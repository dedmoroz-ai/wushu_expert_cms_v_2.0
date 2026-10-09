<?php

namespace App\Support;

use App\Models\Competition;
use App\Models\JudgingLog;
use App\Models\Registration;
use Illuminate\Support\Collection;

/**
 * Детерминированные метрики судейства турнира для аналитического отчёта
 * (AI-аналитика по запросу, docs/ANALYTICS.md).
 *
 * Все числа считаются здесь, без LLM; текст дайджеста пишет модель по этим
 * числам. Методика совпадает со «Сводной таблицей оценок»:
 *  - простая схема: итог строки = trimmedMean (R-4.6, JudgingCalculator);
 *  - сценарий A/B (R-4.18–R-4.20): итог строки = среднее A + среднее B,
 *    крайние оценки не отбрасываются (R-4.19), коды сбавок подтверждаются
 *    несколькими судьями (R-3.14); отсечения R-4.6 не считаются;
 *  - Δ судьи = среднее (оценка судьи − среднее группы судьи) — систематическая
 *    строгость/щедрость: в простой схеме группа = все судьи (итог строки),
 *    в A/B — только панель судьи (панели несопоставимы: A ставит сбавки
 *    от 5.000, B оценивает в диапазоне из настроек возрастной группы,
 *    например 2.000–2.500);
 *  - разброс выступления = максимум − минимум оценок внутри группы судей:
 *    в A/B — отдельно по каждой панели (A отдельно, B отдельно), «спорность»
 *    строки = max(разброс A, разброс B); в простой — по всем судьям;
 *  - пул = дисциплина × возрастная группа.
 *
 * Персональные данные минимизируются: в LLM-дайджест спортсмены попадают
 * кодами (A1…), судьи — кодами (S1…), ФИО разрешены только для призёров
 * фокусных пулов (см. focusPools()). Полные таблицы рендерит blade.
 */
class CompetitionAnalyticsBuilder
{
    /** Минимум выступлений, чтобы пул считался содержательным. */
    public const POOL_MIN_N = 3;

    /** Сколько «спорных» пулов попадает в LLM-дайджест с ФИО. */
    public const FOCUS_POOLS = 3;

    /**
     * @return array{
     *     competition: array<string, mixed>,
     *     totals: array<string, int>,
     *     judges: array<int, array<string, mixed>>,
     *     spread: array<string, float|null>,
     *     spread_panels: array<string, array<string, float|int|null>>,
     *     topSpreads: array<int, array<string, mixed>>,
     *     pools: array<int, array<string, mixed>>,
     *     flips: array<int, array<string, mixed>>,
     *     mismatches: array<int, array<string, mixed>>,
     *     audit: array<string, mixed>,
     *     deductions: array{codes: array<int, array<string, mixed>>},
     *     focusKeys: array<int, string>,
     * }|null
     */
    public static function build(?Competition $competition): ?array
    {
        if (! $competition) {
            return null;
        }

        [$registrations, $scoresByReg, $judgeNames, $extrasByReg] = self::load($competition);
        $isAb = $competition->isAbScheme();

        $rows = [];
        $judgeScores = [];
        $judgePanel = [];
        $judgeDelta = [];
        $judgeDropMin = [];
        $judgeDropMax = [];

        foreach ($registrations as $reg) {
            $values = $scoresByReg[$reg->id] ?? [];
            $extras = $extrasByReg[$reg->id] ?? [];
            $panels = self::rowPanels($isAb, $values, $extras);
            $auto = self::rowTotal($isAb, $panels);

            // «Спорность» строки: в A/B — max(разброс A, разброс B); панели
            // между собой не сравниваются (разные шкалы).
            $spread = self::rowSpread($panels);

            // Отсечения R-4.6 есть только в простой схеме: в A/B крайние
            // оценки не отбрасываются, отсечений не бывает.
            if (! $isAb && $values !== [] && count($values) >= 3) {
                $min = min($values);
                $max = max($values);
                foreach ($values as $code => $value) {
                    if ($value === $min) {
                        $judgeDropMin[$code] = ($judgeDropMin[$code] ?? 0) + 1;
                    }
                    if ($value === $max) {
                        $judgeDropMax[$code] = ($judgeDropMax[$code] ?? 0) + 1;
                    }
                }
            }

            foreach ($values as $code => $value) {
                $judgeScores[$code][] = $value;
                $judgePanel[$code] ??= $extras[$code]['panel'] ?? ScoresSummaryMatrix::GROUP_NONE;

                // Δ — от среднего группы судьи: в простой от итога строки
                // (trimmedMean = среднее группы «none»), в A/B — от среднего
                // его панели и только по «своим» оценкам (R-4.18). Оценки без
                // своей панели в A/B в сравнения не входят.
                $groupKey = $isAb ? ($extras[$code]['panel'] ?? null) : ScoresSummaryMatrix::GROUP_NONE;
                $avg = $groupKey === null ? null : ($panels[$groupKey]['avg'] ?? null);
                if ($avg !== null && (! $isAb || ($extras[$code]['counted'] ?? false))) {
                    $judgeDelta[$code][] = $value - $avg;
                }
            }

            $rows[] = [
                'code' => 'A'.(count($rows) + 1),
                'reg' => $reg,
                'scores' => $values,
                'auto' => $auto,
                'final' => $reg->final_score !== null ? (float) $reg->final_score : null,
                'spread' => $spread,
                'panels' => $panels,
            ];
        }

        $judges = self::judgesSection($judgeNames, $judgePanel, $judgeScores, $judgeDelta, $judgeDropMin, $judgeDropMax);
        [$spreadStats, $spreadPanelStats, $topSpreads] = self::spreadsSection($rows, $judgeNames);
        $pools = self::poolsSection($rows, $judgeNames);
        // Влияние R-4.6 на топ-3 — только простая схема; в A/B правила другие.
        $flips = $isAb ? [] : self::flipsSection($pools);
        $mismatches = self::mismatchesSection($rows);
        // Журнал судейства (аудит) и снимок кодов сбавок — источники
        // «Журнал судейства» и «Сводка оценок» для аналитики.
        $audit = self::auditSection($competition, $rows);
        $deductions = self::deductionsSection($rows);

        return [
            'competition' => [
                'id' => $competition->id,
                'name' => $competition->name,
                'date' => $competition->start_date?->format('Y-m-d'),
                'city' => $competition->city,
                'scheme' => $competition->judgingScheme(),
            ],
            'totals' => [
                'registrations' => $registrations->count(),
                'completed' => count($rows),
                'scores' => array_sum(array_map('count', $scoresByReg)),
                'judges' => count($judgeNames),
                'pools' => count($pools),
            ],
            'judges' => $judges,
            'spread' => $spreadStats,
            'spread_panels' => $spreadPanelStats,
            'topSpreads' => $topSpreads,
            'pools' => $pools,
            'flips' => $flips,
            'mismatches' => $mismatches,
            'audit' => $audit,
            'deductions' => $deductions,
            'focusKeys' => self::focusKeys($pools),
        ];
    }

    /**
     * Данные турнира: завершённые заявки (как в ScoresSummaryMatrix), оценки
     * по судьям (код => оценка), имена судей (код => имя) и доп. данные оценок
     * (код => панель судьи, засчитывается ли оценка, снимок сбавок) для
     * авто-расчёта в сценарии A/B.
     *
     * @return array{
     *     0: Collection<int, Registration>,
     *     1: array<int, array<string, float>>,
     *     2: array<string, string>,
     *     3: array<int, array<string, array{panel: string, counted: bool, deductions: array<int, array{code: string, value: float}>}>>,
     * }
     */
    private static function load(Competition $competition): array
    {
        $registrations = Registration::query()
            ->where('competition_id', $competition->id)
            ->where('is_completed', true)
            ->with(['athlete', 'athlete.club', 'partner', 'style', 'ageGroup', 'scores.judge', 'scores.deductions'])
            ->join('styles', 'registrations.style_id', '=', 'styles.id')
            ->join('age_groups', 'registrations.age_group_id', '=', 'age_groups.id')
            ->join('athletes', 'registrations.athlete_id', '=', 'athletes.id')
            ->orderBy('styles.sort_order')
            ->orderBy('age_groups.sort_order')
            ->orderBy('athletes.gender')
            ->orderBy('registrations.sort_order')
            ->select('registrations.*')
            ->get();

        // Коды судей — в порядке первого появления (стабильны между запусками).
        $judgeIds = [];
        foreach ($registrations as $reg) {
            foreach ($reg->scores as $score) {
                if (! isset($judgeIds[$score->judge_id])) {
                    $judgeIds[$score->judge_id] = true;
                }
            }
        }

        // judge_id => код S1…; код => ФИО.
        $codes = [];
        $nameByCode = [];
        $i = 0;
        foreach (array_keys($judgeIds) as $jid) {
            $i++;
            $code = 'S'.$i;
            $codes[$jid] = $code;

            $name = null;
            foreach ($registrations as $reg) {
                $judge = $reg->scores->firstWhere('judge_id', $jid)?->judge;
                if ($judge) {
                    $name = $judge->name;
                    break;
                }
            }
            $nameByCode[$code] = $name ?? ('Судья #'.$jid);
        }

        // Панель каждого судьи (A/B) — как в ScoresSummaryMatrix: назначение
        // в бригаде, иначе — функция из его оценок.
        $judgesById = [];
        foreach ($registrations as $reg) {
            foreach ($reg->scores as $score) {
                if ($score->judge && ! isset($judgesById[$score->judge_id])) {
                    $judgesById[$score->judge_id] = $score->judge;
                }
            }
        }

        $isAb = $competition->isAbScheme();
        $judgePanels = ScoresSummaryMatrix::resolveJudgePanels($competition, $judgesById, $registrations, $isAb);

        // Оценки: judge_id => значение перенумеровываем в код => значение.
        $scoresByReg = [];
        $extrasByReg = [];
        foreach ($registrations as $reg) {
            $values = [];
            $extras = [];
            foreach ($reg->scores as $score) {
                $code = $codes[$score->judge_id];
                $values[$code] = (float) $score->score;

                $panel = $isAb
                    ? ($judgePanels[$score->judge_id] ?? ScoresSummaryMatrix::GROUP_NONE)
                    : ScoresSummaryMatrix::GROUP_NONE;
                $scorePanel = $score->panel;

                $extras[$code] = [
                    'panel' => $panel,
                    // Правило R-4.18: в A/B засчитывается оценка, выставленная
                    // в своей функции (scores.panel совпадает с назначением).
                    'counted' => ! $isAb || $scorePanel === null || $scorePanel === $panel,
                    'deductions' => $score->deductions
                        ->map(fn ($d) => ['code' => (string) $d->code, 'value' => (float) $d->value])
                        ->values()
                        ->all(),
                ];
            }
            if ($values !== []) {
                $scoresByReg[$reg->id] = $values;
                $extrasByReg[$reg->id] = $extras;
            }
        }

        return [$registrations, $scoresByReg, $nameByCode, $extrasByReg];
    }

    /**
     * Судейские сравнения внутри группы (панели).
     *
     * В A/B панель A (сбавки от 5.000) и панель B (диапазон из настроек
     * возрастной группы, например 2.000–2.500) — разные шкалы, поэтому
     * разброс, минимум/максимум и база Δ считаются только среди судей одной
     * панели: A отдельно, B отдельно. В простой схеме все судьи в одной
     * группе «none».
     *
     * Разброс/мин/макс — по сохранённым оценкам судей (как в ячейках
     * «Сводной таблицы оценок»); база Δ — «среднее группы» той же сводки:
     * A — среднее A с подтверждением кодов сбавок (R-3.14), B — среднее B
     * (R-4.19), простой — trimmedMean (R-4.6).
     *
     * Оценки, выставленные не в своей функции (R-4.18), и оценки судей без
     * панели в сравнения не входят.
     *
     * @param  array<string, float>  $values  код судьи => оценка
     * @param  array<string, array{panel: string, counted: bool, deductions: array<int, array{code: string, value: float}>}>  $extras
     * @return array<string, array{key: string, spread: float|null, min_code: string|null, min_score: float|null, max_code: string|null, max_score: float|null, avg: float|null}>
     */
    private static function rowPanels(bool $isAb, array $values, array $extras): array
    {
        if (! $isAb) {
            $avg = JudgingCalculator::trimmedMean(array_values($values))['avg'];

            return [ScoresSummaryMatrix::GROUP_NONE => self::groupBlock(ScoresSummaryMatrix::GROUP_NONE, $values, $avg)];
        }

        $storedA = [];
        $storedB = [];
        $judgeScoresA = [];
        $valuesB = [];

        foreach ($values as $code => $value) {
            $extra = $extras[$code] ?? null;
            if ($extra === null || ! $extra['counted']) {
                continue;
            }
            if ($extra['panel'] === Competition::PANEL_A) {
                $storedA[$code] = $value;
                $judgeScoresA[$code] = ['score' => $value, 'deductions' => $extra['deductions']];
            } elseif ($extra['panel'] === Competition::PANEL_B) {
                $storedB[$code] = $value;
                $valuesB[] = $value;
            }
        }

        $avgA = $judgeScoresA === []
            ? null
            : JudgingCalculator::panelMean(JudgingCalculator::confirmedPanelScores($judgeScoresA)['scores'])['avg'];
        $avgB = $valuesB === [] ? null : JudgingCalculator::panelMean($valuesB)['avg'];

        return [
            Competition::PANEL_A => self::groupBlock(Competition::PANEL_A, $storedA, $avgA),
            Competition::PANEL_B => self::groupBlock(Competition::PANEL_B, $storedB, $avgB),
        ];
    }

    /**
     * Блок группы: разброс (max − min внутри группы), судьи минимума/максимума
     * и среднее группы (база Δ).
     *
     * @param  array<string, float>  $values  код судьи => оценка
     * @return array{key: string, spread: float|null, min_code: string|null, min_score: float|null, max_code: string|null, max_score: float|null, avg: float|null}
     */
    private static function groupBlock(string $key, array $values, ?float $avg): array
    {
        $minCode = $values === [] ? null : self::extremeJudge($values, 'min');
        $maxCode = $values === [] ? null : self::extremeJudge($values, 'max');

        return [
            'key' => $key,
            'spread' => count($values) >= 2
                ? round(max($values) - min($values), ScoreRange::PRECISION)
                : null,
            'min_code' => $minCode,
            'min_score' => $minCode === null ? null : $values[$minCode],
            'max_code' => $maxCode,
            'max_score' => $maxCode === null ? null : $values[$maxCode],
            'avg' => $avg,
        ];
    }

    /**
     * Авто-расчёт итога строки по сценарию турнира.
     *
     *  - простая схема: trimmedMean (R-4.6);
     *  - A/B: среднее A (оценки с подтверждением кодов сбавок R-3.14) +
     *    среднее B (R-4.20), крайние не отбрасываются (R-4.19).
     *
     * @param  array<string, array{key: string, spread: float|null, min_code: string|null, min_score: float|null, max_code: string|null, max_score: float|null, avg: float|null}>  $panels
     */
    private static function rowTotal(bool $isAb, array $panels): ?float
    {
        if (! $isAb) {
            return $panels[ScoresSummaryMatrix::GROUP_NONE]['avg'] ?? null;
        }

        $avgA = $panels[Competition::PANEL_A]['avg'] ?? null;
        $avgB = $panels[Competition::PANEL_B]['avg'] ?? null;

        return ($avgA !== null && $avgB !== null)
            ? JudgingCalculator::abTotal($avgA, $avgB)
            : null;
    }

    /**
     * «Спорность» строки: в простой — разброс по всем судьям; в A/B —
     * max(разброс A, разброс B) — только для упорядочивания спорных
     * выступлений, сами разбросы панелей между собой не сравниваются.
     *
     * @param  array<string, array{spread: float|null}>  $panels
     */
    private static function rowSpread(array $panels): ?float
    {
        $spreads = array_values(array_filter(
            array_map(fn (array $block) => $block['spread'], $panels),
            fn ($value) => $value !== null,
        ));

        return $spreads === [] ? null : round(max($spreads), ScoreRange::PRECISION);
    }

    /**
     * Секция по судьям: средний балл, Δ, СКО Δ, отсечения, диапазон.
     *
     * Сортировка: сначала по панели (A, B, без функции), внутри — по Δ
     * (сначала «щедрые»): в A/B строгость судей сравнивается только внутри
     * панели (шкалы A и B несопоставимы), в простой схеме — по Δ среди всех.
     *
     * @param  array<string, string>  $judgeNames  код => ФИО
     * @param  array<string, string>  $judgePanel  код => панель (A/B/none)
     * @param  array<string, array<int, float>>  $judgeScores
     * @param  array<string, array<int, float>>  $judgeDelta
     * @param  array<string, int>  $judgeDropMin
     * @param  array<string, int>  $judgeDropMax
     * @return array<int, array<string, mixed>>
     */
    private static function judgesSection(
        array $judgeNames,
        array $judgePanel,
        array $judgeScores,
        array $judgeDelta,
        array $judgeDropMin,
        array $judgeDropMax,
    ): array {
        $judges = [];
        foreach ($judgeScores as $code => $values) {
            $judges[] = [
                'code' => $code,
                'name' => $judgeNames[$code],
                'panel' => $judgePanel[$code] ?? ScoresSummaryMatrix::GROUP_NONE,
                'n' => count($values),
                'mean' => self::round3(self::mean($values)),
                'std' => self::round3(self::std($values)),
                'delta' => self::round3(self::mean($judgeDelta[$code] ?? [])),
                'delta_std' => self::round3(self::std($judgeDelta[$code] ?? [])),
                'drop_min' => $judgeDropMin[$code] ?? 0,
                'drop_max' => $judgeDropMax[$code] ?? 0,
                'min' => min($values),
                'max' => max($values),
            ];
        }

        $order = [
            Competition::PANEL_A => 0,
            Competition::PANEL_B => 1,
            ScoresSummaryMatrix::GROUP_NONE => 2,
        ];

        usort($judges, fn (array $a, array $b) => ($order[$a['panel']] ?? 9) <=> ($order[$b['panel']] ?? 9)
            ?: $b['delta'] <=> $a['delta']);

        return $judges;
    }

    /** Среднее (или null для пустого набора). */
    private static function mean(array $values): ?float
    {
        return $values === [] ? null : array_sum($values) / count($values);
    }

    /** СКО по генеральной совокупности (как в разовой аналитике). */
    private static function std(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        $m = self::mean($values);
        $sum = 0.0;
        foreach ($values as $value) {
            $sum += ($value - $m) ** 2;
        }

        return sqrt($sum / count($values));
    }

    private static function round3(?float $value): ?float
    {
        return $value === null ? null : round($value, ScoreRange::PRECISION);
    }

    /**
     * Разбросы оценок: сводка + топ-15 «спорных» выступлений (коды спортсменов).
     *
     * В A/B разбросы считаются внутри панели (A отдельно, B отдельно — шкалы
     * несопоставимы), поэтому сводка считается и по каждой панели
     * (spread_panels), а топ упорядочен по max(разброс A, разброс B);
     * минимум/максимум в строках — всегда внутри одной панели.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, string>  $judgeNames  код => ФИО
     * @return array{0: array<string, float|int|null>, 1: array<string, array<string, float|int|null>>, 2: array<int, array<string, mixed>>}
     */
    private static function spreadsSection(array $rows, array $judgeNames): array
    {
        $spreads = [];
        foreach ($rows as $idx => $row) {
            if ($row['spread'] !== null) {
                $spreads[] = ['idx' => $idx, 'spread' => $row['spread']];
            }
        }

        usort($spreads, fn (array $a, array $b) => $b['spread'] <=> $a['spread']);

        $stats = self::spreadStats(array_column($spreads, 'spread'));

        // Сводка по каждой группе судей (панели A/B или все судьи в простой).
        $panelStats = [];
        $byPanel = [];
        foreach ($rows as $row) {
            foreach ($row['panels'] as $key => $block) {
                if ($block['spread'] !== null) {
                    $byPanel[$key][] = $block['spread'];
                }
            }
        }
        foreach ($byPanel as $key => $values) {
            $panelStats[$key] = self::spreadStats($values);
        }

        $top = [];
        foreach (array_slice($spreads, 0, 15) as $item) {
            $row = $rows[$item['idx']];
            $top[] = [
                'athlete_code' => $row['code'],
                'athlete' => self::athleteName($row['reg']),
                'style' => (string) ($row['reg']->style?->name ?? '—'),
                'age_group' => (string) ($row['reg']->ageGroup?->name ?? '—'),
                'spread' => $item['spread'],
                'panels' => self::panelsForDisplay($row['panels'], $judgeNames),
                'final' => $row['final'],
            ];
        }

        return [$stats, $panelStats, $top];
    }

    /**
     * Сводка по разбросам набора строк: среднее, медиана, максимум, сколько ≥ 0,7.
     *
     * @param  array<int, float>  $values
     * @return array<string, float|int|null>
     */
    private static function spreadStats(array $values): array
    {
        $sorted = $values;
        sort($sorted);

        return [
            'mean' => self::round3(self::mean($values)),
            'median' => $sorted === [] ? null : self::round3($sorted[(int) floor((count($sorted) - 1) / 2)]),
            'max' => $values === [] ? null : max($values),
            'ge_07' => count(array_filter($values, fn ($v) => $v >= 0.7)),
        ];
    }

    /**
     * Блоки групп (панелей) для blade и LLM-дайджеста: коды судей → ФИО
     * (в llmDigest ФИО обратно переносятся в коды S1…).
     *
     * @param  array<string, array<string, mixed>>  $panels  rowPanels()
     * @param  array<string, string>  $judgeNames  код => ФИО
     * @return array<string, array<string, mixed>>
     */
    private static function panelsForDisplay(array $panels, array $judgeNames): array
    {
        $display = [];
        foreach ($panels as $key => $block) {
            $display[$key] = [
                'key' => $key,
                'label' => $key === ScoresSummaryMatrix::GROUP_NONE ? '' : $key,
                'spread' => $block['spread'],
                'min_score' => $block['min_score'],
                'min_judge' => $block['min_code'] === null ? null : ($judgeNames[$block['min_code']] ?? null),
                'max_score' => $block['max_score'],
                'max_judge' => $block['max_code'] === null ? null : ($judgeNames[$block['max_code']] ?? null),
            ];
        }

        return $display;
    }

    /**
     * Код судьи с минимальной/максимальной оценкой (первый в порядке кодов).
     *
     * @param  array<string, float>  $scores
     */
    private static function extremeJudge(array $scores, string $kind): ?string
    {
        $target = $kind === 'min' ? min($scores) : max($scores);
        ksort($scores);
        foreach ($scores as $code => $value) {
            if ($value === $target) {
                return $code;
            }
        }

        return null;
    }

    private static function athleteName(Registration $reg): string
    {
        $name = trim((string) $reg->athlete?->name);

        return $name !== '' ? $name : ('Участник #'.$reg->id);
    }

    /**
     * Пулы: дисциплина × возрастная группа. Места — по официальному итогу
     * протокола (final_score), как в протоколах и дипломах.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, string>  $judgeNames  код => ФИО
     * @return array<int, array<string, mixed>>
     */
    private static function poolsSection(array $rows, array $judgeNames): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $reg = $row['reg'];
            $key = ($reg->style?->name ?? '—').' | '.($reg->ageGroup?->name ?? '—');
            $groups[$key][] = $row;
        }

        $pools = [];
        foreach ($groups as $key => $group) {
            usort($group, fn (array $a, array $b) => ($b['final'] ?? -1) <=> ($a['final'] ?? -1));

            $athletes = [];
            $place = 0;
            $prevFinal = null;
            foreach ($group as $row) {
                $place++;
                if ($prevFinal !== null && $row['final'] !== null
                    && abs($prevFinal - $row['final']) <= 0.0005) {
                    $place--;
                }
                $prevFinal = $row['final'];

                $scores = $row['scores'];

                $athletes[] = [
                    'place' => $place,
                    'athlete_code' => $row['code'],
                    'athlete' => self::athleteName($row['reg']),
                    'final' => $row['final'],
                    'auto' => $row['auto'],
                    'spread' => $row['spread'],
                    'panels' => self::panelsForDisplay($row['panels'], $judgeNames),
                    'scores' => $scores,
                ];
            }

            $finals = array_values(array_filter(array_column($athletes, 'final'), fn ($v) => $v !== null));
            $spreads = array_values(array_filter(array_column($athletes, 'spread'), fn ($v) => $v !== null));

            // Средний разброс по каждой группе судей (панели в A/B).
            $meanSpreadPanels = [];
            foreach (array_keys($athletes[0]['panels'] ?? []) as $panelKey) {
                $values = [];
                foreach ($athletes as $athlete) {
                    $value = $athlete['panels'][$panelKey]['spread'] ?? null;
                    if ($value !== null) {
                        $values[] = $value;
                    }
                }
                $meanSpreadPanels[$panelKey] = self::round3(self::mean($values));
            }

            $gap = null;
            if (count($finals) >= 2) {
                $gap = round($finals[0] - $finals[1], 4);
            }

            $pools[] = [
                'key' => $key,
                'style' => ($group[0]['reg']->style?->name ?? '—'),
                'age_group' => ($group[0]['reg']->ageGroup?->name ?? '—'),
                'n' => count($group),
                'mean_final' => self::round3(self::mean($finals)),
                'std_final' => self::round3(self::std($finals)),
                'min_final' => $finals === [] ? null : min($finals),
                'max_final' => $finals === [] ? null : max($finals),
                'mean_spread' => self::round3(self::mean($spreads)),
                'mean_spread_panels' => $meanSpreadPanels,
                'gap' => $gap,
                'athletes' => $athletes,
            ];
        }

        usort($pools, fn (array $a, array $b) => $b['n'] <=> $a['n'] ?: strcmp($a['key'], $b['key']));

        return $pools;
    }

    /**
     * Влияние правила R-4.6 (только простая схема; в A/B отсечения крайних
     * нет — раздел вызывается только когда $isAb = false): пулы с N ≥ POOL_MIN_N, где подиум (топ-3) при
     * среднем по всем оценкам отличался бы от итогового по trimmedMean.
     *
     * @param  array<int, array<string, mixed>>  $pools
     * @return array<int, array<string, mixed>>
     */
    private static function flipsSection(array $pools): array
    {
        $flips = [];

        foreach ($pools as $pool) {
            if ($pool['n'] < self::POOL_MIN_N) {
                continue;
            }

            $byTrim = $pool['athletes'];
            usort($byTrim, fn (array $a, array $b) => ($b['auto'] ?? -1) <=> ($a['auto'] ?? -1));

            $byPlain = $pool['athletes'];
            usort($byPlain, function (array $a, array $b) {
                return self::plainMean($b['scores']) <=> self::plainMean($a['scores']);
            });

            $trimTop = array_slice(array_column($byTrim, 'athlete_code'), 0, 3);
            $plainTop = array_slice(array_column($byPlain, 'athlete_code'), 0, 3);

            if ($trimTop !== $plainTop) {
                $flips[] = [
                    'key' => $pool['key'],
                    'trim_top' => $trimTop,
                    'plain_top' => $plainTop,
                ];
            }
        }

        return $flips;
    }

    /** Среднее по всем оценкам без отбрасывания (для сравнения с R-4.6). */
    private static function plainMean(array $scores): ?float
    {
        return $scores === [] ? null : array_sum($scores) / count($scores);
    }

    /**
     * Выступления, где официальный итог заметно отличается от авто-расчёта
     * по правилам схемы турнира (R-4.6 для простой, среднее A + среднее B для
     * A/B): переписан старшим судьёй или сохранён с нестандартной точностью.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private static function mismatchesSection(array $rows): array
    {
        $mismatches = [];

        foreach ($rows as $row) {
            if ($row['auto'] === null || $row['final'] === null) {
                continue;
            }

            $diff = round($row['final'] - $row['auto'], 4);
            if (abs($diff) <= 0.0005) {
                continue;
            }

            $mismatches[] = [
                'athlete_code' => $row['code'],
                'athlete' => self::athleteName($row['reg']),
                'style' => (string) ($row['reg']->style?->name ?? '—'),
                'age_group' => (string) ($row['reg']->ageGroup?->name ?? '—'),
                'auto' => $row['auto'],
                'final' => $row['final'],
                'diff' => $diff,
            ];
        }

        usort($mismatches, fn (array $a, array $b) => abs($b['diff']) <=> abs($a['diff']));

        return $mismatches;
    }

    /**
     * Журнал судейства (правило 8.9 / R-6.13): сводка действий и значимые
     * события для аналитики. Полные таблицы рендерит blade; в LLM уходит
     * кодированный дайджест (llmDigest) без ФИО.
     *
     * Учитываются:
     *  - распределение действий (score_created/updated/deleted,
     *    final_score_changed, protocol_finalized);
     *  - ручные корректировки итогового балла (final_score_changed) с
     *    причинами и кодами спортсменов;
     *  - правки и снятия оценок (score_updated/updated, score_deleted) —
     *    признак нестабильности ввода и перевыставлений.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private static function auditSection(Competition $competition, array $rows): array
    {
        // Коды спортсменов по registration_id (коды стабильны между запусками).
        $codeByReg = [];
        $styleByReg = [];
        $groupByReg = [];
        foreach ($rows as $row) {
            $reg = $row['reg'];
            $codeByReg[$reg->id] = $row['code'];
            $styleByReg[$reg->id] = (string) ($reg->style?->name ?? '—');
            $groupByReg[$reg->id] = (string) ($reg->ageGroup?->name ?? '—');
        }

        $logs = JudgingLog::query()
            ->where('competition_id', $competition->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $actions = [];
        $finalChanges = [];
        $scoreActions = [];

        foreach ($logs as $log) {
            $action = (string) $log->action;
            $actions[$action] = ($actions[$action] ?? 0) + 1;

            if ($action === JudgingLog::ACTION_FINAL_SCORE_CHANGED) {
                $code = $codeByReg[$log->registration_id] ?? null;
                $finalChanges[] = [
                    'athlete_code' => $code,
                    // ФИО — только для blade-таблицы; в LLM-дайджест не попадает
                    // (llmDigest удаляет ключ 'athlete').
                    'athlete' => $code === null ? null : self::athleteNameByReg($rows, $log->registration_id),
                    'style' => $code === null ? null : $styleByReg[$log->registration_id],
                    'age_group' => $code === null ? null : $groupByReg[$log->registration_id],
                    'old' => $log->old_value === null ? null : (float) $log->old_value,
                    'new' => $log->new_value === null ? null : (float) $log->new_value,
                    'reason' => $log->reason !== null ? trim((string) $log->reason) : null,
                    'at' => $log->created_at?->format('d.m.Y H:i'),
                ];
            }

            if (in_array($action, [
                JudgingLog::ACTION_SCORE_CREATED,
                JudgingLog::ACTION_SCORE_UPDATED,
                JudgingLog::ACTION_SCORE_DELETED,
            ], true)) {
                $scoreActions[] = [
                    'action' => $action,
                    'athlete_code' => $codeByReg[$log->registration_id] ?? null,
                    'old' => $log->old_value === null ? null : (float) $log->old_value,
                    'new' => $log->new_value === null ? null : (float) $log->new_value,
                    'reason' => $log->reason !== null ? trim((string) $log->reason) : null,
                ];
            }
        }

        return [
            'total' => $logs->count(),
            'actions' => $actions,
            'final_changes' => $finalChanges,
            'score_actions' => $scoreActions,
        ];
    }

    /**
     * ФИО спортсмена по registration_id (для blade-таблиц журнала).
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private static function athleteNameByReg(array $rows, ?int $registrationId): ?string
    {
        foreach ($rows as $row) {
            if ($row['reg']->id === $registrationId) {
                return self::athleteName($row['reg']);
            }
        }

        return null;
    }

    /**
     * Снимок кодов сбавок панели A (правила R-3.12 / R-3.14) для
     * «Сводки оценок» и LLM-дайджеста.
     *
     * Код, нажатый только одним судьёй (даже дважды), в вычет не идёт
     * (R-3.14, MIN_CODE_JUDGES = 2) — такие коды помечены confirmed = false;
     * их сумма видна как разница value_sum учтённых и неучтённых кодов.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{codes: array<int, array<string, mixed>>}
     */
    private static function deductionsSection(array $rows): array
    {
        // code => [presses, value_sum, label, judges[judge_id => true]]
        $byCode = [];

        foreach ($rows as $row) {
            foreach ($row['reg']->scores as $score) {
                foreach ($score->deductions as $deduction) {
                    $code = (string) $deduction->code;
                    $byCode[$code]['presses'] = ($byCode[$code]['presses'] ?? 0) + 1;
                    $byCode[$code]['value_sum'] = ($byCode[$code]['value_sum'] ?? 0.0) + (float) $deduction->value;
                    $byCode[$code]['label'] = (string) $deduction->label;
                    $byCode[$code]['judges'][$score->judge_id] = true;
                }
            }
        }

        $codes = [];
        foreach ($byCode as $code => $stat) {
            $judges = count($stat['judges']);
            $codes[] = [
                'code' => $code,
                'label' => $stat['label'],
                'presses' => $stat['presses'],
                'judges' => $judges,
                'value_sum' => self::round3($stat['value_sum']),
                'confirmed' => $judges >= JudgingCalculator::MIN_CODE_JUDGES,
            ];
        }

        usort($codes, fn (array $a, array $b) => ($b['presses'] <=> $a['presses'])
            ?: ($b['judges'] <=> $a['judges'])
            ?: strcmp($a['code'], $b['code']));

        return ['codes' => $codes];
    }

    /**
     * Фокусные пулы для LLM-дайджеста: содержательные (N ≥ POOL_MIN_N),
     * сначала самые «спорные» (по среднему разбросу: в A/B — по max(разброс A,
     * разброс B), панели между собой не сравниваются), до FOCUS_POOLS.
     *
     * @param  array<int, array<string, mixed>>  $pools
     * @return array<int, string> ключи пулов
     */
    public static function focusKeys(array $pools): array
    {
        $candidates = array_values(array_filter(
            $pools,
            fn (array $pool) => $pool['n'] >= self::POOL_MIN_N,
        ));

        usort($candidates, fn (array $a, array $b) => ($b['mean_spread'] ?? -1) <=> ($a['mean_spread'] ?? -1));

        return array_slice(array_column($candidates, 'key'), 0, self::FOCUS_POOLS);
    }

    /**
     * Дайджест для LLM: все спортсмены — кодами A1…, судьи — кодами S1….
     * ФИО разрешены только призёрам фокусных пулов (полные таблицы
     * рендерит blade без ограничений — они в LLM не уходят).
     *
     * Источники в дайджесте (как требует заказчик):
     *  - «Сводка оценок» — по-судейские оценки каждого выступления
     *    (scores: код судьи → оценка) и авто-расчёт (auto);
     *  - «Журнал судейства» — audit: ручные корректировки итогов, правки
     *    и снятия оценок, распределение действий;
     *  - коды сбавок панели A — deductions с проверкой R-3.14 (confirmed).
     *
     * В A/B все судейские сравнения — внутри одной панели (panels: A отдельно,
     * B отдельно, шкалы несопоставимы): min/max/разброс берутся из блока
     * панели, Δ судьи — от среднего его панели (judges[].panel).
     *
     * @param  array<string, mixed>  $metrics  результат build()
     * @return array<string, mixed>
     */
    public static function llmDigest(array $metrics): array
    {
        $focusKeys = $metrics['focusKeys'];

        // ФИО судей в дайджест не попадают: min/max — кодами S1…
        $codeByName = [];
        foreach ($metrics['judges'] as $judge) {
            $codeByName[$judge['name']] = $judge['code'];
        }
        $judgeCode = fn (?string $name) => $name === null ? null : ($codeByName[$name] ?? null);

        // Блоки панелей с кодами судей (A/B: сравнения только внутри панели).
        $panelsFor = function (array $displayPanels) use ($judgeCode): array {
            $out = [];
            foreach ($displayPanels as $key => $block) {
                $out[$key] = [
                    'key' => $block['key'],
                    'label' => $block['label'],
                    'spread' => $block['spread'],
                    'min_score' => $block['min_score'],
                    'min_judge' => $judgeCode($block['min_judge']),
                    'max_score' => $block['max_score'],
                    'max_judge' => $judgeCode($block['max_judge']),
                ];
            }

            return $out;
        };

        $pools = [];
        foreach ($metrics['pools'] as $pool) {
            $isFocus = in_array($pool['key'], $focusKeys, true);

            $athletes = [];
            foreach ($pool['athletes'] as $athlete) {
                $entry = [
                    'code' => $athlete['athlete_code'],
                    'place' => $athlete['place'],
                    'final' => $athlete['final'],
                    'auto' => $athlete['auto'],
                    'scores' => $athlete['scores'],
                    'spread' => $athlete['spread'],
                    'panels' => $panelsFor($athlete['panels']),
                ];
                // ФИО — только призёрам (место ≤ 3) фокусных пулов.
                if ($isFocus && $athlete['place'] <= 3) {
                    $entry['name'] = $athlete['athlete'];
                }
                $athletes[] = $entry;
            }

            $pools[] = [
                'key' => $pool['key'],
                'n' => $pool['n'],
                'mean_final' => $pool['mean_final'],
                'std_final' => $pool['std_final'],
                'mean_spread' => $pool['mean_spread'],
                'mean_spread_panels' => $pool['mean_spread_panels'],
                'gap' => $pool['gap'],
                'athletes' => $athletes,
            ];
        }

        $topSpreads = [];
        foreach ($metrics['topSpreads'] as $row) {
            $topSpreads[] = [
                'athlete_code' => $row['athlete_code'],
                'style' => $row['style'],
                'age_group' => $row['age_group'],
                'spread' => $row['spread'],
                'panels' => $panelsFor($row['panels']),
                'final' => $row['final'],
            ];
        }

        return [
            'competition' => $metrics['competition'],
            'totals' => $metrics['totals'],
            'judges' => array_map(
                fn (array $judge) => [
                    'code' => $judge['code'],
                    'panel' => $judge['panel'],
                    'n' => $judge['n'],
                    'mean' => $judge['mean'],
                    'std' => $judge['std'],
                    'delta' => $judge['delta'],
                    'delta_std' => $judge['delta_std'],
                    'drop_min' => $judge['drop_min'],
                    'drop_max' => $judge['drop_max'],
                    'min' => $judge['min'],
                    'max' => $judge['max'],
                ],
                $metrics['judges'],
            ),
            'spread' => $metrics['spread'],
            'spread_panels' => $metrics['spread_panels'],
            'top_spreads' => $topSpreads,
            'pools' => $pools,
            'focus_pools' => array_values(array_filter(
                $pools,
                fn (array $pool) => in_array($pool['key'], $focusKeys, true),
            )),
            'flips' => $metrics['flips'],
            'mismatches' => array_map(
                fn (array $row) => [
                    'athlete_code' => $row['athlete_code'],
                    'style' => $row['style'],
                    'age_group' => $row['age_group'],
                    'auto' => $row['auto'],
                    'final' => $row['final'],
                ],
                $metrics['mismatches'],
            ),
            // Журнал судейства: действия, ручные корректировки итогов (коды A…),
            // правки/снятия оценок. ФИО в события не попадают: ключ 'athlete'
            // (ФИО для blade) здесь вырезается, остаются только коды.
            'audit' => [
                'total' => $metrics['audit']['total'],
                'actions' => $metrics['audit']['actions'],
                'final_changes' => array_map(
                    fn (array $row) => array_diff_key($row, ['athlete' => true]),
                    $metrics['audit']['final_changes'],
                ),
                'score_actions' => $metrics['audit']['score_actions'],
            ],
            // Коды сбавок панели A с проверкой R-3.14 (confirmed — код заметили
            // ≥2 судей, только такие идут в вычет).
            'deductions' => $metrics['deductions'],
        ];
    }
}
