<?php

namespace Tests\Feature;

use App\Filament\Widgets\AccountWidget;
use App\Filament\Widgets\CurrentCompetitionWidget;
use App\Filament\Widgets\StatsOverview;
use App\Models\Athlete;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Federation;
use App\Models\Registration;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Замечание заказчика (02.10): под плашкой «Добро пожаловать» — полноширокая
 * плашка «Актуальное соревнование» (название, даты, адрес, статус сессии
 * регистрации заявок от тренеров). Замечание заказчика (05.10): логотип
 * федерации с плашки убран. Третья плашка статистики администратора — «Всего заявок»
 * по актуальной сессии регистрации, а не по всем соревнованиям.
 *
 * Дашборд тренера повторяет дашборд администратора: та же плашка
 * «Актуальное соревнование», а три плашки статистики — «Всего спортсменов
 * в клубе», «Всего заявок клуба в текущей сессии», «Всего заявок в текущей
 * сессии (общее)».
 *
 * Замечание заказчика (02.10): дашборд судьи (линейного и старшего) — та же
 * плашка «Актуальное соревнование», но со статусом самого соревнования
 * («Скоро» / «Запущено» / «На паузе» / «Завершено»), и три плашки как у
 * администратора: «Всего клубов», «Всего спортсменов», «Всего заявок» —
 * только по актуальной сессии регистрации, а не по всем соревнованиям.
 */
class CurrentCompetitionWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $connection = $_SERVER['DB_CONNECTION'] ?? $_ENV['DB_CONNECTION'] ?? 'sqlite';

        $driver = match ($connection) {
            'pgsql' => 'pgsql',
            'mysql', 'mariadb' => 'mysql',
            'sqlsrv' => 'sqlsrv',
            default => 'sqlite',
        };

        if (! in_array($driver, \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped("PDO-драйвер «{$driver}» не установлен (нужен для тестовой БД).");
        }

        parent::setUp();
    }

    /** Плашка «Актуальное соревнование» занимает все колонки грида дашборда. */
    public function test_current_competition_widget_spans_full_grid_width(): void
    {
        $this->assertSame('full', (new CurrentCompetitionWidget)->getColumnSpan());
    }

    /**
     * Порядок на дашборде: «Добро пожаловать» → «Актуальное соревнование» →
     * статистика.
     */
    public function test_widgets_order_is_welcome_then_competition_then_stats(): void
    {
        $this->assertLessThan(CurrentCompetitionWidget::getSort(), AccountWidget::getSort());
        $this->assertLessThan(StatsOverview::getSort(), CurrentCompetitionWidget::getSort());
    }

    /**
     * Плашка видна всем ролям дашборда: администратору, тренеру и судьям
     * (линейному и старшему) — замечание заказчика (02.10).
     */
    public function test_widget_is_visible_for_admin_coach_and_judges(): void
    {
        $club = Club::create(['name' => 'Клуб '.str()->random(5)]);

        $this->actingAs($this->makeUser('widget-admin@test.local', 'admin'));
        $this->assertTrue(CurrentCompetitionWidget::canView());

        $this->actingAs($this->makeUser('widget-coach@test.local', 'coach', [
            'club_id' => $club->id,
        ]));
        $this->assertTrue(CurrentCompetitionWidget::canView());

        foreach (['judge', 'head_judge'] as $role) {
            $this->actingAs($this->makeUser("widget-{$role}@test.local", $role));
            $this->assertTrue(CurrentCompetitionWidget::canView(), "Роль «{$role}» должна видеть плашку (замечание заказчика 02.10).");
        }
    }

    /** На дашборде администратора плашка «Актуальное соревнование» рендерится. */
    public function test_dashboard_renders_widget_for_admin(): void
    {
        $this->makeCompetition();

        $this->actingAs($this->makeUser('dash-admin@test.local', 'admin'))
            ->get('/admin')
            ->assertOk()
            ->assertSee('fi-current-competition-widget', false);
    }

    /** На дашборде тренера плашка «Актуальное соревнование» рендерится, как у админа. */
    public function test_dashboard_renders_widget_for_coach(): void
    {
        $club = Club::create(['name' => 'Клуб '.str()->random(5)]);

        $this->makeCompetition();

        $this->actingAs($this->makeUser('dash-coach@test.local', 'coach', [
            'club_id' => $club->id,
        ]))
            ->get('/admin')
            ->assertOk()
            ->assertSee('fi-current-competition-widget', false);
    }

    /**
     * На дашборде судьи (линейного и старшего) плашка «Актуальное соревнование»
     * рендерится, как у админа (замечание заказчика 02.10).
     */
    #[DataProvider('judgeRoleProvider')]
    public function test_dashboard_renders_widget_for_judges(string $role): void
    {
        $this->makeCompetition();

        $this->actingAs($this->makeUser("dash-{$role}@test.local", $role))
            ->get('/admin')
            ->assertOk()
            ->assertSee('fi-current-competition-widget', false);
    }

    /** Роли судей: линейный и старший. */
    public static function judgeRoleProvider(): array
    {
        return [
            'линейный судья' => ['judge'],
            'старший судья' => ['head_judge'],
        ];
    }

    /**
     * Замечание заказчика (02.10): судьям на плашке — статус самого
     * соревнования («Скоро» / «Запущено» / «На паузе» / «Завершено»),
     * а не статус сессии регистрации заявок от тренеров.
     */
    #[DataProvider('competitionStatusProvider')]
    public function test_judge_widget_shows_competition_status(
        int $statusCode,
        string $expectedLabel,
    ): void {
        $competition = $this->makeCompetition(['status_code' => $statusCode]);

        $this->assertSame($expectedLabel, $competition->statusLabel());

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($this->makeUser('judge-status@test.local', 'judge'))
            ->test(CurrentCompetitionWidget::class)
            ->assertSee('Статус соревнования')
            ->assertSee($expectedLabel)
            ->assertDontSee('Заявки от тренеров')
            ->assertDontSee('Идёт регистрация');
    }

    /** Статусы соревнования на плашке судьи по status_code. */
    public static function competitionStatusProvider(): array
    {
        return [
            'скоро' => [0, 'Скоро'],
            'запущено' => [1, 'Запущено'],
            'на паузе' => [2, 'На паузе'],
            'завершено' => [3, 'Завершено'],
        ];
    }

    /** Администратор и тренер продолжают видеть статус сессии регистрации заявок. */
    #[DataProvider('sessionStatusRoleProvider')]
    public function test_admin_and_coach_widget_keeps_registration_session_status(string $role): void
    {
        $club = Club::create(['name' => 'Клуб '.str()->random(5)]);

        $this->makeCompetition([
            'status_code' => 1,
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDay(),
        ]);

        $flags = $role === 'coach' ? ['club_id' => $club->id] : [];

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($this->makeUser("session-{$role}@test.local", $role, $flags))
            ->test(CurrentCompetitionWidget::class)
            ->assertSee('Заявки от тренеров')
            ->assertSee('Идёт регистрация')
            ->assertDontSee('Статус соревнования');
    }

    public static function sessionStatusRoleProvider(): array
    {
        return [
            'администратор' => ['admin'],
            'тренер' => ['coach'],
        ];
    }

    /**
     * На плашке: название, календарные даты, адрес проведения и статус сессии
     * регистрации. Логотип федерации не показывается, даже если задан в
     * настройках (замечание заказчика 05.10).
     */
    public function test_widget_shows_competition_details(): void
    {
        $this->makeCompetition([
            'name' => 'Чемпионат России по ушу',
            'start_date' => '2026-11-12',
            'end_date' => '2026-11-15',
            'city' => 'Санкт-Петербург',
            'address' => 'Ледовый дворец',
            'organization_logo' => 'competitions/logos/fed.png',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDay(),
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($this->makeUser('details@test.local', 'admin'))
            ->test(CurrentCompetitionWidget::class)
            ->assertSee('Чемпионат России по ушу')
            ->assertSee('12.11.2026')
            ->assertSee('15.11.2026')
            ->assertSee('Санкт-Петербург')
            ->assertSee('Ледовый дворец')
            ->assertDontSee('competitions/logos/fed.png')
            ->assertSee('Идёт регистрация');
    }

    /**
     * Замечание заказчика (05.10): логотип федерации на плашке не показывается
     * ни при каких настройках — ни свой (organization_logo), ни из федерации.
     */
    public function test_widget_never_shows_federation_logo(): void
    {
        $federation = Federation::create([
            'name' => 'Федерация ушу',
            'logo_path' => 'federations/logo.png',
        ]);

        $this->makeCompetition([
            'organization_logo' => null,
            'federation_id' => $federation->id,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($this->makeUser('logo-fallback@test.local', 'admin'))
            ->test(CurrentCompetitionWidget::class)
            ->assertDontSee('federations/logo.png')
            ->assertDontSee('competitions/logos');
    }

    /** Без соревнований плашка показывает пустое состояние. */
    public function test_widget_shows_empty_state_without_competitions(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($this->makeUser('empty@test.local', 'admin'))
            ->test(CurrentCompetitionWidget::class)
            ->assertSee('Соревнований пока нет');
    }

    /**
     * Статус сессии регистрации считается по окну дат из настроек
     * соревнования: «Не задано», «Ожидает открытия», «Идёт регистрация»,
     * «Регистрация завершена».
     */
    #[DataProvider('sessionStatusProvider')]
    public function test_registration_session_status(
        ?string $opensIn,
        ?string $closesIn,
        string $expectedLabel,
    ): void {
        $competition = $this->makeCompetition([
            'registration_opens_at' => $opensIn ? now()->modify($opensIn) : null,
            'registration_closes_at' => $closesIn ? now()->modify($closesIn) : null,
        ]);

        $this->assertSame($expectedLabel, $competition->registrationSessionStatusLabel());

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($this->makeUser('status@test.local', 'admin'))
            ->test(CurrentCompetitionWidget::class)
            ->assertSee($expectedLabel);
    }

    public static function sessionStatusProvider(): array
    {
        return [
            'окно не задано' => [null, null, 'Не задано'],
            'ожидает открытия' => ['+1 day', '+2 days', 'Ожидает открытия'],
            'идёт регистрация' => ['-1 day', '+1 day', 'Идёт регистрация'],
            'регистрация завершена' => ['-2 days', '-1 day', 'Регистрация завершена'],
        ];
    }

    /** Актуально идущее соревнование важнее ближайшего по дате. */
    public function test_actual_competition_prefers_running_one(): void
    {
        $this->makeCompetition([
            'name' => 'Скоро',
            'start_date' => now()->addDay()->toDateString(),
            'status_code' => 0,
        ]);
        $running = $this->makeCompetition([
            'name' => 'Идёт',
            'start_date' => now()->subDays(2)->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'status_code' => 1,
        ]);

        $this->assertTrue(Competition::actual()->is($running));
    }

    /** Без идущего — ближайшее по дате начала (сегодня или позже). */
    public function test_actual_competition_prefers_nearest_upcoming(): void
    {
        $this->makeCompetition([
            'name' => 'Прошедший',
            'start_date' => now()->subDays(30)->toDateString(),
            'status_code' => 3,
        ]);
        $near = $this->makeCompetition([
            'name' => 'Ближайший',
            'start_date' => now()->addDays(5)->toDateString(),
            'status_code' => 0,
        ]);
        $this->makeCompetition([
            'name' => 'Далёкий',
            'start_date' => now()->addDays(60)->toDateString(),
            'status_code' => 0,
        ]);

        $this->assertTrue(Competition::actual()->is($near));
    }

    /** Если все соревнования прошли — актуально последнее по дате начала. */
    public function test_actual_competition_falls_back_to_latest(): void
    {
        $this->makeCompetition([
            'name' => 'Старый',
            'start_date' => now()->subDays(30)->toDateString(),
            'status_code' => 3,
        ]);
        $latest = $this->makeCompetition([
            'name' => 'Последний',
            'start_date' => now()->subDays(3)->toDateString(),
            'status_code' => 3,
        ]);

        $this->assertTrue(Competition::actual()->is($latest));
    }

    /**
     * «Всего заявок» — только по актуальной сессии регистрации, а не по всем
     * соревнованиям (3 заявки в актуальном, 2 — в другом).
     */
    public function test_registrations_stat_counts_only_actual_session(): void
    {
        $actual = $this->makeCompetition([
            'name' => 'Актуальный турнир',
            'status_code' => 1,
        ]);
        $other = $this->makeCompetition([
            'name' => 'Другой турнир',
            'start_date' => '2026-01-10',
            'status_code' => 0,
        ]);

        $club = Club::create(['name' => 'Клуб '.str()->random(5)]);

        foreach (range(1, 3) as $i) {
            Registration::create([
                'competition_id' => $actual->id,
                'athlete_id' => $this->makeAthlete($club, "Актуальный {$i}")->id,
            ]);
        }

        foreach (range(1, 2) as $i) {
            Registration::create([
                'competition_id' => $other->id,
                'athlete_id' => $this->makeAthlete($club, "Другой {$i}")->id,
            ]);
        }

        $this->actingAs($this->makeUser('stats@test.local', 'admin'));

        $stats = (new class extends StatsOverview
        {
            public function stats(): array
            {
                return $this->getStats();
            }
        })->stats();

        $this->assertSame('Всего заявок', $stats[2]->getLabel());
        $this->assertSame('Актуальная сессия регистрации', $stats[2]->getDescription());
        $this->assertSame(3, $stats[2]->getValue(), 'Считаются только заявки актуального соревнования.');
    }

    /**
     * Три плашки тренера: спортсмены клуба, заявки клуба в текущей сессии
     * (2 из 3 — только клуб тренера) и все заявки текущей сессии (по всем
     * клубам); заявки клуба в другом соревновании не считаются.
     */
    public function test_coach_stats_show_club_and_session_counts(): void
    {
        $actual = $this->makeCompetition([
            'name' => 'Актуальный турнир',
            'status_code' => 1,
        ]);
        $other = $this->makeCompetition([
            'name' => 'Другой турнир',
            'start_date' => '2026-01-10',
            'status_code' => 0,
        ]);

        $club = Club::create(['name' => 'Клуб '.str()->random(5)]);
        $foreignClub = Club::create(['name' => 'Чужой клуб '.str()->random(5)]);

        $mine1 = $this->makeAthlete($club, 'Свой первый');
        $mine2 = $this->makeAthlete($club, 'Свой второй');
        $this->makeAthlete($club, 'Свой третий');

        // В актуальной сессии: две заявки клуба и две — чужого клуба.
        Registration::create(['competition_id' => $actual->id, 'athlete_id' => $mine1->id]);
        Registration::create(['competition_id' => $actual->id, 'athlete_id' => $mine2->id]);
        Registration::create(['competition_id' => $actual->id, 'athlete_id' => $this->makeAthlete($foreignClub, 'Чужой первый')->id]);
        Registration::create(['competition_id' => $actual->id, 'athlete_id' => $this->makeAthlete($foreignClub, 'Чужой второй')->id]);

        // Заявка клуба в другом соревновании — вне текущей сессии.
        Registration::create(['competition_id' => $other->id, 'athlete_id' => $this->makeAthlete($club, 'Свой четвёртый')->id]);

        $this->actingAs($this->makeUser('coach-stats@test.local', 'coach', [
            'club_id' => $club->id,
        ]));

        $stats = (new class extends StatsOverview
        {
            public function stats(): array
            {
                return $this->getStats();
            }
        })->stats();

        $this->assertCount(3, $stats);

        $this->assertSame('Всего спортсменов в клубе', $stats[0]->getLabel());
        $this->assertSame(4, $stats[0]->getValue(), 'Считаются только спортсмены клуба тренера.');

        $this->assertSame('Всего заявок клуба в текущей сессии', $stats[1]->getLabel());
        $this->assertSame('Актуальная сессия регистрации', $stats[1]->getDescription());
        $this->assertSame(2, $stats[1]->getValue(), 'Только заявки клуба тренера в актуальной сессии.');

        $this->assertSame('Всего заявок в текущей сессии (общее)', $stats[2]->getLabel());
        $this->assertSame(4, $stats[2]->getValue(), 'Все клубы в актуальной сессии.');
    }

    /**
     * Замечание заказчика (02.10): три плашки судьи — как у администратора:
     * «Всего клубов», «Всего спортсменов» и «Всего заявок» — только по
     * актуальной сессии регистрации (2 из 4), а не по всем соревнованиям.
     */
    #[DataProvider('judgeRoleProvider')]
    public function test_judge_stats_show_admin_tiles_with_actual_session_count(string $role): void
    {
        $actual = $this->makeCompetition([
            'name' => 'Актуальный турнир',
            'status_code' => 1,
        ]);
        $other = $this->makeCompetition([
            'name' => 'Другой турнир',
            'start_date' => '2026-01-10',
            'status_code' => 0,
        ]);

        $clubA = Club::create(['name' => 'Клуб '.str()->random(5)]);
        $clubB = Club::create(['name' => 'Клуб '.str()->random(5)]);

        Registration::create(['competition_id' => $actual->id, 'athlete_id' => $this->makeAthlete($clubA, 'Первый')->id]);
        Registration::create(['competition_id' => $actual->id, 'athlete_id' => $this->makeAthlete($clubB, 'Второй')->id]);
        Registration::create(['competition_id' => $other->id, 'athlete_id' => $this->makeAthlete($clubA, 'Третий')->id]);
        Registration::create(['competition_id' => $other->id, 'athlete_id' => $this->makeAthlete($clubB, 'Четвёртый')->id]);

        $this->actingAs($this->makeUser("judge-stats-{$role}@test.local", $role));

        $stats = (new class extends StatsOverview
        {
            public function stats(): array
            {
                return $this->getStats();
            }
        })->stats();

        $this->assertCount(3, $stats);

        $this->assertSame('Всего клубов', $stats[0]->getLabel());
        $this->assertSame(2, $stats[0]->getValue());

        $this->assertSame('Всего спортсменов', $stats[1]->getLabel());
        $this->assertSame(4, $stats[1]->getValue());

        $this->assertSame('Всего заявок', $stats[2]->getLabel());
        $this->assertSame('Актуальная сессия регистрации', $stats[2]->getDescription());
        $this->assertSame(2, $stats[2]->getValue(), 'Считаются только заявки актуальной сессии, а не все соревнования.');
    }

    private function makeCompetition(array $attributes = []): Competition
    {
        return Competition::create(array_merge([
            'name' => 'Кубок Москвы 2026',
            'start_date' => '2026-11-12',
            'end_date' => '2026-11-15',
            'city' => 'Москва',
            'address' => 'Дворец спорта «Лужники»',
            'status_code' => 0,
        ], $attributes));
    }

    private function makeAthlete(Club $club, string $name): Athlete
    {
        return Athlete::create([
            'club_id' => $club->id,
            'name' => $name,
            'gender' => 'male',
            'birth_date' => '2013-05-01',
            'is_special' => false,
        ]);
    }

    private function makeUser(string $email, string $role, array $flags = []): User
    {
        return User::create(array_merge([
            'name' => 'Тестовый пользователь',
            'email' => $email,
            'password' => Hash::make('secret'),
            'role' => $role,
            'is_active_judge' => $role !== 'admin',
        ], $flags));
    }
}
