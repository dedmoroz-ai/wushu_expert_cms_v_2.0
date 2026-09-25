<?php

namespace Tests\Feature;

use App\Models\AgeGroup;
use App\Models\Athlete;
use App\Models\Club;
use App\Models\Competition;
use App\Models\DeductionCode;
use App\Models\JudgingLog;
use App\Models\Registration;
use App\Models\Score;
use App\Models\ScoreDeduction;
use App\Models\Style;
use App\Models\User;
use App\Support\JudgingCalculator;
use App\Support\ScoreRange;
use App\Support\ScoreWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Правила 8.1, 8.3, 8.4, 8.5, 8.8, 8.9 (docs/JUDGING_RULES.md).
 */
class JudgingRulesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Тесты требуют рабочего драйвера БД. По умолчанию phpunit.xml использует
     * sqlite :memory:, но подключение можно переопределить переменными окружения
     * (например, DB_CONNECTION=pgsql для локального PostgreSQL в Docker).
     * Если нужного PDO-драйвера нет — пропускаем, а не падаем.
     */
    protected function setUp(): void
    {
        // Важно: проверяем до parent::setUp(), поэтому контейнер (и хелпер config())
        // ещё недоступен — читаем переменную окружения напрямую.
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

    // --- 8.1: точность оценок ---

    public function test_scores_score_stores_three_decimals(): void
    {
        $reg = $this->makeRegistration();
        $judge = $this->makeJudge('Судья А');

        Score::create([
            'registration_id' => $reg->id,
            'judge_id' => $judge->id,
            'score' => 8.125,
        ]);

        // Раньше decimal(4,2) округлял 8.125 до 8.13.
        $this->assertSame(8.125, (float) Score::first()->score);
    }

    // --- 8.3: лимиты в age_groups ---

    public function test_age_groups_have_score_limit_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('age_groups', 'min_score'));
        $this->assertTrue(Schema::hasColumn('age_groups', 'max_score'));
    }

    public function test_registration_score_range_uses_age_group_limits(): void
    {
        $reg = $this->makeRegistration(['min_score' => 5.0, 'max_score' => 9.8]);

        $range = $reg->fresh()->scoreRange();

        $this->assertSame(5.0, $range->min);
        $this->assertSame(9.8, $range->max);
    }

    public function test_registration_without_limits_falls_back_to_global_range(): void
    {
        $reg = $this->makeRegistration();

        $range = $reg->fresh()->scoreRange();

        $this->assertSame(0.0, $range->min);
        $this->assertSame(10.0, $range->max);
    }

    // --- 8.4: бригада турнира ---

    public function test_active_judges_returns_only_brigade_of_this_competition(): void
    {
        $competition = $this->makeCompetition();

        $inBrigade = $this->makeJudge('В бригаде');
        $notInBrigade = $this->makeJudge('Не в бригаде');
        $inactive = $this->makeJudge('Отстранён', isActive: false);

        $competition->judges()->attach([$inBrigade->id, $inactive->id]);

        // Правило 8.4: считаем только привязанных И допущенных.
        $this->assertSame(['В бригаде'], $competition->activeJudges()->pluck('name')->all());
        $this->assertTrue($competition->hasActiveJudge($inBrigade));
        $this->assertFalse($competition->hasActiveJudge($notInBrigade));
        $this->assertFalse($competition->hasActiveJudge($inactive));
        $this->assertFalse($competition->hasActiveJudge(null));
    }

    public function test_head_judge_can_be_part_of_brigade(): void
    {
        $competition = $this->makeCompetition();
        $head = $this->makeJudge('Старший', role: 'head_judge');

        $competition->judges()->attach($head->id);

        $this->assertTrue($competition->hasActiveJudge($head));
    }

    // --- 8.8: автопереход только вперёд ---

    public function test_next_athlete_search_moves_forward_only(): void
    {
        $competition = $this->makeCompetition();

        $skipped = $this->makeRegistration([], $competition, sortOrder: 1);
        $current = $this->makeRegistration([], $competition, sortOrder: 5);
        $next = $this->makeRegistration([], $competition, sortOrder: 7);

        $this->assertFalse($skipped->is_completed);

        // Повторяем запрос из SuperJudgePad::finalizeProtocol().
        $found = Registration::where('competition_id', $competition->id)
            ->where('is_completed', false)
            ->where('id', '!=', $current->id)
            ->where('sort_order', '>', $current->sort_order)
            ->orderBy('sort_order', 'asc')
            ->first();

        // Правило 8.8: система НЕ возвращается к №1, а идёт к №7.
        $this->assertNotNull($found);
        $this->assertSame($next->id, $found->id);
    }

    // --- 8.9: журнал аудита ---

    public function test_judging_log_records_action_with_actor(): void
    {
        $reg = $this->makeRegistration();
        $judge = $this->makeJudge('Судья Б');

        $this->actingAs($judge);

        $log = JudgingLog::record(JudgingLog::ACTION_SCORE_UPDATED, [
            'registration_id' => $reg->id,
            'judge_id' => $judge->id,
            'old_value' => 8.500,
            'new_value' => 9.125,
            'reason' => 'Тест',
        ]);

        $this->assertNotNull($log);
        $this->assertSame(JudgingLog::ACTION_SCORE_UPDATED, $log->action);
        $this->assertSame($judge->id, $log->actor_id);
        $this->assertSame(8.5, (float) $log->old_value);
        $this->assertSame(9.125, (float) $log->new_value);
        $this->assertSame('Тест', $log->reason);
    }

    // --- Сценарий A/B (R-2.11, R-3.12–R-3.16, R-4.18–R-4.20, R-6.13) ---

    public function test_new_ab_columns_and_tables_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('competitions', 'judging_scheme'));
        $this->assertTrue(Schema::hasColumn('competition_user', 'panel'));
        $this->assertTrue(Schema::hasColumn('scores', 'panel'));
        $this->assertTrue(Schema::hasColumn('registrations', 'score_a'));
        $this->assertTrue(Schema::hasColumn('registrations', 'score_b'));
        $this->assertTrue(Schema::hasColumn('age_groups', 'b_min_score'));
        $this->assertTrue(Schema::hasColumn('judging_logs', 'details'));
        $this->assertTrue(Schema::hasTable('deduction_codes'));
        $this->assertTrue(Schema::hasTable('score_deductions'));
    }

    public function test_competition_defaults_to_simple_scheme(): void
    {
        $competition = $this->makeCompetition()->fresh();

        $this->assertSame(Competition::SCHEME_SIMPLE, $competition->judgingScheme());
        $this->assertFalse($competition->isAbScheme());
    }

    public function test_panel_of_returns_brigade_function(): void
    {
        $competition = $this->makeCompetition();
        $a = $this->makeJudge('Судья A');
        $none = $this->makeJudge('Без функции');

        $competition->judges()->attach($a->id, ['panel' => 'A']);
        $competition->judges()->attach($none->id);

        $this->assertSame('A', $competition->panelOf($a));
        $this->assertNull($competition->panelOf($none));
        $this->assertNull($competition->panelOf(null));
    }

    public function test_judge_range_depends_on_scheme_and_panel(): void
    {
        $reg = $this->makeRegistration(['min_score' => 6.0, 'max_score' => 10.0, 'b_min_score' => 2.0]);

        // simple: лимиты категории.
        $this->assertSame('6.000 – 10.000', ScoreRange::forJudge($reg->fresh(), null)->label());

        $reg->competition->update(['judging_scheme' => Competition::SCHEME_AB]);
        $reg = $reg->fresh();

        $this->assertSame('0.000 – 5.000', ScoreRange::forJudge($reg, 'A')->label());
        $this->assertSame('2.000 – 5.000', ScoreRange::forJudge($reg, 'B')->label());
        $this->assertSame('0.000 – 10.000', ScoreRange::totalRange($reg)->label());
    }

    public function test_score_writer_saves_a_score_with_deductions_and_detailed_log(): void
    {
        $reg = $this->makeRegistration();
        $reg->competition->update(['judging_scheme' => Competition::SCHEME_AB]);
        $judge = $this->makeJudge('Судья A');
        $this->actingAs($judge);

        $c11 = DeductionCode::create(['code' => '11', 'label' => 'Руки', 'value' => 0.1]);
        $c22 = DeductionCode::create(['code' => '22', 'label' => 'Падение', 'value' => 0.3]);

        $deductions = ScoreWriter::snapshotDeductions([$c11->id, $c11->id, $c22->id]);
        $value = JudgingCalculator::scoreFromDeductions(array_column($deductions, 'value'));

        $this->assertTrue(ScoreWriter::save($reg->competition->fresh(), $reg, $judge->id, $value, 'A', $deductions));

        $score = Score::first();
        $this->assertSame(4.5, (float) $score->score);
        $this->assertSame('A', $score->panel);
        $this->assertSame(['11', '11', '22'], $score->deductions->pluck('code')->all());

        $log = JudgingLog::where('action', JudgingLog::ACTION_SCORE_CREATED)->first();
        $this->assertSame('A', $log->details['panel']);
        $this->assertSame('ab', $log->details['scheme']);
        $this->assertCount(3, $log->details['deductions']);
        $this->assertSame(0.5, (float) $log->details['deductions_total']);
        $this->assertStringContainsString('= 4.500', $log->reason);
    }

    public function test_score_writer_update_rewrites_deductions_and_logs_old_set(): void
    {
        $reg = $this->makeRegistration();
        $reg->competition->update(['judging_scheme' => Competition::SCHEME_AB]);
        $judge = $this->makeJudge('Судья A');
        $this->actingAs($judge);

        $c11 = DeductionCode::create(['code' => '11', 'label' => 'Руки', 'value' => 0.1]);
        $c22 = DeductionCode::create(['code' => '22', 'label' => 'Падение', 'value' => 0.3]);
        $competition = $reg->competition->fresh();

        ScoreWriter::save($competition, $reg, $judge->id, 4.9, 'A', ScoreWriter::snapshotDeductions([$c11->id]));
        $created = ScoreWriter::save($competition, $reg, $judge->id, 4.7, 'A', ScoreWriter::snapshotDeductions([$c22->id]));

        $this->assertFalse($created);
        $this->assertSame(1, Score::count());
        $this->assertSame(['22'], Score::first()->deductions->pluck('code')->all());

        $log = JudgingLog::where('action', JudgingLog::ACTION_SCORE_UPDATED)->first();
        $this->assertSame(4.9, (float) $log->old_value);
        $this->assertSame(4.7, (float) $log->new_value);
        $this->assertSame('11', $log->details['old_deductions'][0]['code']);
    }

    public function test_deleting_score_cascades_deductions(): void
    {
        $reg = $this->makeRegistration();
        $judge = $this->makeJudge('Судья A');
        $code = DeductionCode::create(['code' => '11', 'label' => 'Руки', 'value' => 0.1]);

        ScoreWriter::save($reg->competition, $reg, $judge->id, 4.9, 'A', ScoreWriter::snapshotDeductions([$code->id]));
        Score::first()->delete();

        $this->assertSame(0, ScoreDeduction::count());
    }

    public function test_snapshot_survives_deduction_code_removal(): void
    {
        $reg = $this->makeRegistration();
        $judge = $this->makeJudge('Судья A');
        $code = DeductionCode::create(['code' => '11', 'label' => 'Руки', 'value' => 0.1]);

        ScoreWriter::save($reg->competition, $reg, $judge->id, 4.9, 'A', ScoreWriter::snapshotDeductions([$code->id]));
        $code->delete();

        $d = ScoreDeduction::first();
        $this->assertNull($d->deduction_code_id);
        $this->assertSame('11', $d->code);
        $this->assertSame(0.1, (float) $d->value);
    }

    public function test_inactive_codes_are_not_snapshotted(): void
    {
        $code = DeductionCode::create(['code' => '99', 'label' => 'Старый', 'value' => 0.2, 'is_active' => false]);

        $this->assertSame([], ScoreWriter::snapshotDeductions([$code->id]));
    }

    public function test_judging_log_reason_accepts_long_text(): void
    {
        $long = str_repeat('Сбавка 11 −0.100, ', 40);

        $log = JudgingLog::record(JudgingLog::ACTION_SCORE_CREATED, ['reason' => $long]);

        $this->assertNotNull($log);
        $this->assertSame($long, $log->fresh()->reason);
    }

    // --- ХЕЛПЕРЫ ---

    private function makeJudge(string $name, bool $isActive = true, string $role = 'judge'): User
    {
        return User::create([
            'name' => $name,
            'email' => str()->random(12) . '@test.local',
            'password' => Hash::make('secret'),
            'role' => $role,
            'is_active_judge' => $isActive,
        ]);
    }

    private function makeCompetition(): Competition
    {
        return Competition::create([
            'name' => 'Тестовый турнир',
            'start_date' => '2026-02-01',
            'city' => 'Москва',
            'status_code' => 1,
        ]);
    }

    private function makeRegistration(
        array $ageGroupAttributes = [],
        ?Competition $competition = null,
        int $sortOrder = 1,
    ): Registration {
        $competition ??= $this->makeCompetition();

        $ageGroup = AgeGroup::create(array_merge([
            'name' => 'Юниоры',
            'gender' => 'male',
            'min_age' => 12,
            'max_age' => 14,
        ], $ageGroupAttributes));

        $club = Club::create(['name' => 'Клуб ' . str()->random(5)]);

        $athlete = Athlete::create([
            'club_id' => $club->id,
            'name' => 'Иванов Иван',
            'birth_date' => '2013-05-01',
            'gender' => 'male',
        ]);

        $style = Style::create(['name' => 'Чанцюань']);

        return Registration::create([
            'competition_id' => $competition->id,
            'athlete_id' => $athlete->id,
            'style_id' => $style->id,
            'age_group_id' => $ageGroup->id,
            'sort_order' => $sortOrder,
            'is_completed' => false,
        ]);
    }
}
