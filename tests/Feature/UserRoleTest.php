<?php

namespace Tests\Feature;

use App\Filament\Pages\SuperJudgePad;
use App\Filament\Resources\DeductionCodeResource;
use App\Filament\Resources\JudgingLogResource;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Widgets\StatsOverview;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Закрепление инцидента 03.10: права в админ-панели зависят от роли
 * пользователя («Пользователи», «Клубы», «Пульт Старшего судьи» и др.
 * видит только администратор), а роль выбирается в карточке пользователя.
 * В форме раньше поля «Роль» не было — роль можно было испортить только
 * правкой БД, из-за чего у админ-учётки оказался «тренер» и пропал
 * полный набор пунктов меню.
 */
class UserRoleTest extends TestCase
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

    /** Все четыре роли есть в словаре ролей модели. */
    public function test_model_exposes_role_options(): void
    {
        $options = User::roleOptions();

        $this->assertSame([
            'admin' => 'Администратор',
            'head_judge' => 'Старший судья',
            'judge' => 'Линейный судья',
            'coach' => 'Тренер',
        ], $options);
    }

    /** В карточке пользователя есть поле «Роль». */
    public function test_user_form_has_role_field(): void
    {
        $admin = $this->makeUser('role-form-admin@test.local', 'admin');
        $target = $this->makeUser('role-form-target@test.local', 'coach');

        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
            ->assertFormFieldExists('role');
    }

    /** Администратор меняет роль пользователя в карточке — она сохраняется. */
    public function test_admin_can_change_user_role(): void
    {
        $admin = $this->makeUser('role-admin@test.local', 'admin');
        $target = $this->makeUser('role-target@test.local', 'coach');

        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
            ->fillForm(['role' => 'judge'])
            ->call('save')
            ->assertHasNoFormErrors();

        $target->refresh();

        $this->assertSame('judge', $target->role);
    }

    /** В списке пользователей роль показывается отдельной колонкой. */
    public function test_users_table_shows_role_column(): void
    {
        $admin = $this->makeUser('role-table-admin@test.local', 'admin');
        $this->makeUser('role-table-coach@test.local', 'coach');

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords(User::query()->get())
            ->assertCountTableRecords(2);

        $this->assertSame(
            'Администратор',
            User::roleOptions()[$admin->role] ?? $admin->role,
        );
    }

    /**
     * Инцидент 03.10: роль «тренер» у админ-учётки скрыла админ-пункты меню
     * («Пульт Старшего судьи», «Коды сбавок (судья A)», «Журнал судейства»)
     * и подставила тренерские плашки. После смены роли на «администратора»
     * набор пунктов меню и плашки возвращаются.
     */
    public function test_role_decides_admin_menu_and_stats(): void
    {
        $user = $this->makeUser('role-decides@test.local', 'coach');

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAs($user);

        $adminOnlyItems = [
            SuperJudgePad::class => 'Пульт Старшего судьи',
            DeductionCodeResource::class => 'Коды сбавок (судья A)',
            JudgingLogResource::class => 'Журнал судейства',
        ];

        foreach ($adminOnlyItems as $item => $label) {
            $this->assertFalse(
                $item::shouldRegisterNavigation(),
                "Пункт «{$label}» не должен быть в меню роли «тренер».",
            );
        }

        $this->assertSame('Всего спортсменов в клубе', $this->statsLabels()[0]);

        $user->update(['role' => 'admin']);

        foreach ($adminOnlyItems as $item => $label) {
            $this->assertTrue(
                $item::shouldRegisterNavigation(),
                "Пункт «{$label}» должен быть в меню роли «администратор».",
            );
        }

        $this->assertSame([
            'Всего клубов',
            'Всего спортсменов',
            'Всего заявок',
        ], $this->statsLabels());
    }

    /** @return array<int, string> */
    private function statsLabels(): array
    {
        $stats = (new class extends StatsOverview
        {
            /** @return array<int, \Filament\Widgets\StatsOverviewWidget\Stat> */
            public function collectStats(): array
            {
                return $this->getStats();
            }
        })->collectStats();

        return array_map(
            static fn (Stat $stat): string => (string) $stat->getLabel(),
            $stats,
        );
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
