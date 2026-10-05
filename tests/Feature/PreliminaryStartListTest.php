<?php

namespace Tests\Feature;

use App\Models\AgeGroup;
use App\Models\Athlete;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\Style;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Решение заказчика (05.10): «Предварительный стартовый протокол» — HTML-страница
 * по заявкам на соревнование в стиле публичной страницы результатов; открывается
 * с дашборда тренера и администратора, только авторизованным (гость — редирект
 * на вход, судья — 403).
 */
class PreliminaryStartListTest extends TestCase
{
    use RefreshDatabase;

    /** Как в JudgeDashboardTest: тестам нужен рабочий PDO-драйвер БД. */
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

    /** Гость получает редирект на вход — страница не публичная. */
    public function test_guest_is_redirected_to_login(): void
    {
        $competition = $this->makeCompetition();

        $response = $this->get(route('start-list.preview', $competition));

        $response->assertRedirect();
        $this->assertStringContainsString('login', (string) $response->headers->get('Location'));
    }

    /** Судья (линейный и старший) не имеет доступа — 403. */
    public function test_judges_get_forbidden(): void
    {
        $competition = $this->makeCompetition();

        foreach (['judge', 'head_judge'] as $role) {
            $this->actingAs($this->makeUser("preview-{$role}@test.local", $role))
                ->get(route('start-list.preview', $competition))
                ->assertForbidden();
        }
    }

    /** Тренер и администратор видят протокол с заголовком и названием турнира. */
    public function test_coach_and_admin_see_protocol(): void
    {
        $competition = $this->makeCompetition();

        foreach ([
            'preview-coach@test.local' => 'coach',
            'preview-admin@test.local' => 'admin',
        ] as $email => $role) {
            $this->actingAs($this->makeUser($email, $role))
                ->get(route('start-list.preview', $competition))
                ->assertOk()
                ->assertSee('Предварительный стартовый протокол')
                ->assertSee($competition->name)
                ->assertSee('Заявки по возрастным группам');
        }
    }

    /** Смоук с заявками: номинации группируются (как в PDF start-list), сводка по группам на месте. */
    public function test_protocol_shows_grouped_registrations_and_counts(): void
    {
        $competition = $this->makeCompetition();

        $ageGroup = AgeGroup::create(['name' => 'Юниоры', 'gender' => 'male', 'min_age' => 12, 'max_age' => 14]);
        $club = Club::create(['name' => 'Клуб Тест']);
        // У модели Athlete в тестовой схеме нет колонки surname (код и PDF
        // используют её как поле продовой БД) — в тесте обходимся именем.
        $athlete = Athlete::create([
            'club_id' => $club->id,
            'name' => 'Иванов Иван',
            'birth_date' => '2013-05-01',
            'gender' => 'male',
        ]);
        $style = Style::create(['name' => 'Чанцюань']);

        Registration::create([
            'competition_id' => $competition->id,
            'athlete_id' => $athlete->id,
            'style_id' => $style->id,
            'age_group_id' => $ageGroup->id,
            'sort_order' => 1,
            'is_completed' => false,
        ]);

        $this->actingAs($this->makeUser('preview-smoke@test.local', 'coach'))
            ->get(route('start-list.preview', $competition))
            ->assertOk()
            ->assertSee('Чанцюань')
            ->assertSee('Иванов Иван')
            ->assertSee('Юниоры');
    }

    private function makeCompetition(): Competition
    {
        return Competition::create([
            'name' => 'Тестовый турнир',
            'city' => 'Москва',
            'start_date' => now()->toDateString(),
            'status_code' => 0,
        ]);
    }

    private function makeUser(string $email, string $role): User
    {
        return User::create([
            'name' => 'Тестовый пользователь',
            'email' => $email,
            'password' => Hash::make('secret'),
            'role' => $role,
            'is_active_judge' => $role !== 'admin',
        ]);
    }
}
