<?php

namespace Tests\Feature;

use App\Filament\Resources\AgeGroupResource\Pages\ListAgeGroups;
use App\Http\Controllers\AgeGroupsMemoPdfController;
use App\Models\AgeGroup;
use App\Models\Competition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Памятка судьям «Лимиты оценок» (PDF) для раздела «Возрастные группы»:
 * лимиты оценок категории и лимиты судей B (сценарий A/B) для печати
 * и раздачи судьям (правила 8.2, 8.3, R-4.19).
 */
class AgeGroupsMemoTest extends TestCase
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
    public function test_access_to_limits_memo_pdf(): void
    {
        $competition = $this->makeCompetition();
        $this->makeAgeGroups();

        // Гость — до actingAs (авторизация в тесте сохраняется между запросами).
        $this->get(route('competition.age-groups-memo', $competition))
            ->assertRedirect(route('filament.admin.auth.login'));

        foreach ([
            'limits-admin@test.local' => 'admin',
            'limits-hj@test.local' => 'head_judge',
        ] as $email => $role) {
            $this->actingAs($this->makeUser($email, $role))
                ->get(route('competition.age-groups-memo', $competition))
                ->assertOk()
                ->assertHeader('Content-Type', 'application/pdf');
        }

        $judge = $this->makeUser('limits-judge@test.local', 'judge');
        $this->actingAs($judge)
            ->get(route('competition.age-groups-memo', $competition))
            ->assertForbidden();
    }

    /** Памятка: две таблицы лимитов — категории и судей B — по всем группам. */
    public function test_memo_renders_category_and_panel_b_limits(): void
    {
        $competition = $this->makeCompetition();
        $this->makeAgeGroups();

        $html = view('pdf.age-groups-memo', AgeGroupsMemoPdfController::memoData($competition))
            ->render();

        // Заголовок, соревнование и названия таблиц — как в форме справочника.
        $this->assertStringContainsString('Памятка судьям: лимиты оценок', $html);
        $this->assertStringContainsString('Мемориал 2026', $html);
        $this->assertStringContainsString('Лимиты оценок для этой категории', $html);
        $this->assertStringContainsString('Сценарий A/B: лимиты оценок судей B', $html);

        // Группа с лимитами: категория 8.000–9.500, судьи B 3.000–3.500.
        $this->assertStringContainsString('Юниоры', $html);
        $this->assertStringContainsString('8.000', $html);
        $this->assertStringContainsString('9.500', $html);
        $this->assertStringContainsString('3.000', $html);
        $this->assertStringContainsString('3.500', $html);

        // Группа без лимитов: общий диапазон 0.000–10.000, судьи B — 0.000–5.000.
        $this->assertStringContainsString('Мужчины', $html);
        $this->assertStringContainsString('10.000', $html);
        $this->assertStringContainsString('5.000', $html);

        // Пояснения для судей.
        $this->assertStringContainsString('лимиты категории не применяются', $html);
    }

    /** В разделе «Возрастные группы» есть кнопка «Памятка (PDF)» и она отрабатывает. */
    public function test_list_page_has_memo_button(): void
    {
        $competition = $this->makeCompetition();
        $this->makeAgeGroups();
        $admin = $this->makeUser('limits-btn@test.local', 'admin');

        Livewire::actingAs($admin)
            ->test(ListAgeGroups::class)
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

    /** Группа с лимитами (категория и B) и группа без лимитов (общие диапазоны). */
    private function makeAgeGroups(): void
    {
        AgeGroup::create([
            'name' => 'Юниоры', 'gender' => 'male',
            'min_age' => 12, 'max_age' => 15, 'sort_order' => 1,
            'min_score' => 8.0, 'max_score' => 9.5,
            'b_min_score' => 3.0, 'b_max_score' => 3.5,
        ]);
        AgeGroup::create([
            'name' => 'Мужчины', 'gender' => 'male',
            'min_age' => 18, 'max_age' => 35, 'sort_order' => 2,
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
