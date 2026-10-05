<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Доступ к отчётам аналитики — только авторизованным (замечание заказчика 05.10,
 * docs/ANALYTICS.md): карточки ведут на /reports/<файл>, маршрут (routes/web.php)
 * отдаёт HTML после входа. Статический /storage/reports/ закрыт веб-сервером.
 */
class ReportsAccessTest extends TestCase
{
    use RefreshDatabase;

    /** Как в AnalyticsReportsHintTest: тестам нужен рабочий PDO-драйвер БД. */
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

    protected function tearDown(): void
    {
        File::delete($this->reportPath());

        parent::tearDown();
    }

    /** Гость получает редирект на вход, HTML отчёта ему не отдаётся. */
    public function test_guest_is_redirected_to_login(): void
    {
        $this->createReportFile();

        $response = $this->get('/reports/analytics_accesstest.html');

        $response->assertRedirect();
        $this->assertStringContainsString('login', (string) $response->headers->get('Location'));
    }

    /** Авторизованный админ открывает отчёт как HTML. */
    public function test_admin_receives_report_html(): void
    {
        $this->createReportFile();

        $this->actingAs($this->makeUser('reports-access-admin@test.local', 'admin', true))
            ->get('/reports/analytics_accesstest.html')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('Проверка доступа к отчёту');
    }

    /** Судья без права видеть «Аналитику» получает 403 — как и на самой странице. */
    public function test_judge_without_analytics_access_gets_forbidden(): void
    {
        $this->createReportFile();

        $this->actingAs($this->makeUser('reports-access-judge@test.local', 'judge', false))
            ->get('/reports/analytics_accesstest.html')
            ->assertForbidden();
    }

    /** Несуществующий отчёт — 404. */
    public function test_missing_report_returns_not_found(): void
    {
        $this->actingAs($this->makeUser('reports-access-missing@test.local', 'admin', true))
            ->get('/reports/analytics_no_such_report.html')
            ->assertNotFound();
    }

    /** Попытка выйти из папки отчётов в имени файла — 404. */
    public function test_traversal_filename_returns_not_found(): void
    {
        $this->actingAs($this->makeUser('reports-access-traversal@test.local', 'admin', true))
            ->get('/reports/..%2F..%2Fstorage%2Flogs%2Flaravel.log')
            ->assertNotFound();
    }

    private function reportPath(): string
    {
        return storage_path('app/public/reports/analytics_accesstest.html');
    }

    private function createReportFile(): void
    {
        $dir = dirname($this->reportPath());
        if (! is_dir($dir)) {
            File::makeDirectory($dir, 0775, true);
        }

        File::put($this->reportPath(), <<<'HTML'
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Проверка доступа к отчёту</title>
</head>
<body>Проверка доступа к отчёту.</body>
</html>
HTML);
    }

    private function makeUser(string $email, string $role, bool $showAnalytics): User
    {
        return User::create([
            'name' => 'Тестовый пользователь',
            'email' => $email,
            'password' => Hash::make('secret'),
            'role' => $role,
            'is_active_judge' => $role !== 'admin',
            'show_analytics' => $showAnalytics,
        ]);
    }
}
