<?php

namespace Tests\Feature;

use App\Filament\Widgets\PreStartListWidget;
use App\Filament\Widgets\PublicResultsQrWidget;
use App\Models\Competition;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Заголовки виджетов дашборда (замечания заказчика 05.10): у виджетов
 * «Протокол» и «QR-код» убраны заголовки секций Filament и описания.
 * Заголовки виджетов статические (название соревнования не показывается):
 * левый — «Стартовый протокол», подпись «Предварительный (по поданным
 * заявкам)»; правый — «Результаты соревнования», подпись «(поделиться)».
 * Кнопки — «Открыть протокол» и «QR-код».
 */
class DashboardWidgetsLabelsTest extends TestCase
{
    use RefreshDatabase;

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

    /** Виджет протокола: статический заголовок «Стартовый протокол», подпись «Предварительный (по поданным заявкам)». */
    public function test_pre_start_list_widget_has_no_heading_and_short_label(): void
    {
        $competition = $this->makeCompetition();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $html = Livewire::actingAs($this->makeUser('labels-protocol@test.local', 'admin'))
            ->test(PreStartListWidget::class)
            ->html();

        $this->assertStringNotContainsString('fi-section-header', $html, 'Заголовок секции виджета убран (замечание заказчика 05.10).');
        $this->assertStringNotContainsString('Предварительный стартовый протокол', $html, 'Заголовок секции виджета убран (замечание заказчика 05.10).');
        $this->assertStringContainsString('Стартовый протокол', $html, 'Заголовок виджета — «Стартовый протокол» (замечание заказчика 05.10, правка 2).');
        $this->assertStringContainsString('Предварительный (по поданным заявкам)', $html, 'Подпись «Предварительный (по поданным заявкам)» (замечание заказчика 05.10, правка 2).');
        $this->assertStringNotContainsString($competition->name, $html, 'Название соревнования в виджете не показывается (замечание заказчика 05.10, правка 2).');
        $this->assertStringContainsString('Открыть протокол', $html, 'Кнопка открытия протокола на месте.');
    }

    /**
     * Виджет QR-кода: статический заголовок «Результаты соревнования», подпись
     * «(поделиться)», кнопка — «QR-код».
     */
    public function test_public_results_qr_widget_has_no_heading_and_short_labels(): void
    {
        $competition = $this->makeCompetition();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $html = Livewire::actingAs($this->makeUser('labels-qr@test.local', 'admin'))
            ->test(PublicResultsQrWidget::class)
            ->html();

        $this->assertStringNotContainsString('fi-section-header', $html, 'Заголовок секции виджета убран (замечание заказчика 05.10).');
        $this->assertStringNotContainsString('Ссылка и QR-код публичной страницы', $html, 'Описание виджета убрано (замечание заказчика 05.10).');
        $this->assertStringNotContainsString('Показать QR-код', $html, 'Старая подпись кнопки убрана (замечание заказчика 05.10).');
        $this->assertStringContainsString('Результаты соревнования', $html, 'Заголовок виджета — «Результаты соревнования» (замечание заказчика 05.10, правка 2).');
        $this->assertStringContainsString('(поделиться)', $html, 'Подпись «(поделиться)» (замечание заказчика 05.10, правка 2).');
        $this->assertStringNotContainsString('Страница результатов', $html, 'Прежняя подпись «Страница результатов (поделиться)» убрана (замечание заказчика 05.10, правка 2).');
        $this->assertStringNotContainsString($competition->name, $html, 'Название соревнования в виджете не показывается (замечание заказчика 05.10, правка 2).');
        // Подпись кнопки «QR-код» — в тексте кнопки (alt картинки модалки её не даёт).
        $this->assertMatchesRegularExpression('/>\s*QR-код\s*</u', $html, 'Кнопка «QR-код» на месте (замечание заказчика 05.10).');
    }

    private function makeCompetition(): Competition
    {
        return Competition::create([
            'name' => 'Кубок '.str()->random(5),
            'start_date' => '2026-03-01',
            'city' => 'Симферополь',
            'status_code' => 1,
            'judging_scheme' => Competition::SCHEME_AB,
        ]);
    }

    private function makeUser(string $email, string $role): User
    {
        return User::create([
            'name' => 'Пользователь',
            'email' => $email,
            'password' => Hash::make('secret'),
            'role' => $role,
            'is_active_judge' => $role !== 'admin',
        ]);
    }
}
