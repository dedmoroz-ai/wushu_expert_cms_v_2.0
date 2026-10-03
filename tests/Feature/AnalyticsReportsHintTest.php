<?php

namespace Tests\Feature;

use App\Filament\Pages\Analytics;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Замечание заказчика (02.10): памятка «Поместите HTML-файлы в папку
 * storage/app/public/reports/» в пустом состоянии «Аналитика — отчёты»
 * остаётся только у админа; остальным аккаунтам она не показывается.
 */
class AnalyticsReportsHintTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Как в JudgingRulesTest: тесты требуют рабочего драйвера БД;
     * если нужного PDO-драйвера нет — пропускаем, а не падаем.
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

    /** Админу памятка о папке отчётов показывается. */
    public function test_admin_sees_reports_folder_hint(): void
    {
        $html = $this->renderEmptyAnalytics('admin');

        $this->assertStringContainsString('Отчётов пока нет', $html);
        $this->assertStringContainsString('Поместите HTML-файлы в папку', $html);
        $this->assertStringContainsString('storage/app/public/reports/', $html);
    }

    /** Всем аккаунтам, кроме админа, подсказка о папке отчётов не показывается. */
    #[DataProvider('nonAdminRoleProvider')]
    public function test_non_admin_roles_do_not_see_reports_folder_hint(string $role): void
    {
        $html = $this->renderEmptyAnalytics($role);

        $this->assertStringContainsString('Отчётов пока нет', $html);
        $this->assertStringNotContainsString('Поместите HTML-файлы в папку', $html);
        $this->assertStringNotContainsString('storage/app/public/reports/', $html);
    }

    public static function nonAdminRoleProvider(): array
    {
        return [
            'тренер' => ['coach'],
            'линейный судья' => ['judge'],
            'старший судья' => ['head_judge'],
        ];
    }

    /** Рендер «Аналитики» с гарантированно пустым списком отчётов. */
    private function renderEmptyAnalytics(string $role): string
    {
        $user = $this->makeUser("reports-hint-{$role}@test.local", $role);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Livewire::actingAs($user)
            ->test(EmptyReportsAnalyticsPage::class)
            ->assertSee('Отчётов пока нет')
            ->html();
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

/**
 * Страница «Аналитика» с фиктивно пустым списком отчётов: тесты не зависят
 * от реальных файлов в storage/app/public/reports (каталог боевой).
 */
class EmptyReportsAnalyticsPage extends Analytics
{
    public function getReports(): array
    {
        return [];
    }
}
