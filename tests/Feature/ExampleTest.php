<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke-тест главной страницы: «GET /» отвечает 200.
 *
 * Шаблонный тест Laravel падал с 500: welcome читает свежее соревнование
 * из БД (routes/web.php), а тестовая sqlite :memory: была пустой —
 * миграции не выполнялись (в шаблоне RefreshDatabase был закомментирован).
 * Приведено к шаблону проекта: RefreshDatabase + пропуск без PDO-драйвера,
 * как в AppFooterTest.
 */
class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Как в JudgingRulesTest/AppFooterTest: тесты требуют рабочего драйвера БД.
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

    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
