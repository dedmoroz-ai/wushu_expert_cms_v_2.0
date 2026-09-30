<?php

namespace Tests\Feature;

use App\Models\Style;
use Database\Seeders\StyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Стандартизация категорий видов программ (30.09.2026):
 *  - единый канон названий Style::CATEGORIES («Таолу (Комплексы)»,
 *    «Традиционное ушу», «Юнчуньцюань») для справочника и формы заявки;
 *  - виды «Юнчуньцюань - ...» относятся к категории 'yongchun', а не
 *    'traditional' (в форме заявки появилась отдельная секция «Юнчуньцюань»);
 *  - миграция-фиксап 2026_09_30_100100_normalize_style_categories переводит
 *    старые строки в 'yongchun'.
 */
class StyleCategoriesTest extends TestCase
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

    public function test_category_names_are_standardized(): void
    {
        // Канон названий — один для справочника «Виды программы» и формы заявки
        $this->assertSame(
            [
                'taolu' => 'Таолу (Комплексы)',
                'traditional' => 'Традиционное ушу',
                'yongchun' => 'Юнчуньцюань',
            ],
            Style::CATEGORIES
        );

        $this->assertSame('Таолу (Комплексы)', Style::categoryLabel('taolu'));
        $this->assertSame('Традиционное ушу', Style::categoryLabel('traditional'));
        $this->assertSame('Юнчуньцюань', Style::categoryLabel('yongchun'));
        // Неизвестный ключ не «падает», а выводится как есть
        $this->assertSame('sanda', Style::categoryLabel('sanda'));
    }

    public function test_seeder_sorts_styles_into_three_categories(): void
    {
        $this->seed(StyleSeeder::class);

        // Юнчуньцюань — отдельная категория, а не «Традиционное ушу»
        $yongchun = Style::where('category', 'yongchun')->pluck('name')->all();
        $this->assertCount(6, $yongchun);
        $this->assertContains('Юнчуньцюань - Гуйдин Дуйда', $yongchun);
        $this->assertContains('Юнчуньцюань - Мужэньчжуан', $yongchun);

        // Ни один юнчуньцюаньский вид не остался в traditional
        $this->assertSame(
            0,
            Style::where('category', 'traditional')->where('name', 'like', 'Юнчуньцюань%')->count()
        );

        // Традиционные и таолу-виды не задеты
        $this->assertContains(
            'Традиционное ушу Гуньшу',
            Style::where('category', 'traditional')->pluck('name')->all()
        );
        $this->assertContains(
            'Чанцюань',
            Style::where('category', 'taolu')->pluck('name')->all()
        );
    }

    public function test_migration_recategorizes_legacy_yongchun_styles(): void
    {
        // Старые данные: юнчуньцюаньские виды лежали в 'traditional'
        $legacy = Style::create(['name' => 'Юнчуньцюань - Гуйдин', 'category' => 'traditional']);
        $other = Style::create(['name' => 'Традиционное ушу Гуньшу', 'category' => 'traditional']);

        $migration = require database_path('migrations/2026_09_30_100100_normalize_style_categories.php');
        $migration->up();
        $migration->up(); // идемпотентность

        $this->assertSame('yongchun', $legacy->fresh()->category);
        $this->assertSame('traditional', $other->fresh()->category);

        // down() возвращает прежнюю категорию
        $migration->down();
        $this->assertSame('traditional', $legacy->fresh()->category);

        // Повторный up() после отката снова переводит в 'yongchun'
        $migration->up();
        $this->assertSame('yongchun', $legacy->fresh()->category);
        $this->assertSame(
            0,
            DB::table('styles')->where('category', 'traditional')->where('name', 'like', 'Юнчуньцюань%')->count()
        );
    }
}
