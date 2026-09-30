<?php

namespace Tests\Feature;

use App\Filament\Resources\DeductionCodeResource\Pages\ListDeductionCodes;
use App\Http\Controllers\DeductionCodesMemoPdfController;
use App\Models\Competition;
use App\Models\DeductionCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Памятка судьям «Коды сбавок» (PDF) для раздела «Коды сбавок»:
 * активные коды таблицей с пояснениями, печать/раздача судьям (R-7.8).
 */
class DeductionCodesMemoTest extends TestCase
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

    /** Админ и старший судья получают PDF; судья — 403, гость — на логин. */
    public function test_access_to_memo_pdf(): void
    {
        $competition = $this->makeCompetition();
        $this->makeCodes();

        // Гость — до actingAs (авторизация в тесте сохраняется между запросами).
        $this->get(route('competition.deduction-codes-memo', $competition))
            ->assertRedirect(route('filament.admin.auth.login'));

        foreach ([
            'memo-admin@test.local' => 'admin',
            'memo-hj@test.local' => 'head_judge',
        ] as $email => $role) {
            $this->actingAs($this->makeUser($email, $role))
                ->get(route('competition.deduction-codes-memo', $competition))
                ->assertOk()
                ->assertHeader('Content-Type', 'application/pdf');
        }

        $judge = $this->makeUser('memo-judge@test.local', 'judge');
        $this->actingAs($judge)
            ->get(route('competition.deduction-codes-memo', $competition))
            ->assertForbidden();
    }

    /** Памятка: таблица «Код | Сбавка | Описание ошибки» только с активными кодами. */
    public function test_memo_renders_active_codes_with_explanations_and_groups(): void
    {
        $competition = $this->makeCompetition();
        $this->makeCodes();

        $html = view('pdf.deduction-codes-memo', DeductionCodesMemoPdfController::memoData($competition))
            ->render();

        // Заголовок, соревнование и пояснения.
        $this->assertStringContainsString('Памятка судьям: коды сбавок', $html);
        $this->assertStringContainsString('Мемориал 2026', $html);
        $this->assertStringContainsString('оценка = 5.000 − сумма сбавок', $html);
        $this->assertStringContainsString('не более 2 раз', $html);

        // Активные коды: с группой и без, с величиной сбавки.
        $this->assertStringContainsString('Равновесие', $html);
        $this->assertStringContainsString('Прыжки', $html);
        $this->assertStringContainsString('Нарушение равновесия', $html);
        $this->assertStringContainsString('Потеря устойчивости при приземлении', $html);
        $this->assertStringContainsString('−0.100', $html);
        $this->assertStringContainsString('−0.300', $html);

        // Неактивный код в памятку не попадает.
        $this->assertStringNotContainsString('Скрытая ошибка', $html);
    }

    /** В разделе «Коды сбавок» есть кнопка «Памятка (PDF)» и она отрабатывает. */
    public function test_list_page_has_memo_button(): void
    {
        $competition = $this->makeCompetition();
        $this->makeCodes();
        $admin = $this->makeUser('memo-btn@test.local', 'admin');

        Livewire::actingAs($admin)
            ->test(ListDeductionCodes::class)
            ->assertSee('Памятка (PDF)')
            ->callAction('memo_pdf', ['competition_id' => $competition->id])
            ->assertHasNoActionErrors();
    }

    private function makeCompetition(): Competition
    {
        return Competition::create([
            'name' => 'Мемориал 2026',
            'start_date' => '2026-03-01',
            'city' => 'Москва',
            'status_code' => 1,
            'judging_scheme' => Competition::SCHEME_AB,
        ]);
    }

    private function makeCodes(): void
    {
        DeductionCode::create([
            'code' => '10', 'label' => 'Нарушение равновесия', 'value' => 0.1,
            'group_label' => 'Равновесие', 'sort_order' => 1, 'is_active' => true,
        ]);
        DeductionCode::create([
            'code' => '31', 'label' => 'Потеря устойчивости при приземлении', 'value' => 0.3,
            'group_label' => 'Прыжки', 'sort_order' => 2, 'is_active' => true,
        ]);
        DeductionCode::create([
            'code' => '99', 'label' => 'Прочая ошибка', 'value' => 0.5,
            'group_label' => null, 'sort_order' => 3, 'is_active' => true,
        ]);
        DeductionCode::create([
            'code' => '90', 'label' => 'Скрытая ошибка', 'value' => 0.2,
            'group_label' => null, 'sort_order' => 4, 'is_active' => false,
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
