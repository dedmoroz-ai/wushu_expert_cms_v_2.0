<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Замечание заказчика (30.09): футер приложения 50px на всю ширину —
 * «Wushu Expert CMS 3.0» слева, «© 2026 Макс Мороз» + лого разработчика
 * справа; белый текст на тёмной теме, чёрный на светлой. В админ-панели
 * и на welcome. Порядок «© 2026» (сначала знак копирайта, потом год) —
 * по замечанию заказчика (01.10).
 *
 * Правка заказчика (04.10): единая строка заглавными
 * «WUSHU EXPERT COMPETITION MANAGEMENT SYSTEM 3.0 © 2026 МАКС МОРОЗ (logo)» —
 * имя перед логотипом разработчика, версия после названия.
 */
class AppFooterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Как в JudgingRulesTest: тесты требуют рабочего драйвера БД. Если нужного
     * PDO-драйвера нет — пропускаем, а не падаем.
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

    /** Версия приложения в футере берётся из config('app.version'). */
    public function test_app_version_config_is_set(): void
    {
        $this->assertIsString(config('app.version'));
        $this->assertNotSame('', config('app.version'));
    }

    /** Welcome: футер 50px — версия слева, копирайт + год + лого разработчика справа. */
    public function test_welcome_page_shows_app_footer(): void
    {
        $response = $this->get('/')->assertOk();

        $response->assertSee('WUSHU EXPERT COMPETITION MANAGEMENT SYSTEM '.config('app.version'));
        // Порядок (01.10): сначала знак копирайта, потом год.
        $response->assertSee('&copy; 2026', false);
        // Порядок (04.10): имя, затем логотип разработчика.
        $response->assertSeeInOrder(['МАКС МОРОЗ', 'images/c989.svg']);
        // Welcome — тёмный дизайн: только c989.svg, светлого варианта d989.svg нет.
        $response->assertDontSee('images/d989.svg', false);
        // Высота полосы — 50px (по уточнению заказчика).
        $response->assertSee('h-[50px]', false);
    }

    /** Админ-панель: футер на странице входа (общей для всей панели). */
    public function test_admin_login_page_shows_app_footer(): void
    {
        $response = $this->get('/admin/login')->assertOk();

        $response->assertSee('wushu-app-footer', false);
        $response->assertSee('WUSHU EXPERT COMPETITION MANAGEMENT SYSTEM '.config('app.version'));
        // Порядок (01.10): сначала знак копирайта, потом год.
        $response->assertSee('&copy; 2026', false);
        // Лого разработчика зависит от темы: d989.svg (светлая) + c989.svg (тёмная).
        $response->assertSee('images/d989.svg', false);
        $response->assertSee('images/c989.svg', false);
        // Порядок (04.10): имя, затем логотип разработчика.
        $response->assertSeeInOrder(['МАКС МОРОЗ', 'images/d989.svg']);
        $response->assertSee('wushu-app-footer__logo-light-theme', false);
        $response->assertSee('wushu-app-footer__logo-dark-theme', false);
    }

    /** Админ-панель: футер на авторизованной странице (дашборд). */
    public function test_admin_dashboard_shows_app_footer(): void
    {
        $this->actingAs($this->makeUser('footer-admin@test.local'));

        $response = $this->get('/admin')->assertOk();

        $response->assertSee('wushu-app-footer', false);
        $response->assertSee('WUSHU EXPERT COMPETITION MANAGEMENT SYSTEM '.config('app.version'));
        // Порядок (01.10): сначала знак копирайта, потом год.
        $response->assertSee('&copy; 2026', false);
        // Лого разработчика зависит от темы: d989.svg (светлая) + c989.svg (тёмная).
        $response->assertSee('images/d989.svg', false);
        $response->assertSee('images/c989.svg', false);
        // Порядок (04.10): имя, затем логотип разработчика.
        $response->assertSeeInOrder(['МАКС МОРОЗ', 'images/d989.svg']);
        // Компенсация фиксированной полосы футера — контент не прячется под ней.
        $response->assertSee('padding-bottom: 50px', false);
    }

    private function makeUser(string $email): User
    {
        return User::create([
            'name' => 'Пользователь',
            'email' => $email,
            'password' => Hash::make('secret'),
            'role' => 'admin',
            'is_active_judge' => false,
        ]);
    }
}
