<?php

namespace App\Support;

/**
 * Правила R-4.6, R-4.18–R-4.20, R-3.12–R-3.15 (docs/JUDGING_RULES.md).
 *
 * Чистая математика судейства без обращения к БД — единая для пультов,
 * тестов и протоколов.
 */
class JudgingCalculator
{
    /** Правило R-3.12: стартовая оценка судьи A. */
    public const A_START = 5.0;

    /** Правило R-3.14: сколько раз можно нажать один код сбавки. */
    public const MAX_CODE_REPEATS = 2;

    /**
     * Правило R-3.14 (уточнение заказчика 08.10): сколько разных судей панели A
     * должны заметить один и тот же код сбавки, чтобы он засчитался в вычет.
     * Код, нажатый только одним судьёй (хоть один раз, хоть дважды),
     * не считается — ошибку должны заметить несколько судей.
     */
    public const MIN_CODE_JUDGES = 2;

    /**
     * Правило R-4.6: среднее с отбрасыванием крайних (только простая система).
     *
     *  - 3 и более оценок — отбрасываются одна минимальная и одна максимальная;
     *  - 1–2 оценки — среднее по всем;
     *  - пусто — null.
     *
     * @param  array<int, float|int>  $scores
     * @return array{avg: float|null, used: array<int, float>, dropped: array<int, float>}
     */
    public static function trimmedMean(array $scores): array
    {
        $values = array_map('floatval', array_values($scores));
        sort($values);

        $dropped = [];

        if (count($values) >= 3) {
            $dropped[] = array_shift($values);
            $dropped[] = array_pop($values);
        }

        if ($values === []) {
            return ['avg' => null, 'used' => [], 'dropped' => $dropped];
        }

        $avg = round(array_sum($values) / count($values), ScoreRange::PRECISION);

        return ['avg' => $avg, 'used' => $values, 'dropped' => $dropped];
    }

    /**
     * Правило R-4.19: среднее панели A/B — арифметическое по всем оценкам
     * панели, без отбрасывания крайних.
     *
     *  - пусто — null;
     *  - иначе — среднее по всем, округление до 3 знаков;
     *  - `dropped` всегда пуст.
     *
     * @param  array<int, float|int>  $scores
     * @return array{avg: float|null, used: array<int, float>, dropped: array<int, float>}
     */
    public static function panelMean(array $scores): array
    {
        $values = array_map('floatval', array_values($scores));

        if ($values === []) {
            return ['avg' => null, 'used' => [], 'dropped' => []];
        }

        $avg = round(array_sum($values) / count($values), ScoreRange::PRECISION);

        return ['avg' => $avg, 'used' => $values, 'dropped' => []];
    }

    /**
     * Правило R-3.12: оценка судьи A = 5.000 − сумма сбавок, не ниже 0.
     *
     * @param  array<int, float|int>  $deductionValues
     */
    public static function scoreFromDeductions(array $deductionValues): float
    {
        $total = array_sum(array_map('floatval', $deductionValues));

        return max(ScoreRange::GLOBAL_MIN, round(self::A_START - $total, ScoreRange::PRECISION));
    }

    /**
     * Правило R-3.14: можно ли нажать код ещё раз.
     *
     * @param  array<int, string>  $pressedCodes  уже нажатые коды (с повторами)
     */
    public static function canPressCode(array $pressedCodes, string $code): bool
    {
        $count = count(array_filter($pressedCodes, fn ($c) => (string) $c === $code));

        return $count < self::MAX_CODE_REPEATS;
    }

    /**
     * Правила R-3.13–R-3.14 (уточнение заказчика 08.10): подтверждение кода сбавки
     * на панели A — ошибка должна быть замечена несколькими судьями. Код,
     * нажатый только одним судьёй панели (неважно, один раз или дважды),
     * НЕ учитывается в вычете (нажатия остаются в снимке score_deductions
     * для аудита); код, нажатый двумя и более разными судьями, учитывается
     * полностью — каждое нажатие каждого судьи в его личную оценку.
     *
     * Оценка судьи пересчитывается как сохранённая + сумма неучтённых сбавок (эквивалент
     * «5.000 − сумма учтённых сбавок» для целостного снимка R-3.12) и
     * ограничивается диапазоном 0.000–5.000. Снимок сбавок не меняется — фильтр
     * применяется только при расчёте: набор нажатий других судей может
     * пополниться после сохранения судьи.
     *
     * @param  array<int, array{score: float|int, deductions: array<int, array{code: string, value: float|int}>}>  $judgeScores
     *     judge_id => оценка судьи и его снимок сбавок (score_deductions)
     * @return array{scores: array<int, float>, ignored: array<int, array<int, array{code: string, value: float}>>, totals: array<string, int>}
     *     scores — пересчитанные оценки (judge_id => оценка);
     *     ignored — неучтённые нажатия (judge_id => [{code, value}]);
     *     totals — сколько раз каждый код нажат суммарно по панели;
     *     judges — сколько разных судей нажали каждый код.
     */
    public static function confirmedPanelScores(array $judgeScores): array
    {
        $totals = [];   // code => сколько раз нажат суммарно по панели (аудит)
        $pressedBy = []; // code => [judge_id => true] — кто нажал код

        foreach ($judgeScores as $judgeId => $js) {
            foreach ($js['deductions'] ?? [] as $d) {
                $code = (string) $d['code'];
                $totals[$code] = ($totals[$code] ?? 0) + 1;
                $pressedBy[$code][$judgeId] = true;
            }
        }

        $judgeCounts = [];
        foreach ($pressedBy as $code => $judges) {
            $judgeCounts[$code] = count($judges);
        }

        $scores = [];
        $ignored = [];

        foreach ($judgeScores as $judgeId => $js) {
            $ignoredList = [];
            $ignoredSum = 0.0;

            foreach ($js['deductions'] ?? [] as $d) {
                if (($judgeCounts[(string) $d['code']] ?? 0) < self::MIN_CODE_JUDGES) {
                    $ignoredList[] = ['code' => (string) $d['code'], 'value' => (float) $d['value']];
                    $ignoredSum += (float) $d['value'];
                }
            }

            $effective = (float) $js['score'] + $ignoredSum;

            $scores[$judgeId] = min(
                self::A_START,
                max(ScoreRange::GLOBAL_MIN, round($effective, ScoreRange::PRECISION)),
            );

            if ($ignoredList !== []) {
                $ignored[$judgeId] = $ignoredList;
            }
        }

        return ['scores' => $scores, 'ignored' => $ignored, 'totals' => $totals, 'judges' => $judgeCounts];
    }

    /**
     * Правило R-4.20: итог сценария A/B = среднее A + среднее B.
     */
    public static function abTotal(float $avgA, float $avgB): float
    {
        return round($avgA + $avgB, ScoreRange::PRECISION);
    }
}
