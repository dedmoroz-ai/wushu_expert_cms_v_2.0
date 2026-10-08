<?php

namespace App\Support;

use App\Models\Competition;
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
 *  - Δ судьи = среднее (оценка судьи − итог строки) — систематическая
 *    строгость/щедрость относительно коллег;
 *  - разброс выступления = максимум − минимум оценок судей;
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
     *     topSpreads: array<int, array<string, mixed>>,
     *     pools: array<int, array<string, mixed>>,
     *     flips: array<int, array<string, mixed>>,
     *     mismatches: array<int, array<string, mixed>>,
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
        $judgeDelta = [];
        $judgeDropMin = [];
        $judgeDropMax = [];

        foreach ($registrations as $reg) {
            $values = $scoresByReg[$reg->id] ?? [];
            $auto = self::autoScore($isAb, $values, $extrasByReg[$reg->id] ?? []);


            $spread = null;
            if (count($values) >= 2) {
                $spread = round(max($values) - min($values), ScoreRange::PRECISION);
            }

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
                if ($auto !== null) {
                    $judgeDelta[$code][] = $value - $auto;
                }
            }

            $rows[] = [
                'code' => 'A'.(count($rows) + 1),
                'reg' => $reg,
                'scores' => $values,
                'auto' => $auto,
                'final' => $reg->final_score !== null ? (float) $reg->final_score : null,
                'spread' => $spread,
            ];
        }

        $judges = self::judgesSection($judgeNames, $judgeScores, $judgeDelta, $judgeDropMin, $judgeDropMax);
        [$spreadStats, $topSpreads] = self::spreadsSection($rows, $judgeNames);
        $pools = self::poolsSection($rows, $judgeNames);
        // Влияние R-4.6 на топ-3 — только простая схема; в A/B правила другие.
        $flips = $isAb ? [] : self::flipsSection($pools);
        $mismatches = self::mismatchesSection($rows);

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
            'topSpreads' => $topSpreads,
            'pools' => $pools,
            'flips' => $flips,
            'mismatches' => $mismatches,
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
     * Авто-расчёт итога строки по сценарию турнира.
     *
     *  - простая схема: trimmedMean (R-4.6);
     *  - A/B: среднее A (оценки с подтверждением кодов сбавок R-3.14) +
     *    среднее B (R-4.20), крайние не отбрасываются (R-4.19).
     *
     * Оценки, выставленные не в своей функции, в авто-расчёт не идут (R-4.18),
     * как и в «Сводной таблице оценок».
     *
     * @param  array<string, float>  $values  код судьи => оценка
     * @param  array<string, array{panel: string, counted: bool, deductions: array<int, array{code: string, value: float}>}>  $extras
     */
    private static function autoScore(bool $isAb, array $values, array $extras): ?float
    {
        if ($values === []) {
            return null;
        }

        if (! $isAb) {
            return JudgingCalculator::trimmedMean(array_values($values))['avg'];
        }

        $judgeScoresA = [];
        $valuesB = [];

        foreach ($values as $code => $value) {
            $extra = $extras[$code] ?? null;
            if ($extra === null || ! $extra['counted']) {
                continue;
            }
            if ($extra['panel'] === Competition::PANEL_A) {
                $judgeScoresA[$code] = ['score' => $value, 'deductions' => $extra['deductions']];
            } elseif ($extra['panel'] === Competition::PANEL_B) {
                $valuesB[] = $value;
            }
        }

        $avgA = $judgeScoresA === []
            ? null
            : JudgingCalculator::panelMean(JudgingCalculator::confirmedPanelScores($judgeScoresA)['scores'])['avg'];
        $avgB = JudgingCalculator::panelMean($valuesB)['avg'];

        return ($avgA !== null && $avgB !== null)
            ? JudgingCalculator::abTotal($avgA, $avgB)
            : null;
    }

    /**
     * Секция по судьям: средний балл, Δ, СКО Δ, отсечения, диапазон.
     * Сортировка — по Δ (сначала «щедрые»).
     *
     * @param  array<string, string>  $judgeNames  код => ФИО
     * @param  array<string, array<int, float>>  $judgeScores
     * @param  array<string, array<int, float>>  $judgeDelta
     * @param  array<string, int>  $judgeDropMin
     * @param  array<string, int>  $judgeDropMax
     * @return array<int, array<string, mixed>>
     */
    private static function judgesSection(
        array $judgeNames,
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

        usort($judges, fn (array $a, array $b) => $b['delta'] <=> $a['delta']);

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
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, string>  $judgeNames  код => ФИО
     * @return array{0: array<string, float|int|null>, 1: array<int, array<string, mixed>>}
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

        $values = array_column($spreads, 'spread');
        $sorted = $values;
        sort($sorted);

        $stats = [
            'mean' => self::round3(self::mean($values)),
            'median' => $sorted === [] ? null : self::round3($sorted[(int) floor((count($sorted) - 1) / 2)]),
            'max' => $values === [] ? null : max($values),
            'ge_07' => count(array_filter($values, fn ($v) => $v >= 0.7)),
        ];

        $top = [];
        foreach (array_slice($spreads, 0, 15) as $item) {
            $row = $rows[$item['idx']];
            $scores = $row['scores'];
            $minCode = self::extremeJudge($scores, 'min');
            $maxCode = self::extremeJudge($scores, 'max');
            $top[] = [
                'athlete_code' => $row['code'],
                'athlete' => self::athleteName($row['reg']),
                'style' => (string) ($row['reg']->style?->name ?? '—'),
                'age_group' => (string) ($row['reg']->ageGroup?->name ?? '—'),
                'spread' => $item['spread'],
                'min_score' => $scores[$minCode] ?? null,
                'min_judge' => $judgeNames[$minCode] ?? null,
                'max_score' => $scores[$maxCode] ?? null,
                'max_judge' => $judgeNames[$maxCode] ?? null,
                'final' => $row['final'],
            ];
        }

        return [$stats, $top];
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
                $minCode = $scores === [] ? null : self::extremeJudge($scores, 'min');
                $maxCode = $scores === [] ? null : self::extremeJudge($scores, 'max');

                $athletes[] = [
                    'place' => $place,
                    'athlete_code' => $row['code'],
                    'athlete' => self::athleteName($row['reg']),
                    'final' => $row['final'],
                    'auto' => $row['auto'],
                    'spread' => $row['spread'],
                    'min_score' => $minCode === null ? null : $scores[$minCode],
                    'min_judge' => $minCode === null ? null : $judgeNames[$minCode],
                    'max_score' => $maxCode === null ? null : $scores[$maxCode],
                    'max_judge' => $maxCode === null ? null : $judgeNames[$maxCode],
                    'scores' => $scores,
                ];
            }

            $finals = array_values(array_filter(array_column($athletes, 'final'), fn ($v) => $v !== null));
            $spreads = array_values(array_filter(array_column($athletes, 'spread'), fn ($v) => $v !== null));

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
     * Фокусные пулы для LLM-дайджеста: содержательные (N ≥ POOL_MIN_N),
     * сначала самые «спорные» (по среднему разбросу судей), до FOCUS_POOLS.
     *
     * @param  array<int, array<string, mixed>>  $pools
     * @return array<int, string>  ключи пулов
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

        $pools = [];
        foreach ($metrics['pools'] as $pool) {
            $isFocus = in_array($pool['key'], $focusKeys, true);

            $athletes = [];
            foreach ($pool['athletes'] as $athlete) {
                $entry = [
                    'code' => $athlete['athlete_code'],
                    'place' => $athlete['place'],
                    'final' => $athlete['final'],
                    'spread' => $athlete['spread'],
                    'min_judge' => $judgeCode($athlete['min_judge']),
                    'max_judge' => $judgeCode($athlete['max_judge']),
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
                'min_judge' => $judgeCode($row['min_judge']),
                'max_judge' => $judgeCode($row['max_judge']),
                'final' => $row['final'],
            ];
        }

        return [
            'competition' => $metrics['competition'],
            'totals' => $metrics['totals'],
            'judges' => array_map(
                fn (array $judge) => [
                    'code' => $judge['code'],
                    'mean' => $judge['mean'],
                    'delta' => $judge['delta'],
                    'delta_std' => $judge['delta_std'],
                    'drop_min' => $judge['drop_min'],
                    'drop_max' => $judge['drop_max'],
                ],
                $metrics['judges'],
            ),
            'spread' => $metrics['spread'],
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
        ];
    }
}