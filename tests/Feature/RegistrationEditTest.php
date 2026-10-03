<?php

namespace Tests\Feature;

use App\Filament\Resources\RegistrationResource\Pages\EditRegistration;
use App\Models\AgeGroup;
use App\Models\Athlete;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\Style;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Замечание заказчика (01.10.2026): кнопка «Изменить» в «Заявках» (админ и
 * тренер) открывала пустую форму и падала при сохранении.
 *
 * Редактирование — «одна заявка = одна строка»: меняются дисциплина, партнёр,
 * группа «O»; категория пересчитывается единообразно (AgeGroupResolver).
 */
class RegistrationEditTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Как в JudgingRulesTest: тесты требуют рабочего драйвера БД.
     * Если нужного PDO-драйвера нет — пропускаем, а не падаем.
     */
    protected function setUp(): void
    {
        // Проверяем до parent::setUp(), поэтому config() ещё недоступен.
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

    /** Регресс «пустой формы»: поля редактирования заполняются данными заявки. */
    public function test_edit_form_is_filled_from_record(): void
    {
        $ctx = $this->makeWorld();
        $registration = $ctx['registration'];

        $this->actingAs($this->makeUser('edit-fill-admin@test.local', 'admin'));

        Livewire::test(EditRegistration::class, ['record' => $registration->getRouteKey()])
            ->assertFormSet([
                'style_id' => $registration->style_id,
                'partner_id' => $registration->partner_id,
                'is_special' => (bool) $registration->is_special,
            ]);
    }

    /** Смена дисциплины сохраняется; категория пересчитывается в едином формате. */
    public function test_style_change_is_saved_and_age_group_recalculated(): void
    {
        $ctx = $this->makeWorld();
        $registration = $ctx['registration'];

        $this->actingAs($this->makeUser('edit-style-admin@test.local', 'admin'));

        Livewire::test(EditRegistration::class, ['record' => $registration->getRouteKey()])
            ->fillForm(['style_id' => $ctx['duilian']->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $registration->refresh();

        $this->assertSame($ctx['duilian']->id, $registration->style_id);
        // Категория приведена к формату справочника (была устаревшая «Юноши (...)»).
        $this->assertSame($ctx['group']->id, $registration->age_group_id);
        $this->assertSame('Юниоры (12-14 лет)', $registration->age_group_label);
    }

    /** Смена партнёра у парного вида сохраняется; R-6.15: пара «O / не O» невозможна. */
    public function test_partner_change_and_special_pair_rule(): void
    {
        $ctx = $this->makeWorld();
        $registration = $ctx['registration'];
        $registration->update(['style_id' => $ctx['duilian']->id]);

        $this->actingAs($this->makeUser('edit-partner-admin@test.local', 'admin'));

        Livewire::test(EditRegistration::class, ['record' => $registration->getRouteKey()])
            ->fillForm(['partner_id' => $ctx['partner']->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $registration->refresh();
        $this->assertSame($ctx['partner']->id, $registration->partner_id);

        // Партнёр с карточкой «O», у основного её нет — сохранение блокируется.
        Livewire::test(EditRegistration::class, ['record' => $registration->getRouteKey()])
            ->fillForm(['partner_id' => $ctx['specialPartner']->id])
            ->call('save')
            ->assertHasFormErrors(['partner_id']);

        $registration->refresh();
        $this->assertSame($ctx['partner']->id, $registration->partner_id);
    }

    /** У одиночного вида партнёр принудительно очищается (R-6.15). */
    public function test_partner_is_cleared_for_single_style(): void
    {
        $ctx = $this->makeWorld();
        $registration = $ctx['registration'];
        $registration->update(['style_id' => $ctx['duilian']->id, 'partner_id' => $ctx['partner']->id]);

        $this->actingAs($this->makeUser('edit-clear-admin@test.local', 'admin'));

        Livewire::test(EditRegistration::class, ['record' => $registration->getRouteKey()])
            ->fillForm(['style_id' => $ctx['chanquan']->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $registration->refresh();

        $this->assertSame($ctx['chanquan']->id, $registration->style_id);
        $this->assertNull($registration->partner_id);
    }

    /** Дубль дисциплины — ошибка валидации, а не 500 от БД-констрейнта. */
    public function test_duplicate_style_gives_validation_error(): void
    {
        $ctx = $this->makeWorld();
        $registration = $ctx['registration'];

        // Та же дисциплина уже заявлена этим же спортсменом на этом турнире.
        Registration::create([
            'competition_id' => $ctx['competition']->id,
            'athlete_id' => $ctx['athlete']->id,
            'style_id' => $ctx['duilian']->id,
            'age_group_id' => $ctx['group']->id,
            'age_group_label' => 'Юниоры (12-14 лет)',
        ]);

        $this->actingAs($this->makeUser('edit-dup-admin@test.local', 'admin'));

        Livewire::test(EditRegistration::class, ['record' => $registration->getRouteKey()])
            ->fillForm(['style_id' => $ctx['duilian']->id])
            ->call('save')
            ->assertHasFormErrors(['style_id']);

        $registration->refresh();
        $this->assertSame($ctx['chanquan']->id, $registration->style_id);
    }

    /** Тренер редактирует заявки своего клуба (фильтр getEloquentQuery не мешает). */
    public function test_trainer_can_edit_own_club_registration(): void
    {
        $ctx = $this->makeWorld();
        $registration = $ctx['registration'];

        $this->actingAs($this->makeUser('edit-trainer@test.local', 'trainer', $ctx['club']->id));

        Livewire::test(EditRegistration::class, ['record' => $registration->getRouteKey()])
            ->fillForm(['style_id' => $ctx['duida']->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $registration->refresh();
        $this->assertSame($ctx['duida']->id, $registration->style_id);
    }

    /**
     * Мир теста: турнир, спортсмен 13 лет (категория «Юниоры (12-14 лет)»),
     * одиночный вид (Чанцюань) и парные (Дуйлянь, Дуйда), заявка с устаревшей
     * меткой категории из старого редактирования.
     *
     * @return array<string, mixed>
     */
    private function makeWorld(): array
    {
        $competition = Competition::create([
            'name' => 'Турнир редактирования заявок',
            'start_date' => '2026-02-01',
            'city' => 'Москва',
            'status_code' => 1,
        ]);

        $club = Club::create(['name' => 'Клуб '.str()->random(5)]);

        // 2026 − 2013 = 13 лет → группа «Юниоры (12-14 лет)».
        $athlete = Athlete::create([
            'club_id' => $club->id,
            'name' => 'Иванов Иван',
            'birth_date' => '2013-05-01',
            'gender' => 'male',
            'is_special' => false,
        ]);

        $partner = Athlete::create([
            'club_id' => $club->id,
            'name' => 'Петров Пётр',
            'birth_date' => '2013-07-15',
            'gender' => 'male',
            'is_special' => false,
        ]);

        // Карточка «O» — для проверки правила R-6.15 (пара «O / не O» невозможна).
        $specialPartner = Athlete::create([
            'club_id' => $club->id,
            'name' => 'Сидоров Сидор',
            'birth_date' => '2013-03-10',
            'gender' => 'male',
            'is_special' => true,
        ]);

        $group = AgeGroup::create(['name' => 'Юниоры', 'gender' => 'male', 'min_age' => 12, 'max_age' => 14]);

        $chanquan = Style::create(['name' => 'Чанцюань', 'sort_order' => 1]);
        $duilian = Style::create(['name' => 'Двойные кулаки дуйлянь', 'sort_order' => 2]);
        $duida = Style::create(['name' => 'Гуйдин дуйда', 'sort_order' => 3]);

        // Устаревшая метка из старого edit — при сохранении должна пересчитаться.
        $registration = Registration::create([
            'competition_id' => $competition->id,
            'athlete_id' => $athlete->id,
            'style_id' => $chanquan->id,
            'age_group_id' => null,
            'age_group_label' => 'Юноши (12-14 лет)',
        ]);

        return compact('competition', 'club', 'athlete', 'partner', 'specialPartner', 'group', 'chanquan', 'duilian', 'duida', 'registration');
    }

    private function makeUser(string $email, string $role, ?int $clubId = null): User
    {
        return User::create([
            'name' => 'Пользователь',
            'email' => $email,
            'password' => Hash::make('secret'),
            'role' => $role,
            'is_active_judge' => $role !== 'admin',
            'club_id' => $clubId,
        ]);
    }
}
