<?php

namespace Tests\Feature;

use App\Filament\Resources\RegistrationResource\Pages\CreateRegistration;
use App\Filament\Resources\RegistrationResource\Pages\EditRegistration;
use App\Filament\Resources\RegistrationResource\Pages\ListRegistrations;
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
 * Замечание заказчика (13.10.2026): по завершении сессии подачи заявок тренер
 * не может создавать и редактировать заявки — при попытке этих действий
 * показывается модальное предупреждение: «Вы пытаетесь создать или
 * отредактировать заявку, но сессия подачи заявок на (...) завершена
 * (дд.мм.гггг)» и «Администратор уже знает. Администратор не удивлён...)».
 *
 * Администратор создаёт и редактирует заявки от любых клубов всегда.
 * До открытия окна («Ожидает открытия») и без заданных дат запрета нет;
 * удаление заявок не ограничивается.
 */
class RegistrationSessionDeadlineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Как в JudgingRulesTest и RegistrationEditTest: тесты требуют рабочего
     * драйвера БД. Если нужного PDO-драйвера нет — пропускаем, а не падаем.
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

    /** Тренер не может создать заявку после завершения сессии (серверная защита). */
    public function test_trainer_cannot_create_registration_after_session_closed(): void
    {
        $ctx = $this->makeWorld('closed');

        $this->actingAs($this->makeUser('deadline-create-trainer@test.local', 'trainer', $ctx['club']->id));

        Livewire::test(CreateRegistration::class)
            ->fillForm($this->createState($ctx))
            ->call('create')
            ->assertNotified('Приём заявок завершён');

        $this->assertDatabaseCount('registrations', 1);
        $this->assertDatabaseMissing('registrations', ['style_id' => $ctx['taichi']->id]);
    }

    /** Тренер не может отредактировать заявку после завершения сессии. */
    public function test_trainer_cannot_edit_registration_after_session_closed(): void
    {
        $ctx = $this->makeWorld('closed');

        $this->actingAs($this->makeUser('deadline-edit-trainer@test.local', 'trainer', $ctx['club']->id));

        Livewire::test(EditRegistration::class, ['record' => $ctx['registration']->getRouteKey()])
            ->fillForm(['style_id' => $ctx['duilian']->id])
            ->call('save')
            ->assertNotified('Приём заявок завершён');

        $this->assertSame($ctx['chanquan']->id, $ctx['registration']->refresh()->style_id);
    }

    /** Администратор создаёт и редактирует заявки от любых клубов всегда. */
    public function test_admin_can_create_and_edit_after_session_closed(): void
    {
        $ctx = $this->makeWorld('closed');

        $this->actingAs($this->makeUser('deadline-admin@test.local', 'admin'));

        Livewire::test(CreateRegistration::class)
            ->fillForm($this->createState($ctx))
            ->call('create')
            ->assertNotified('Обработка завершена');

        $this->assertDatabaseCount('registrations', 2);

        Livewire::test(EditRegistration::class, ['record' => $ctx['registration']->getRouteKey()])
            ->fillForm(['style_id' => $ctx['duilian']->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($ctx['duilian']->id, $ctx['registration']->refresh()->style_id);
    }

    /** Внутри окна («Идёт регистрация») тренер работает как обычно. */
    public function test_trainer_can_create_and_edit_while_session_open(): void
    {
        $ctx = $this->makeWorld('open');

        $this->actingAs($this->makeUser('deadline-open-trainer@test.local', 'trainer', $ctx['club']->id));

        Livewire::test(CreateRegistration::class)
            ->fillForm($this->createState($ctx))
            ->call('create')
            ->assertNotified('Обработка завершена');

        $this->assertDatabaseCount('registrations', 2);

        Livewire::test(EditRegistration::class, ['record' => $ctx['registration']->getRouteKey()])
            ->fillForm(['style_id' => $ctx['duilian']->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($ctx['duilian']->id, $ctx['registration']->refresh()->style_id);
    }

    /**
     * Заказчик запретил действия только «по завершению» сессии: до открытия
     * окна («Ожидает открытия») заявку создать можно.
     */
    public function test_trainer_can_create_before_session_opens(): void
    {
        $ctx = $this->makeWorld('pending');

        $this->actingAs($this->makeUser('deadline-pending-trainer@test.local', 'trainer', $ctx['club']->id));

        Livewire::test(CreateRegistration::class)
            ->fillForm($this->createState($ctx))
            ->call('create')
            ->assertNotified('Обработка завершена');

        $this->assertDatabaseCount('registrations', 2);
    }
    /** Тренер: вместо «Создать» и «Изменить» — модальное предупреждение. */
    public function test_trainer_sees_warning_modal_instead_of_create_and_edit(): void
    {
        $ctx = $this->makeWorld('closed');

        $this->actingAs($this->makeUser('deadline-modal-trainer@test.local', 'trainer', $ctx['club']->id));

        Livewire::test(ListRegistrations::class)
            ->assertActionVisible('createSessionClosed')
            ->assertActionHidden('create')
            ->assertTableActionVisible('editSessionClosed', $ctx['registration']->getRouteKey())
            ->assertTableActionHidden('edit', $ctx['registration']->getRouteKey())
            ->mountAction('createSessionClosed')
            ->assertSee('Вы пытаетесь создать или отредактировать заявку')
            ->assertSee('завершена ('.now()->subDay()->format('d.m.Y').')')
            ->assertSee('Администратор уже знает')
            ->assertSee('Администратор не удивлён...');
    }

    /** Модалка «Изменить» — тот же текст предупреждения и дата закрытия. */
    public function test_edit_warning_modal_contains_customer_message(): void
    {
        $ctx = $this->makeWorld('closed');

        $this->actingAs($this->makeUser('deadline-modal-edit@test.local', 'trainer', $ctx['club']->id));

        Livewire::test(ListRegistrations::class)
            ->mountTableAction('editSessionClosed', $ctx['registration']->getRouteKey())
            ->assertSee('Вы пытаетесь создать или отредактировать заявку')
            ->assertSee('но сессия подачи заявок на «'.$ctx['competition']->name.'» завершена')
            ->assertSee('Администратор не удивлён...');
    }

    /** Администратор видит обычные «Создать» и «Изменить» и после закрытия. */
    public function test_admin_sees_create_and_edit_after_session_closed(): void
    {
        $ctx = $this->makeWorld('closed');

        $this->actingAs($this->makeUser('deadline-modal-admin@test.local', 'admin'));

        Livewire::test(ListRegistrations::class)
            ->assertActionVisible('create')
            ->assertActionHidden('createSessionClosed')
            ->assertTableActionVisible('edit', $ctx['registration']->getRouteKey())
            ->assertTableActionHidden('editSessionClosed', $ctx['registration']->getRouteKey());
    }

    /** Данные формы создания: одиночная дисциплина (без парных полей). */
    private function createState(array $ctx): array
    {
        return [
            'competition_id' => $ctx['competition']->id,
            'athlete_id' => $ctx['athlete']->id,
            'events_taolu_virtual' => [$ctx['taichi']->id],
        ];
    }

    /**
     * Мир теста: турнир с заданным окном сессии регистрации, спортсмен клуба
     * 13 лет, одиночные (Чанцюань, Тайцзицюань) и парные (Дуйлянь, Дуйда)
     * дисциплины, готовая заявка (Чанцюань).
     *
     * @return array<string, mixed>
     */
    private function makeWorld(string $window): array
    {
        $dates = match ($window) {
            'closed' => [
                'registration_opens_at' => now()->subDays(5),
                'registration_closes_at' => now()->subDay(),
            ],
            'open' => [
                'registration_opens_at' => now()->subDay(),
                'registration_closes_at' => now()->addDay(),
            ],
            'pending' => [
                'registration_opens_at' => now()->addDay(),
                'registration_closes_at' => now()->addDays(2),
            ],
            // Без заданных дат («Не задано») запрета нет.
            default => [
                'registration_opens_at' => null,
                'registration_closes_at' => null,
            ],
        };

        // status_code = 1: Competition::actual() вернёт этот турнир — от него
        // считается сессия кнопки «Создать» в списке заявок.
        $competition = Competition::create([
            'name' => 'Турнир сессии регистрации',
            'start_date' => '2026-02-01',
            'city' => 'Москва',
            'status_code' => 1,
            ...$dates,
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

        $group = AgeGroup::create(['name' => 'Юниоры', 'gender' => 'male', 'min_age' => 12, 'max_age' => 14]);

        $chanquan = Style::create(['name' => 'Чанцюань', 'sort_order' => 1]);
        $taichi = Style::create(['name' => 'Тайцзицюань', 'sort_order' => 2]);
        $duilian = Style::create(['name' => 'Двойные кулаки дуйлянь', 'sort_order' => 3]);
        $duida = Style::create(['name' => 'Гуйдин дуйда', 'sort_order' => 4]);

        $registration = Registration::create([
            'competition_id' => $competition->id,
            'athlete_id' => $athlete->id,
            'style_id' => $chanquan->id,
            'age_group_id' => $group->id,
            'age_group_label' => 'Юниоры (12-14 лет)',
        ]);

        return compact('competition', 'club', 'athlete', 'group', 'chanquan', 'taichi', 'duilian', 'duida', 'registration');
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
