<?php

namespace Tests\Feature;

use App\Filament\Pages\Analytics;
use App\Filament\Pages\ScoresSummary;
use App\Filament\Resources\JudgeResource;
use App\Filament\Resources\JudgingLogResource;
use App\Http\Responses\LoginResponse;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Auth\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Замечание заказчика (01.10): старший судья и линейные судьи после авторизации
 * попадают на дашборд (а не в пульты) и видят свой набор пунктов меню:
 * «Инфопанель», «Судейский пульт», «Аналитика» (по умолчанию), плюс опциональные
 * «Сводка оценок» и «Журнал судейства» (включаются админом в настройках судей).
 */
class JudgeDashboardTest extends TestCase
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

    /** После входа судья (линейный и старший) попадает на дашборд, а не на пульт. */
    public function test_login_redirects_judges_to_dashboard(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        foreach ([
            'login-judge@test.local' => 'judge',
            'login-head@test.local' => 'head_judge',
            'login-admin@test.local' => 'admin',
        ] as $email => $role) {
            $user = $this->makeUser($email, $role);

            // Выходим между итерациями: страница логина редиректит уже
            // авторизованных пользователей (Login::mount).
            auth()->logout();
            session()->flush();

            Livewire::test(Login::class)
                ->fillForm(['email' => $user->email, 'password' => 'secret'])
                ->call('authenticate')
                ->assertRedirect('/admin');
        }
    }

    /** Контракт LoginResponse тоже ведёт на дашборд для всех ролей. */
    public function test_login_response_always_targets_dashboard(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        foreach (['judge', 'head_judge', 'admin'] as $role) {
            $user = $this->makeUser("resp-{$role}@test.local", $role);

            $this->actingAs($user);

            $response = (new LoginResponse)->toResponse(request());

            $this->assertSame(
                url('/admin'),
                $response->getTargetUrl(),
                "Роль «{$role}» должна попадать на дашборд после входа.",
            );
        }
    }

    /** Меню линейного судьи: «Инфопанель», «Судейский пульт», «Аналитика»; остальное скрыто. */
    public function test_line_judge_sidebar_contains_own_menu_set(): void
    {
        $judge = $this->makeUser('menu-judge@test.local', 'judge');

        $this->actingAs($judge)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Инфопанель')
            ->assertSee('Судейский пульт')
            ->assertSee('Аналитика')
            ->assertDontSee('Сводка оценок')
            ->assertDontSee('Журнал судейства')
            ->assertDontSee('Заявки')
            ->assertDontSee('Судейская коллегия')
            ->assertDontSee('Пользователи')
            ->assertDontSee('Коды сбавок')
            ->assertDontSee('Возрастные группы')
            ->assertDontSee('Виды программы');
    }

    /** Меню старшего судьи — тот же свой набор пунктов. */
    public function test_head_judge_sidebar_contains_own_menu_set(): void
    {
        $head = $this->makeUser('menu-head@test.local', 'head_judge');

        $this->actingAs($head)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Инфопанель')
            ->assertSee('Судейский пульт')
            ->assertSee('Аналитика')
            ->assertDontSee('Сводка оценок')
            ->assertDontSee('Журнал судейства')
            ->assertDontSee('Заявки')
            ->assertDontSee('Судейская коллегия')
            ->assertDontSee('Пользователи')
            ->assertDontSee('Коды сбавок')
            ->assertDontSee('Возрастные группы')
            ->assertDontSee('Виды программы');
    }

    /** «Судейский пульт» линейного судьи ведёт на judge-pad. */
    public function test_line_judge_pad_menu_item(): void
    {
        $judge = $this->makeUser('pad-judge@test.local', 'judge');

        $this->actingAs($judge)
            ->get('/admin')
            ->assertSee('/admin/judge-pad')
            ->assertDontSee('/admin/super-judge-pad');
    }

    /** «Судейский пульт» старшего судьи ведёт на super-judge-pad. */
    public function test_head_judge_pad_menu_item(): void
    {
        $head = $this->makeUser('pad-head@test.local', 'head_judge');

        $this->actingAs($head)
            ->get('/admin')
            ->assertSee('/admin/super-judge-pad')
            ->assertDontSee('/admin/judge-pad');
    }

    /** «Сводка оценок» и «Журнал судейства» выключены по умолчанию: нет в меню, 403 по URL. */
    public function test_optional_sections_are_off_by_default(): void
    {
        $judge = $this->makeUser('off@test.local', 'judge');

        $this->assertFalse($judge->show_scores_summary);
        $this->assertFalse($judge->show_judging_log);

        $this->actingAs($judge)->get('/admin/scores-summary')->assertForbidden();
        $this->actingAs($judge)->get('/admin/judging-logs')->assertForbidden();
    }

    /** Включённые админом разделы появляются в меню и открываются. */
    public function test_enabled_sections_appear_in_menu_and_open(): void
    {
        $judge = $this->makeUser('on@test.local', 'judge', [
            'show_scores_summary' => true,
            'show_judging_log' => true,
        ]);

        $this->actingAs($judge)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Сводка оценок')
            ->assertSee('Журнал судейства');

        $this->actingAs($judge)->get('/admin/scores-summary')->assertOk();
        $this->actingAs($judge)->get('/admin/judging-logs')->assertOk();
    }

    /** «Аналитика» включена по умолчанию и видна судье. */
    public function test_analytics_is_on_by_default(): void
    {
        $judge = $this->makeUser('an-on@test.local', 'judge');
        $this->assertTrue($judge->show_analytics);

        $this->actingAs($judge)
            ->get('/admin')
            ->assertSee('Аналитика');
        $this->actingAs($judge)->get('/admin/analytics')->assertOk();
    }

    /** Выключенная админом «Аналитика» исчезает из меню и отдаёт 403. */
    public function test_disabled_analytics_is_hidden_and_forbidden(): void
    {
        $off = $this->makeUser('an-off@test.local', 'judge', ['show_analytics' => false]);

        $this->actingAs($off)
            ->get('/admin')
            ->assertDontSee('Аналитика');
        $this->actingAs($off)->get('/admin/analytics')->assertForbidden();
    }

    /** Админ включает/выключает разделы в настройках судей («Судейская коллегия»). */
    public function test_admin_toggles_sections_in_judge_settings(): void
    {
        $admin = $this->makeUser('settings-admin@test.local', 'admin');
        $judge = $this->makeUser('settings-judge@test.local', 'judge');

        Livewire::actingAs($admin)
            ->test(JudgeResource\Pages\EditJudge::class, ['record' => $judge->id])
            ->fillForm([
                'show_scores_summary' => true,
                'show_judging_log' => true,
                'show_analytics' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $judge->refresh();

        $this->assertTrue($judge->show_scores_summary);
        $this->assertTrue($judge->show_judging_log);
        $this->assertFalse($judge->show_analytics);
    }

    /** Меню админа не меняется: все разделы на месте. */
    public function test_admin_menu_is_unchanged(): void
    {
        $admin = $this->makeUser('menu-admin@test.local', 'admin');

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Инфопанель')
            ->assertSee('Заявки')
            ->assertSee('Судейская коллегия')
            ->assertSee('Пользователи')
            ->assertSee('Сводка оценок')
            ->assertSee('Журнал судейства')
            ->assertSee('Коды сбавок')
            ->assertSee('Аналитика');
    }

    /** Статические правила доступа согласованы с флагами настроек. */
    public function test_access_rules_follow_flags(): void
    {
        $judge = $this->makeUser('rules@test.local', 'judge');

        $this->actingAs($judge);
        $this->assertFalse(ScoresSummary::canAccess());
        $this->assertFalse(JudgingLogResource::canViewAny());
        $this->assertTrue(Analytics::canAccess());

        $judge->update(['show_scores_summary' => true, 'show_judging_log' => true, 'show_analytics' => false]);

        $this->assertTrue(ScoresSummary::canAccess());
        $this->assertTrue(JudgingLogResource::canViewAny());
        $this->assertFalse(Analytics::canAccess());

        // Админ видит разделы всегда.
        $this->actingAs($this->makeUser('rules-admin@test.local', 'admin'));
        $this->assertTrue(ScoresSummary::canAccess());
        $this->assertTrue(JudgingLogResource::canViewAny());
        $this->assertTrue(Analytics::canAccess());
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
