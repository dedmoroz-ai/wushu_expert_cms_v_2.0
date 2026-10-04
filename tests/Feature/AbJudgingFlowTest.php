<?php

namespace Tests\Feature;

use App\Filament\Pages\JudgePad;
use App\Filament\Pages\SuperJudgePad;
use App\Models\AgeGroup;
use App\Models\Athlete;
use App\Models\Club;
use App\Models\Competition;
use App\Models\DeductionCode;
use App\Models\JudgingLog;
use App\Models\Registration;
use App\Models\Score;
use App\Models\Style;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Сквозной сценарий A/B на пультах (R-2.11, R-3.12–R-3.16, R-4.18–R-4.20, R-6.13).
 */
class AbJudgingFlowTest extends TestCase
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

        if (!in_array($driver, \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped("PDO-драйвер «{$driver}» не установлен (нужен для тестовой БД).");
        }

        parent::setUp();
    }

    public function test_full_ab_cycle_from_pads_to_protocol(): void
    {
        [, $reg, $judges, $head] = $this->makeAbTournament();

        $c11 = DeductionCode::create(['code' => '11', 'label' => 'Руки', 'value' => 0.1, 'sort_order' => 1]);
        $c22 = DeductionCode::create(['code' => '22', 'label' => 'Падение', 'value' => 0.3, 'sort_order' => 2]);

        // Судья A1: 11, 11, (третье 11 — блок, R-3.14), 22 → 5 − 0.5 = 4.500
        $this->actingAs($judges['a1']);
        Livewire::test(JudgePad::class)
            ->assertSet('panel', 'A')
            ->assertSet('inputMode', 'codes')
            ->call('addNumber', '4')
            ->assertSet('score', '')
            ->call('pressCode', $c11->id)
            ->call('pressCode', $c11->id)
            ->call('pressCode', $c11->id)
            ->call('pressCode', $c22->id)
            ->assertSet('pressedCodes', [$c11->id, $c11->id, $c22->id])
            ->call('submitScore');

        // Судья A2: 11 → отмена → 22 → 4.700
        $this->actingAs($judges['a2']);
        Livewire::test(JudgePad::class)
            ->call('pressCode', $c11->id)
            ->call('undoLastCode')
            ->call('pressCode', $c22->id)
            ->call('submitScore');

        // Судья B1: одна цифра целой части, «40» не набирается.
        $this->actingAs($judges['b1']);
        Livewire::test(JudgePad::class)
            ->assertSet('inputMode', 'keypad')
            ->call('addNumber', '4')
            ->call('addNumber', '0')
            ->call('addNumber', '.')
            ->call('addNumber', '2')
            ->assertSet('score', '4.2')
            ->call('submitScore');

        $this->actingAs($judges['b2']);
        Livewire::test(JudgePad::class)
            ->call('addNumber', '3')
            ->call('addNumber', '.')
            ->call('addNumber', '8')
            ->call('submitScore');

        $scoreA1 = Score::where('judge_id', $judges['a1']->id)->first();
        $this->assertSame(4.5, (float) $scoreA1->score);
        $this->assertSame('A', $scoreA1->panel);
        $this->assertSame(3, $scoreA1->deductions()->count());
        $this->assertSame(4.7, (float) Score::where('judge_id', $judges['a2']->id)->value('score'));
        $this->assertSame('B', Score::where('judge_id', $judges['b1']->id)->value('panel'));

        // Старший судья (функция B) ставит 4.100.
        // A: [4.5, 4.7] → 4.600; B: [4.2, 3.8, 4.1] → по всем оценкам → 4.033 (R-4.19); итог 8.633.
        $this->actingAs($head);
        Livewire::test(SuperJudgePad::class)
            ->assertSet('myPanel', 'B')
            ->assertSet('receivedA', 2)
            ->assertSet('expectedB', 3)
            ->assertSet('receivedB', 2)
            ->assertSet('canFinalize', false)
            ->call('addNumber', '4')
            ->call('addNumber', '.')
            ->call('addNumber', '1')
            ->call('submitMyScore')
            ->assertSet('avgA', '4.600')
            ->assertSet('avgB', '4.033')
            ->assertSet('calculatedAvg', '8.633')
            ->assertSet('canFinalize', true)
            ->call('finalizeProtocol');

        $reg->refresh();
        $this->assertTrue((bool) $reg->is_completed);
        $this->assertSame(8.633, (float) $reg->final_score);
        $this->assertSame(4.6, (float) $reg->score_a);
        $this->assertSame(4.033, (float) $reg->score_b);

        $log = JudgingLog::where('action', JudgingLog::ACTION_PROTOCOL_FINALIZED)->first();
        $this->assertNotNull($log);
        $this->assertSame('ab', $log->details['scheme']);
        $this->assertCount(5, $log->details['scores']);
        $this->assertSame(4.6, (float) $log->details['avg_a']);
    }

    public function test_judge_without_panel_is_blocked_in_ab(): void
    {
        [, , $judges] = $this->makeAbTournament(assignPanels: false);

        $this->actingAs($judges['a1']);

        Livewire::test(JudgePad::class)
            ->assertSet('statusMessage', 'Функция судьи не назначена')
            ->assertSet('canVote', false);
    }

    public function test_simple_scheme_keeps_old_behaviour(): void
    {
        [$competition, , $judges] = $this->makeAbTournament();
        $competition->update(['judging_scheme' => Competition::SCHEME_SIMPLE]);

        $this->actingAs($judges['a1']);

        Livewire::test(JudgePad::class)
            ->assertSet('panel', null)
            ->assertSet('inputMode', 'keypad')
            ->call('addNumber', '1')
            ->call('addNumber', '0')
            ->assertSet('score', '10');
    }

    public function test_super_pad_shows_head_surname_role_and_category_max(): void
    {
        [, $reg, , $head] = $this->makeAbTournament();
        $reg->ageGroup->update(['b_min_score' => 3.0, 'b_max_score' => 3.5]);
        $head->update(['name' => 'Петров Пётр Петрович']);

        $this->actingAs($head);

        Livewire::test(SuperJudgePad::class)
            ->assertSet('myPanel', 'B')
            ->assertSet('mySurname', 'Петров')
            ->assertSet('scoreRangeLabel', '3.000 – 3.500')
            ->assertSet('totalMaxLabel', '8.5 (5.0+3.5)')
            // В HTML между blade-директивами Livewire вставляет свои маркеры,
            // поэтому строки проверяем по непрерывным фрагментам.
            ->assertSee('Петров (ст. судья)', false)
            ->assertSee('ДЛЯ СУДЬИ "B" - ', false)
            ->assertSee('ДИАПАЗОН:', false)
            ->assertSee('МАКСИМУМ:', false)
            ->assertSee('8.5 (5.0+3.5)', false)
            ->assertDontSee('Я (Гл. Судья)', false)
            ->assertDontSee('ИТОГ:', false);
    }

    public function test_super_pad_hides_range_when_head_judges_panel_a(): void
    {
        [$competition, , , $head] = $this->makeAbTournament();
        $competition->judges()->updateExistingPivot($head->id, ['panel' => 'A']);

        $this->actingAs($head);

        Livewire::test(SuperJudgePad::class)
            ->assertSet('myPanel', 'A')
            ->assertDontSee('ДИАПАЗОН', false);
    }

    /**
     * Замечание заказчика (30.09): пульт старшего судьи в аккаунте админа — только
     * просмотр: без плашки «ст. судья» (админ не судья) и без действий.
     */
    public function test_super_pad_is_view_only_for_admin(): void
    {
        [, $reg, $judges] = $this->makeAbTournament();

        // Судья B1 выставил оценку — для судей была бы видна кнопка «Снять».
        $this->actingAs($judges['b1']);
        Livewire::test(JudgePad::class)
            ->call('addNumber', '4')
            ->call('addNumber', '.')
            ->call('addNumber', '2')
            ->call('submitScore');

        $admin = User::create([
            'name' => 'Иванов Админ',
            'email' => str()->random(12) . '@test.local',
            'password' => Hash::make('secret'),
            'role' => 'admin',
            'is_active_judge' => true,
        ]);

        $this->actingAs($admin);

        Livewire::test(SuperJudgePad::class)
            ->assertSet('isViewOnly', true)
            ->assertSet('canScoreSelf', false)
            ->assertDontSee('ст. судья', false)
            ->assertDontSee('Снять', false)
            ->assertDontSee('В ПРОТОКОЛ', false)
            ->assertDontSee('ПОДТВЕРДИТЬ ОЦЕНКУ', false)
            ->assertSee('РЕЖИМ ПРОСМОТРА', false)
            ->call('addNumber', '4')
            ->assertSet('myScore', '')
            ->call('submitMyScore')
            ->call('resetJudgeScore', $judges['b1']->id)
            ->call('finalizeProtocol');

        // Ни оценка админа не создана, ни оценка B1 не снята, протокол не утверждён.
        $this->assertSame(0, Score::where('judge_id', $admin->id)->count());
        $this->assertSame(1, Score::where('judge_id', $judges['b1']->id)->count());
        $this->assertFalse((bool) $reg->fresh()->is_completed);
    }

    public function test_linear_pad_hides_range_for_panel_a(): void
    {
        [, $reg, $judges] = $this->makeAbTournament();
        $reg->ageGroup->update(['b_min_score' => 3.0, 'b_max_score' => 3.5]);

        $this->actingAs($judges['a1']);
        Livewire::test(JudgePad::class)
            ->assertSet('panel', 'A')
            ->assertSet('inputMode', 'codes')
            ->assertDontSee('Диапазон', false);

        $this->actingAs($judges['b1']);
        Livewire::test(JudgePad::class)
            ->assertSet('panel', 'B')
            ->assertSee('Диапазон: 3.000 – 3.500', false);
    }

    /**
     * @return array{0: Competition, 1: Registration, 2: array<string, User>, 3: User}
     */
    private function makeAbTournament(bool $assignPanels = true): array
    {
        $competition = Competition::create([
            'name' => 'A/B турнир',
            'start_date' => '2026-02-01',
            'city' => 'Москва',
            'status_code' => 1,
            'judging_scheme' => Competition::SCHEME_AB,
        ]);

        $group = AgeGroup::create(['name' => 'Юниоры', 'gender' => 'male', 'min_age' => 12, 'max_age' => 14]);
        $club = Club::create(['name' => 'Клуб ' . str()->random(5)]);
        $athlete = Athlete::create([
            'club_id' => $club->id,
            'name' => 'Иванов Иван',
            'birth_date' => '2013-05-01',
            'gender' => 'male',
        ]);
        $style = Style::create(['name' => 'Чанцюань']);

        $reg = Registration::create([
            'competition_id' => $competition->id,
            'athlete_id' => $athlete->id,
            'style_id' => $style->id,
            'age_group_id' => $group->id,
            'sort_order' => 1,
            'is_completed' => false,
        ]);

        $competition->update(['current_registration_id' => $reg->id]);

        $make = fn (string $name, string $role = 'judge') => User::create([
            'name' => $name,
            'email' => str()->random(12) . '@test.local',
            'password' => Hash::make('secret'),
            'role' => $role,
            'is_active_judge' => true,
        ]);

        $judges = ['a1' => $make('A1'), 'a2' => $make('A2'), 'b1' => $make('B1'), 'b2' => $make('B2')];
        $head = $make('Старший', 'head_judge');
        $panels = ['a1' => 'A', 'a2' => 'A', 'b1' => 'B', 'b2' => 'B'];

        foreach ($judges as $key => $user) {
            $competition->judges()->attach($user->id, ['panel' => $assignPanels ? $panels[$key] : null]);
        }

        $competition->judges()->attach($head->id, ['panel' => $assignPanels ? 'B' : null]);

        return [$competition->fresh(), $reg, $judges, $head];
    }
}
