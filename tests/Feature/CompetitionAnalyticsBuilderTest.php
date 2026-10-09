<?php

namespace Tests\Feature;

use App\Models\AgeGroup;
use App\Models\Athlete;
use App\Models\Club;
use App\Models\Competition;
use App\Models\JudgingLog;
use App\Models\Registration;
use App\Models\Score;
use App\Models\ScoreDeduction;
use App\Models\Style;
use App\Models\User;
use App\Support\CompetitionAnalyticsBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Детерминированные метрики судейства для AI-аналитики
 * (CompetitionAnalyticsBuilder, docs/ANALYTICS.md): числа считаются без LLM,
 * методика совпадает со «Сводной таблицей оценок» (R-4.6).
 */
class CompetitionAnalyticsBuilderTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, User> бригада текущего турнира */
    private array $judges = [];

    private int $sortOrder = 0;

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

    /** Итоги, метрики судей и разбросы считаются по R-4.6. */
    public function test_totals_judge_metrics_and_spread(): void
    {
        $competition = $this->makeCompetition();
        [$reg1, $judges] = $this->makeRegistration($competition, 'Чанцюань', 'Юноши', 'Спортсмен Первый');
        [$reg2] = $this->makeRegistration($competition, 'Чанцюань', 'Юноши', 'Спортсмен Второй');

        // Выступление 1: 8.0 / 8.5 / 9.0 → trimmedMean 8.5, разброс 1.0.
        Score::create(['registration_id' => $reg1->id, 'judge_id' => $judges[0]->id, 'score' => 8.0]);
        Score::create(['registration_id' => $reg1->id, 'judge_id' => $judges[1]->id, 'score' => 8.5]);
        Score::create(['registration_id' => $reg1->id, 'judge_id' => $judges[2]->id, 'score' => 9.0]);
        $reg1->update(['final_score' => 8.5]);

        // Выступление 2: 8.0 / 8.2 / 8.4 → trimmedMean 8.2, разброс 0.4.
        Score::create(['registration_id' => $reg2->id, 'judge_id' => $judges[0]->id, 'score' => 8.0]);
        Score::create(['registration_id' => $reg2->id, 'judge_id' => $judges[1]->id, 'score' => 8.2]);
        Score::create(['registration_id' => $reg2->id, 'judge_id' => $judges[2]->id, 'score' => 8.4]);
        $reg2->update(['final_score' => 8.2]);

        $metrics = CompetitionAnalyticsBuilder::build($competition->fresh());

        $this->assertSame(2, $metrics['totals']['completed']);
        $this->assertSame(6, $metrics['totals']['scores']);
        $this->assertSame(3, $metrics['totals']['judges']);

        // Судьи отсортированы по Δ (сначала «щедрые»).
        $byName = collect($metrics['judges'])->keyBy('name');
        $third = $byName['Судья В'];
        $second = $byName['Судья Б'];
        $first = $byName['Судья А'];

        // Судья В: (0.5 + 0.2) / 2 — всегда выше итога строки.
        $this->assertSame(0.35, $third['delta']);
        // Судья А: (−0.5 + −0.2) / 2.
        $this->assertSame(-0.35, $first['delta']);
        // Судья Б: (0 + 0) / 2.
        $this->assertSame(0.0, $second['delta']);

        // Отсечения: минимум в обеих строках — у судьи А, максимум — у судьи В.
        $this->assertSame(2, $first['drop_min']);
        $this->assertSame(2, $third['drop_max']);
        $this->assertSame(0, $second['drop_min']);
        $this->assertSame(0, $second['drop_max']);

        // Разбросы: среднее (1.0 + 0.4) / 2, максимум 1.0, «≥ 0.7» — одно.
        $this->assertSame(0.7, $metrics['spread']['mean']);
        $this->assertSame(1.0, $metrics['spread']['max']);
        $this->assertSame(1, $metrics['spread']['ge_07']);
        $this->assertCount(2, $metrics['topSpreads']);
        $this->assertSame(1.0, $metrics['topSpreads'][0]['spread']);
        $this->assertSame('Спортсмен Первый', $metrics['topSpreads'][0]['athlete']);
    }

    /** Пулы: места по официальному итогу, ничья, отрыв победителя. */
    public function test_pools_rank_official_finals_with_ties(): void
    {
        $competition = $this->makeCompetition();
        [$regA, $judges] = $this->makeRegistration($competition, 'Гунь', 'Юноши', 'Победитель');
        [$regB] = $this->makeRegistration($competition, 'Гунь', 'Юноши', 'Серебро');
        [$regC] = $this->makeRegistration($competition, 'Гунь', 'Юноши', 'Бронза');

        foreach ([[$regA, 8.6], [$regB, 8.0], [$regC, 8.0]] as [$reg, $value]) {
            foreach ($judges as $judge) {
                Score::create(['registration_id' => $reg->id, 'judge_id' => $judge->id, 'score' => $value]);
            }
            $reg->update(['final_score' => $value]);
        }

        $metrics = CompetitionAnalyticsBuilder::build($competition->fresh());

        $this->assertCount(1, $metrics['pools']);
        $pool = $metrics['pools'][0];
        $this->assertSame('Гунь | Юноши', $pool['key']);
        $this->assertSame(3, $pool['n']);
        $this->assertSame(0.6, $pool['gap']);

        // 1-е место у Победителя; Серебро и Бронза — ничья (оба 2-е).
        $this->assertSame(1, $pool['athletes'][0]['place']);
        $this->assertSame('Победитель', $pool['athletes'][0]['athlete']);
        $this->assertSame(2, $pool['athletes'][1]['place']);
        $this->assertSame(2, $pool['athletes'][2]['place']);
    }

    /**
     * Правило R-4.6 меняет подиум — секция flips фиксирует это;
     * переписанный вручную итог попадает в mismatches.
     */
    public function test_flips_and_mismatches_sections(): void
    {
        $competition = $this->makeCompetition();
        [$regA, $judges] = $this->makeRegistration($competition, 'Бинци', 'Женщины', 'Первая');
        [$regB] = $this->makeRegistration($competition, 'Бинци', 'Женщины', 'Вторая');
        [$regC] = $this->makeRegistration($competition, 'Бинци', 'Женщины', 'Третья');

        // 4 оценки: trim 8.45, plain 8.375 → по trim первая, по plain — вторая.
        $this->fillScores($regA, $judges, [8.0, 8.4, 8.5, 8.6]);
        $regA->update(['final_score' => 8.45]);

        // trim 8.40, plain 8.40 → по plain первая.
        $this->fillScores($regB, $judges, [8.2, 8.3, 8.5, 8.6]);
        $regB->update(['final_score' => 8.4]);

        $this->fillScores($regC, $judges, [8.1, 8.2, 8.2, 8.3]);
        // Официальный итог переписан вручную (авто-расчёт 8.20).
        $regC->update(['final_score' => 8.0]);

        $metrics = CompetitionAnalyticsBuilder::build($competition->fresh());

        $this->assertCount(1, $metrics['flips']);
        $flip = $metrics['flips'][0];
        $this->assertSame('Бинци | Женщины', $flip['key']);
        $this->assertSame(['A1', 'A2', 'A3'], $flip['trim_top']);
        $this->assertSame(['A2', 'A1', 'A3'], $flip['plain_top']);

        $this->assertCount(1, $metrics['mismatches']);
        $mismatch = $metrics['mismatches'][0];
        $this->assertSame('Третья', $mismatch['athlete']);
        $this->assertSame(8.2, $mismatch['auto']);
        $this->assertSame(8.0, $mismatch['final']);
        $this->assertSame(-0.2, $mismatch['diff']);
    }

    /**
     * LLM-дайджест минимизирует персональные данные: спортсмены — коды A1…,
     * судьи — коды S1…; ФИО остаются только во внутренних полях для blade.
     */
    public function test_llm_digest_payload_is_coded(): void
    {
        $competition = $this->makeCompetition();
        [$reg, $judges] = $this->makeRegistration($competition, 'Чанцюань', 'Юноши', 'Иванов Иван');

        Score::create(['registration_id' => $reg->id, 'judge_id' => $judges[0]->id, 'score' => 8.0]);
        Score::create(['registration_id' => $reg->id, 'judge_id' => $judges[1]->id, 'score' => 8.2]);
        Score::create(['registration_id' => $reg->id, 'judge_id' => $judges[2]->id, 'score' => 8.4]);
        $reg->update(['final_score' => 8.2]);

        $metrics = CompetitionAnalyticsBuilder::build($competition->fresh());
        $payload = CompetitionAnalyticsBuilder::llmDigest($metrics);

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('Иванов Иван', $json);
        $this->assertStringNotContainsString('Судья А', $json);
        $this->assertStringContainsString('"S1"', $json);
        $this->assertStringContainsString('"A1"', $json);
        $this->assertArrayNotHasKey('name', $payload['judges'][0]);
        $this->assertArrayNotHasKey('athlete', $payload['pools'][0]['athletes'][0]);
    }

    /** ФИО разрешены только призёрам фокусных пулов (фокус = N ≥ 3, до 3 пулов). */
    public function test_focus_pool_winners_keep_names_in_digest(): void
    {
        $competition = $this->makeCompetition();
        [$regA, $judges] = $this->makeRegistration($competition, 'Дуаньбин', 'Мужчины', 'Лукашенко Юрий');
        [$regB] = $this->makeRegistration($competition, 'Дуаньбин', 'Мужчины', 'Аметов Сеитосман');
        [$regC] = $this->makeRegistration($competition, 'Дуаньбин', 'Мужчины', 'Килык Кирилл');

        $this->fillScores($regA, $judges, [8.3, 8.9, 9.1, 9.6]);
        $regA->update(['final_score' => 9.0]);
        $this->fillScores($regB, $judges, [8.1, 8.4, 8.5, 8.9]);
        $regB->update(['final_score' => 8.45]);
        $this->fillScores($regC, $judges, [8.2, 8.3, 8.4, 8.8]);
        $regC->update(['final_score' => 8.35]);

        $metrics = CompetitionAnalyticsBuilder::build($competition->fresh());
        $payload = CompetitionAnalyticsBuilder::llmDigest($metrics);

        $this->assertSame(['Дуаньбин | Мужчины'], $metrics['focusKeys']);

        $winners = $payload['focus_pools'][0]['athletes'];
        $this->assertSame('Лукашенко Юрий', $winners[0]['name']);
        $this->assertSame('Аметов Сеитосман', $winners[1]['name']);
        $this->assertSame('Килык Кирилл', $winners[2]['name']);
    }

    /** Турнир без завершённых выступлений: метрики нулевые, LLM не нужен. */
    public function test_build_returns_empty_metrics_for_unjudged_competition(): void
    {
        $competition = $this->makeCompetition();
        [$reg] = $this->makeRegistration($competition, 'Чанцюань', 'Юноши', 'Неоценённый');

        // Заявка без оценок и без итога — не завершена.
        $reg->update(['is_completed' => false, 'final_score' => null]);

        $metrics = CompetitionAnalyticsBuilder::build($competition->fresh());

        $this->assertSame(0, $metrics['totals']['completed']);
        $this->assertSame([], $metrics['judges']);
        $this->assertSame([], $metrics['pools']);
        $this->assertNull($metrics['spread']['mean']);
    }

    /**
     * Сценарий A/B: авто-расчёт строки = среднее A + среднее B без отбрасывания
     * крайних (R-4.18–R-4.20); код сбавки, нажатый только одним судьёй (даже
     * дважды), не считается (R-3.14). Правило R-4.6 (trimmedMean, отсечения,
     * flips) в A/B не применяется.
     */
    public function test_ab_scheme_uses_panel_sum_without_r46(): void
    {
        $competition = $this->makeCompetition(Competition::SCHEME_AB);
        [$reg, $judges] = $this->makeRegistration($competition, 'Таолу', 'Юноши', 'Иващенко Лев');

        // Судьи 0,1 — панель A; 2,3 — панель B (функция — из самой оценки).
        $s1 = Score::create(['registration_id' => $reg->id, 'judge_id' => $judges[0]->id, 'score' => 4.2, 'panel' => Competition::PANEL_A]);
        $s2 = Score::create(['registration_id' => $reg->id, 'judge_id' => $judges[1]->id, 'score' => 4.5, 'panel' => Competition::PANEL_A]);
        Score::create(['registration_id' => $reg->id, 'judge_id' => $judges[2]->id, 'score' => 3.1, 'panel' => Competition::PANEL_B]);
        Score::create(['registration_id' => $reg->id, 'judge_id' => $judges[3]->id, 'score' => 3.5, 'panel' => Competition::PANEL_B]);

        // Судья A1: 5 − (0.2+0.3+0.1+0.1+0.1) = 4.200.
        foreach ([['82', 0.2], ['75', 0.3], ['06', 0.1], ['03', 0.1], ['03', 0.1]] as [$code, $value]) {
            ScoreDeduction::create(['score_id' => $s1->id, 'code' => $code, 'label' => 'Код '.$code, 'value' => $value]);
        }
        // Судья A2: 5 − 5×0.1 = 4.500.
        foreach (['01', '02', '03', '04', '05'] as $code) {
            ScoreDeduction::create(['score_id' => $s2->id, 'code' => $code, 'label' => 'Код '.$code, 'value' => 0.1]);
        }

        // Код 03 заметили оба судьи A (у A1 — дважды) → считается; остальные
        // коды нажал только один судья → не считаются:
        // A = ((4.200 + 0.600) + (4.500 + 0.400)) / 2 = 4.850; B = 3.300; авто 8.150.
        $reg->update(['final_score' => 8.15]);

        $metrics = CompetitionAnalyticsBuilder::build($competition->fresh());

        $row = $metrics['pools'][0]['athletes'][0];
        $this->assertSame(8.15, $row['auto']);
        $this->assertSame([], $metrics['mismatches']);
        $this->assertSame([], $metrics['flips']);

        // Отсечения R-4.6 в A/B не считаются.
        foreach ($metrics['judges'] as $judge) {
            $this->assertSame(0, $judge['drop_min']);
            $this->assertSame(0, $judge['drop_max']);
        }

        // Переписанный вручную итог попадает в mismatches с авто-расчётом A/B.
        $reg->update(['final_score' => 7.65]);
        $metrics = CompetitionAnalyticsBuilder::build($competition->fresh());

        $this->assertCount(1, $metrics['mismatches']);
        $mismatch = $metrics['mismatches'][0];
        $this->assertSame(8.15, $mismatch['auto']);
        $this->assertSame(7.65, $mismatch['final']);
        $this->assertSame(-0.5, $mismatch['diff']);
    }

    /**
     * В A/B судейские сравнения — только внутри своей панели: разброс A
     * (сбавки от 5.000) и разброс B (диапазон возрастной группы, например
     * 2.000–2.500) считаются отдельно, min/max — внутри панели, Δ судьи —
     * от среднего его панели. Панели между собой не сравниваются: у судьи A
     * сбавки от 5.000, у судьи B — ограниченный диапазон из настроек
     * возрастной группы. «Спорность» строки = max(разброс A, разброс B).
     */
    public function test_ab_spreads_and_deltas_are_panel_scoped(): void
    {
        $competition = $this->makeCompetition(Competition::SCHEME_AB);
        [$reg1, $judges] = $this->makeRegistration($competition, 'Таолу', 'Юноши', 'Первый Спортсмен');
        [$reg2] = $this->makeRegistration($competition, 'Таолу', 'Юноши', 'Второй Спортсмен');

        // Выступление 1: A = 4.2 / 4.6 (разброс A = 0.4), B = 2.0 / 2.3 (0.3).
        // Между панелями «разброс» был бы 2.6 — в A/B он не считается.
        Score::create(['registration_id' => $reg1->id, 'judge_id' => $judges[0]->id, 'score' => 4.2, 'panel' => Competition::PANEL_A]);
        Score::create(['registration_id' => $reg1->id, 'judge_id' => $judges[1]->id, 'score' => 4.6, 'panel' => Competition::PANEL_A]);
        Score::create(['registration_id' => $reg1->id, 'judge_id' => $judges[2]->id, 'score' => 2.0, 'panel' => Competition::PANEL_B]);
        Score::create(['registration_id' => $reg1->id, 'judge_id' => $judges[3]->id, 'score' => 2.3, 'panel' => Competition::PANEL_B]);
        // Среднее A 4.400 + среднее B 2.150 = 6.550.
        $reg1->update(['final_score' => 6.55]);

        // Выступление 2: A = 4.3 / 4.5 (0.2), B = 2.1 / 2.2 (0.1).
        Score::create(['registration_id' => $reg2->id, 'judge_id' => $judges[0]->id, 'score' => 4.3, 'panel' => Competition::PANEL_A]);
        Score::create(['registration_id' => $reg2->id, 'judge_id' => $judges[1]->id, 'score' => 4.5, 'panel' => Competition::PANEL_A]);
        Score::create(['registration_id' => $reg2->id, 'judge_id' => $judges[2]->id, 'score' => 2.1, 'panel' => Competition::PANEL_B]);
        Score::create(['registration_id' => $reg2->id, 'judge_id' => $judges[3]->id, 'score' => 2.2, 'panel' => Competition::PANEL_B]);
        $reg2->update(['final_score' => 6.55]);

        $metrics = CompetitionAnalyticsBuilder::build($competition->fresh());

        // Спорность строки = max(разброс A, разброс B): A1 — 0.4, A2 — 0.2.
        $first = collect($metrics['topSpreads'])->firstWhere('athlete_code', 'A1');
        $this->assertSame(0.4, $first['spread']);
        $this->assertSame(0.4, $first['panels']['A']['spread']);
        $this->assertSame(0.3, $first['panels']['B']['spread']);

        // Минимум/максимум — внутри панели (не 2.0 против 4.6):
        // A: 4.2 (Судья А) … 4.6 (Судья Б); B: 2.0 (Судья В) … 2.3 (Судья Г).
        $this->assertSame(4.2, $first['panels']['A']['min_score']);
        $this->assertSame('Судья А', $first['panels']['A']['min_judge']);
        $this->assertSame(4.6, $first['panels']['A']['max_score']);
        $this->assertSame('Судья Б', $first['panels']['A']['max_judge']);
        $this->assertSame(2.0, $first['panels']['B']['min_score']);
        $this->assertSame('Судья В', $first['panels']['B']['min_judge']);
        $this->assertSame(2.3, $first['panels']['B']['max_score']);
        $this->assertSame('Судья Г', $first['panels']['B']['max_judge']);

        // Δ судьи — от среднего его панели, а не от итога строки (~6.55):
        // A (средние 4.400): Судья А −0.150, Судья Б +0.150;
        // B (средние 2.150): Судья В −0.100, Судья Г +0.100.
        $byName = collect($metrics['judges'])->keyBy('name');
        $this->assertSame('A', $byName['Судья А']['panel']);
        $this->assertSame('B', $byName['Судья В']['panel']);
        $this->assertSame(-0.15, $byName['Судья А']['delta']);
        $this->assertSame(0.15, $byName['Судья Б']['delta']);
        $this->assertSame(-0.1, $byName['Судья В']['delta']);
        $this->assertSame(0.1, $byName['Судья Г']['delta']);

        // Сводка разбросов — по каждой панели отдельно.
        $this->assertSame(0.3, $metrics['spread_panels']['A']['mean']);
        $this->assertSame(0.4, $metrics['spread_panels']['A']['max']);
        $this->assertSame(0.2, $metrics['spread_panels']['B']['mean']);
        $this->assertSame(0.3, $metrics['spread_panels']['B']['max']);
        $this->assertSame(0, $metrics['spread_panels']['A']['ge_07']);
        $this->assertSame(0, $metrics['spread_panels']['B']['ge_07']);

        // В LLM-дайджесте судьи мин/макс — кодами, внутри панели.
        $payload = CompetitionAnalyticsBuilder::llmDigest($metrics);
        $this->assertSame('S1', $payload['top_spreads'][0]['panels']['A']['min_judge']);
        $this->assertSame('S2', $payload['top_spreads'][0]['panels']['A']['max_judge']);
        $this->assertSame('S3', $payload['top_spreads'][0]['panels']['B']['min_judge']);
        $this->assertSame('S4', $payload['top_spreads'][0]['panels']['B']['max_judge']);
    }

    /**
     * «Журнал судейства» в метриках: распределение действий, ручные
     * корректировки итогов (коды спортсменов + ФИО для blade), правки оценок.
     */
    public function test_audit_section_summarizes_judging_log(): void
    {
        $competition = $this->makeCompetition();
        [$reg, $judges] = $this->makeRegistration($competition, 'Чанцюань', 'Юноши', 'Иванов Иван');

        $this->fillScores($reg, $judges, [8.0, 8.2, 8.4]);
        $reg->update(['final_score' => 8.2]);

        JudgingLog::create([
            'competition_id' => $competition->id,
            'registration_id' => $reg->id,
            'judge_id' => $judges[0]->id,
            'action' => JudgingLog::ACTION_SCORE_CREATED,
            'new_value' => 8.0,
        ]);
        JudgingLog::create([
            'competition_id' => $competition->id,
            'registration_id' => $reg->id,
            'judge_id' => $judges[1]->id,
            'action' => JudgingLog::ACTION_SCORE_UPDATED,
            'old_value' => 8.2,
            'new_value' => 8.4,
            'reason' => 'Исправление оценки',
        ]);
        JudgingLog::create([
            'competition_id' => $competition->id,
            'registration_id' => $reg->id,
            'action' => JudgingLog::ACTION_FINAL_SCORE_CHANGED,
            'old_value' => 8.2,
            'new_value' => 8.5,
            'reason' => 'Ручная корректировка итога',
        ]);
        JudgingLog::create([
            'competition_id' => $competition->id,
            'registration_id' => $reg->id,
            'action' => JudgingLog::ACTION_PROTOCOL_FINALIZED,
            'new_value' => 8.5,
        ]);

        $metrics = CompetitionAnalyticsBuilder::build($competition->fresh());
        $audit = $metrics['audit'];

        $this->assertSame(4, $audit['total']);
        $this->assertSame(1, $audit['actions'][JudgingLog::ACTION_SCORE_CREATED]);
        $this->assertSame(1, $audit['actions'][JudgingLog::ACTION_SCORE_UPDATED]);
        $this->assertSame(1, $audit['actions'][JudgingLog::ACTION_FINAL_SCORE_CHANGED]);
        $this->assertSame(1, $audit['actions'][JudgingLog::ACTION_PROTOCOL_FINALIZED]);

        // Ручная корректировка итога: код A1, значения, причина, ФИО (для blade).
        $this->assertCount(1, $audit['final_changes']);
        $change = $audit['final_changes'][0];
        $this->assertSame('A1', $change['athlete_code']);
        $this->assertSame('Иванов Иван', $change['athlete']);
        $this->assertSame(8.2, $change['old']);
        $this->assertSame(8.5, $change['new']);
        $this->assertSame('Ручная корректировка итога', $change['reason']);

        // Правки/снятия оценок: 2 события (создание + обновление).
        $this->assertCount(2, $audit['score_actions']);
    }

    /**
     * Коды сбавок (панель A): R-3.14 — код, нажатый одним судьёй (даже дважды),
     * не подтверждён; код, нажатый двумя судьями, подтверждён.
     */
    public function test_deduction_codes_respect_r314_confirmation(): void
    {
        $competition = $this->makeCompetition(Competition::SCHEME_AB);
        [$reg, $judges] = $this->makeRegistration($competition, 'Таолу', 'Юноши', 'Иващенко Лев');

        $s1 = Score::create(['registration_id' => $reg->id, 'judge_id' => $judges[0]->id, 'score' => 4.6, 'panel' => Competition::PANEL_A]);
        $s2 = Score::create(['registration_id' => $reg->id, 'judge_id' => $judges[1]->id, 'score' => 4.7, 'panel' => Competition::PANEL_A]);
        Score::create(['registration_id' => $reg->id, 'judge_id' => $judges[2]->id, 'score' => 3.3, 'panel' => Competition::PANEL_B]);

        // Код 11 нажали оба судьи A → подтверждён; код 23 нажал один судья
        // дважды → не подтверждён (R-3.14).
        ScoreDeduction::create(['score_id' => $s1->id, 'code' => '11', 'label' => 'Ошибка 11', 'value' => 0.1]);
        ScoreDeduction::create(['score_id' => $s2->id, 'code' => '11', 'label' => 'Ошибка 11', 'value' => 0.1]);
        ScoreDeduction::create(['score_id' => $s1->id, 'code' => '23', 'label' => 'Ошибка 23', 'value' => 0.1]);
        ScoreDeduction::create(['score_id' => $s1->id, 'code' => '23', 'label' => 'Ошибка 23', 'value' => 0.1]);

        $reg->update(['final_score' => 8.45]);

        $metrics = CompetitionAnalyticsBuilder::build($competition->fresh());
        $codes = collect($metrics['deductions']['codes'])->keyBy('code');

        $this->assertTrue($codes['11']['confirmed']);
        $this->assertSame(2, $codes['11']['judges']);
        $this->assertSame(2, $codes['11']['presses']);

        $this->assertFalse($codes['23']['confirmed']);
        $this->assertSame(1, $codes['23']['judges']);
        $this->assertSame(2, $codes['23']['presses']);
    }

    /**
     * LLM-дайджест содержит «Сводку оценок» (оценки по судьям + авто-расчёт)
     * и «Журнал судейства» (audit), ФИО из audit вырезаны.
     */
    public function test_llm_digest_includes_scores_audit_and_deductions(): void
    {
        $competition = $this->makeCompetition();
        [$reg, $judges] = $this->makeRegistration($competition, 'Чанцюань', 'Юноши', 'Иванов Иван');

        $this->fillScores($reg, $judges, [8.0, 8.2, 8.4]);
        $reg->update(['final_score' => 8.2]);

        JudgingLog::create([
            'competition_id' => $competition->id,
            'registration_id' => $reg->id,
            'action' => JudgingLog::ACTION_FINAL_SCORE_CHANGED,
            'old_value' => 8.2,
            'new_value' => 8.5,
            'reason' => 'Ручная корректировка итога',
        ]);

        $metrics = CompetitionAnalyticsBuilder::build($competition->fresh());
        $payload = CompetitionAnalyticsBuilder::llmDigest($metrics);

        // «Сводка оценок»: по-судейские оценки и авто-расчёт в каждом выступлении.
        $athlete = $payload['pools'][0]['athletes'][0];
        $this->assertSame(['S1' => 8.0, 'S2' => 8.2, 'S3' => 8.4], $athlete['scores']);
        $this->assertSame(8.2, $athlete['auto']);

        // «Журнал судейства» в дайджесте, без ФИО.
        $this->assertSame(1, $payload['audit']['total']);
        $this->assertCount(1, $payload['audit']['final_changes']);
        $change = $payload['audit']['final_changes'][0];
        $this->assertSame('A1', $change['athlete_code']);
        $this->assertArrayNotHasKey('athlete', $change);
        $this->assertSame('Ручная корректировка итога', $change['reason']);

        // Секция кодов сбавок присутствует в дайджесте.
        $this->assertArrayHasKey('codes', $payload['deductions']);

        // ФИО не утекают в JSON дайджеста.
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Иванов Иван', $json);
    }

    private function makeCompetition(string $scheme = Competition::SCHEME_SIMPLE): Competition
    {
        $competition = Competition::create([
            'name' => 'AI-аналитика турнир',
            'start_date' => '2026-03-01',
            'city' => 'Москва',
            'status_code' => 3,
            'judging_scheme' => $scheme,
        ]);

        // Бригада общая для турнира (как на сервере — 6 судей на все выступления).
        $this->judges = [];
        foreach (['Судья А', 'Судья Б', 'Судья В', 'Судья Г'] as $name) {
            $judge = User::create([
                'name' => $name,
                'email' => str()->random(8).'@test.local',
                'password' => Hash::make('secret'),
                'role' => 'judge',
                'is_active_judge' => true,
            ]);
            $competition->judges()->attach($judge->id);
            $this->judges[] = $judge;
        }

        return $competition;
    }

    /**
     * Заявка в пуле (дисциплина × возрастная группа).
     *
     * @return array{0: Registration, 1: array<int, User>}
     */
    private function makeRegistration(Competition $competition, string $styleName, string $groupName, string $athleteName): array
    {
        $this->sortOrder++;

        $gender = str_contains($groupName, 'Жен') || str_contains($groupName, 'Дев') ? 'female' : 'male';
        $group = AgeGroup::firstOrCreate(
            ['name' => $groupName, 'gender' => $gender, 'min_age' => 12, 'max_age' => 14],
        );
        $style = Style::firstOrCreate(['name' => $styleName], ['sort_order' => $this->sortOrder]);
        $club = Club::firstOrCreate(['name' => 'Клуб аналитики']);
        $athlete = Athlete::create([
            'club_id' => $club->id,
            'name' => $athleteName,
            'birth_date' => '2013-05-01',
            'gender' => $gender,
        ]);

        $reg = Registration::create([
            'competition_id' => $competition->id,
            'athlete_id' => $athlete->id,
            'style_id' => $style->id,
            'age_group_id' => $group->id,
            'sort_order' => $this->sortOrder,
            'is_completed' => true,
        ]);

        return [$reg, $this->judges];
    }

    /**
     * @param  array<int, User>  $judges
     * @param  array<int, float>  $values
     */
    private function fillScores(Registration $reg, array $judges, array $values): void
    {
        foreach ($values as $i => $value) {
            Score::create([
                'registration_id' => $reg->id,
                'judge_id' => $judges[$i]->id,
                'score' => $value,
            ]);
        }
    }
}
