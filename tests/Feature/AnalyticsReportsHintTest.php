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
 *
 * Замечание заказчика (05.10): нижняя подсказка о папке отчётов — тоже только
 * у админа и без фразы о публичном доступе (отчёты открываются лишь
 * авторизованными пользователями через /reports/).
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

    /** Нижняя подсказка (05.10): админу — короткая, без упоминания публичного доступа. */
    public function test_admin_sees_short_footer_hint_without_public_warning(): void
    {
        $html = $this->renderOneReportAnalytics('admin');

        $this->assertStringContainsString('💡 Файлы в папке', $html);
        $this->assertStringContainsString('storage/app/public/reports', $html);
        $this->assertStringNotContainsString('без авторизации', $html);
        $this->assertStringNotContainsString('доступны по прямой ссылке', $html);
    }

    /** Нижняя подсказка (05.10): остальным аккаунтам не показывается вовсе. */
    #[DataProvider('nonAdminRoleProvider')]
    public function test_non_admin_roles_do_not_see_footer_hint(string $role): void
    {
        $html = $this->renderOneReportAnalytics($role);

        $this->assertStringNotContainsString('💡 Файлы в папке', $html);
        $this->assertStringNotContainsString('storage/app/public/reports', $html);
        $this->assertStringNotContainsString('без авторизации', $html);
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

    /** Рендер «Аналитики» с одним фиктивным отчётом (нижняя подсказка видна). */
    private function renderOneReportAnalytics(string $role): string
    {
        $user = $this->makeUser("reports-footer-{$role}@test.local", $role);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Livewire::actingAs($user)
            ->test(OneReportAnalyticsPage::class)
            ->assertSee('Тестовый отчёт по ссылкам')
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

/**
 * Страница «Аналитика» с одним фиктивным отчётом: файлы в storage/ не трогаем,
 * нижняя подсказка о папке показывается, только когда отчёты есть.
 */
class OneReportAnalyticsPage extends Analytics
{
    public function getReports(): array
    {
        return [[
            'slug' => 'analytics_test',
            'filename' => 'analytics_test.html',
            'title' => 'Тестовый отчёт по ссылкам',
            'description' => null,
            'url' => '/reports/analytics_test.html',
            'mtime' => \Illuminate\Support\Carbon::now(),
            'report_date' => null,
            'size' => 1024,
        ]];
    }
}
