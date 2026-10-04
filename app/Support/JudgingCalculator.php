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
     * Правило R-4.20: итог сценария A/B = среднее A + среднее B.
     */
    public static function abTotal(float $avgA, float $avgB): float
    {
        return round($avgA + $avgB, ScoreRange::PRECISION);
    }
}
