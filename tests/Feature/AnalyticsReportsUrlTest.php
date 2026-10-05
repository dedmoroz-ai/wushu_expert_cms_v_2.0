<?php

namespace Tests\Feature;

use App\Filament\Pages\Analytics;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Фикс доступа (docs/ANALYTICS.md, 05.10): отчёт открывается только
 * авторизованными пользователями через маршрут /reports/<файл> (routes/web.php).
 * Статический путь /storage/reports/ закрыт веб-сервером и в ссылках не используется.
 */
class AnalyticsReportsUrlTest extends TestCase
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

    /** Карточка отчёта на странице «Аналитика» ссылается на /reports/. */
    public function test_report_card_links_to_authenticated_reports_route(): void
    {
        $dir = storage_path('app/public/reports');
        if (! is_dir($dir)) {
            File::makeDirectory($dir, 0775, true);
        }

        // Временный отчёт: каталог рабочий, поэтому убираем файл за собой.
        $path = $dir.'/analytics_urltest.html';
        File::put($path, <<<'HTML'
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Тестовый отчёт по ссылкам</title>
<meta name="description" content="Проверка ссылки отчёта.">
<meta name="report-date" content="2026-09-26">
</head>
<body>Отчёт.</body>
</html>
HTML);

        try {
            $user = User::create([
                'name' => 'Тестовый администратор',
                'email' => 'reports-url@test.local',
                'password' => Hash::make('secret'),
                'role' => 'admin',
                'is_active_judge' => false,
            ]);

            Filament::setCurrentPanel(Filament::getPanel('admin'));

            Livewire::actingAs($user)
                ->test(Analytics::class)
                ->assertSee('Тестовый отчёт по ссылкам')
                ->assertSee('26.09.2026')
                ->assertSee('href="/reports/analytics_urltest.html"', false)
                ->assertDontSee('/storage/reports/', false);
        } finally {
            File::delete($path);
        }
    }
}
