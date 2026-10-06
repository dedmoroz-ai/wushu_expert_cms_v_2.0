<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Замечание заказчика (05.10): цвета всех кнопок приложения приведены к пяти
 * стандартам — серый #E6E9E8 (текст #272727), синий #0A92BA, зелёный #229954,
 * оранжевый #E67E22, красный #DC3532 (текст #FFFFFF).
 *
 * Палитры Filament (primary/info — синий, success — зелёный, warning —
 * оранжевый, danger — красный) заданы так, что shade 600 (заливка кнопки —
 * bg-custom-600) равен стандартному hex. Серые кнопки Filament переопределены
 * инлайн-CSS (#E6E9E8 / #272727), кастомные кнопки страниц (пульты, модалки,
 * протоколы, welcome) — локальными стилями.
 *
 * Палитру `gray` перебивать НЕЛЬЗЯ (регресс 05.10): она красит текст пунктов
 * меню сайдбара (светлая тема) и фоны тёмной темы — остаётся дефолт Filament.
 */
class ButtonPaletteTest extends TestCase
{
    use RefreshDatabase;

    /** Стандартные цвета кнопок: alias палитры → RGB «r, g, b» shade 600. */
    private const STANDARD_SHADE_600 = [
        'primary' => '10, 146, 186', // #0A92BA
        'info' => '10, 146, 186', // #0A92BA
        'success' => '34, 153, 84', // #229954
        'warning' => '230, 126, 34', // #E67E22
        'danger' => '220, 53, 50', // #DC3532
    ];

    /**
     * Палитра `gray` — дефолт Filament (Color::Zinc). Регресс 05.10: перебивка
     * на светлый #E6E9E8 сделала невидимыми пункты меню сайдбара (светлая
     * тема) и «посветлела» тёмная тема (фоны gray-950).
     */
    private const FILAMENT_DEFAULT_GRAY_SHADE_600 = '82, 82, 91'; // Color::Zinc

    /** Старые оттенки, которых больше не должно быть в кнопках. */
    private const LEGACY_BUTTON_COLORS = [
        '#2563eb', '#1d4ed8', '#16a34a', '#15803d', '#059669', '#047857',
        '#dc2626', '#f59e0b', '#009419', '#7f1d1d', '#f31111', '#d97706',
        '#2a436b', '#37578a', '#1e3a5f', '#dd0000',
    ];

    /** Файлы с кастомными кнопками (страницы, модалки, публичные). */
    private const CUSTOM_BUTTON_FILES = [
        'resources/views/filament/resources/competition-resource/pages/manage-competition.blade.php',
        'resources/views/filament/resources/competition-resource/pages/qr-code-modal.blade.php',
        'resources/views/filament/pages/judge-pad.blade.php',
        'resources/views/filament/pages/super-judge-pad.blade.php',
        'resources/views/filament/pages/scores-summary.blade.php',
        'resources/views/filament/pages/analytics.blade.php',
        'resources/views/livewire/preliminary-start-list.blade.php',
        'resources/views/welcome.blade.php',
        'app/Filament/Resources/CompetitionResource/RelationManagers/RegistrationsRelationManager.php',
    ];

    /**
     * Как в JudgingRulesTest: тесты требуют рабочего драйвера БД. Если нужного
     * PDO-драйвера нет — пропускаем, а не падаем.
     */
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

    /**
     * Палитры Filament: shade 600 (заливка кнопок — bg-custom-600) равен
     * стандартному hex пяти цветов кнопок; `gray` — дефолт Filament (Zinc,
     * не перебит — иначе страдают меню сайдбара и тёмная тема).
     */
    public function test_filament_button_palettes_match_standard_colors(): void
    {
        // Палитры панели регистрируются в Panel::boot() (при первом запросе
        // к панели); здесь HTTP-запроса нет — прогоняем boot() вручную.
        Filament::getPanel('admin')->boot();

        $colors = FilamentColor::getColors();

        foreach (self::STANDARD_SHADE_600 as $alias => $rgb) {
            $this->assertArrayHasKey($alias, $colors, "Палитра «{$alias}» не зарегистрирована.");
            $this->assertSame($rgb, $colors[$alias][600], "Shade 600 палитры «{$alias}» не равен стандартному цвету кнопки.");
        }

        // `gray` не переопределён — остаётся дефолтным Color::Zinc.
        $this->assertArrayHasKey('gray', $colors, 'Палитра «gray» не зарегистрирована.');
        $this->assertSame(
            self::FILAMENT_DEFAULT_GRAY_SHADE_600,
            $colors['gray'][600],
            'Палитра «gray» должна остаться дефолтной Filament (Zinc): она красит меню сайдбара и тёмную тему.',
        );
    }

    /** Ползунки скролла: тонкие, бегунок #0A92BA, дорожка #E6E9E8. */
    public function test_thin_scrollbar_styles_are_applied(): void
    {
        $response = $this->get('/admin/login')->assertOk();

        $response->assertSee('scrollbar-width: thin', false);
        $response->assertSee('scrollbar-color: #0A92BA #E6E9E8', false);
        $response->assertSee('::-webkit-scrollbar', false);
    }

    /** Панель: в разметке есть стандартные серые кнопки и синий primary. */
    public function test_admin_panel_renders_standard_button_styles(): void
    {
        $response = $this->get('/admin/login')->assertOk();

        // Серые кнопки Filament — инлайн-переопределение #E6E9E8 / #272727.
        $response->assertSee('fi-color-gray', false);
        $response->assertSee('background-color: #E6E9E8', false);
        $response->assertSee('color: #272727', false);
        // Заливка цветных кнопок — shade 600 стандартного синего.
        $response->assertSee('--primary-600:10, 146, 186', false);
    }

    /** Кастомные страницы: только стандартные цвета, старых оттенков нет. */
    public function test_custom_pages_use_only_standard_button_colors(): void
    {
        foreach (self::CUSTOM_BUTTON_FILES as $file) {
            $contents = file_get_contents(base_path($file));

            $this->assertNotFalse($contents, "Файл «{$file}» не читается.");

            foreach (self::LEGACY_BUTTON_COLORS as $legacy) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $legacy,
                    $contents,
                    "В файле «{$file}» остался старый оттенок кнопки {$legacy}.",
                );
            }
        }

        // Пульт управления (все пять стандартов в одном файле).
        $manage = file_get_contents(base_path('resources/views/filament/resources/competition-resource/pages/manage-competition.blade.php'));

        foreach (['#E6E9E8', '#272727', '#0A92BA', '#229954', '#E67E22', '#DC3532'] as $standard) {
            $this->assertStringContainsString($standard, $manage);
        }
    }

    /** Welcome: CTA-кнопки — зелёный и красный стандарты. */
    public function test_welcome_page_uses_standard_cta_colors(): void
    {
        // Гость видит кнопку «Войти в систему» — красный стандарт.
        $this->get('/')->assertOk()->assertSee('bg-[#DC3532]', false);

        // Авторизованный видит «Личный кабинет» — зелёный стандарт.
        $this->actingAs($this->makeUser('palette-welcome@test.local'))
            ->get('/')
            ->assertOk()
            ->assertSee('bg-[#229954]', false);
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
