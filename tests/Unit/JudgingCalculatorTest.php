<?php

namespace Tests\Unit;

use App\Support\JudgingCalculator;
use App\Support\ScoreRange;
use PHPUnit\Framework\TestCase;

/**
 * Правила R-4.6, R-4.18–R-4.20, R-3.12–R-3.14 (docs/JUDGING_RULES.md).
 */
class JudgingCalculatorTest extends TestCase
{
    public function test_trimmed_mean_drops_min_and_max_with_three_or_more(): void
    {
        $res = JudgingCalculator::trimmedMean([4.5, 4.9, 4.7, 4.1, 4.8]);

        $this->assertSame([4.1, 4.9], $res['dropped']);
        $this->assertSame(round((4.5 + 4.7 + 4.8) / 3, 3), $res['avg']);
    }

    public function test_trimmed_mean_with_three_scores_uses_middle_one(): void
    {
        $this->assertSame(4.5, JudgingCalculator::trimmedMean([3.0, 4.5, 5.0])['avg']);
    }

    public function test_trimmed_mean_with_one_or_two_scores_averages_all(): void
    {
        // Правило R-4.19: при 1–2 оценках в панели ничего не отбрасываем.
        $this->assertSame(4.2, JudgingCalculator::trimmedMean([4.2])['avg']);
        $this->assertSame(4.35, JudgingCalculator::trimmedMean([4.2, 4.5])['avg']);
        $this->assertSame([], JudgingCalculator::trimmedMean([4.2, 4.5])['dropped']);
    }

    public function test_trimmed_mean_of_empty_is_null(): void
    {
        $this->assertNull(JudgingCalculator::trimmedMean([])['avg']);
    }

    public function test_trimmed_mean_rounds_to_three_decimals(): void
    {
        // Отбрасываем 7.0 и 9.0; (8.1 + 8.2 + 8.2) / 3 = 8.1666… → 8.167
        $this->assertSame(8.167, JudgingCalculator::trimmedMean([7.0, 8.1, 8.2, 8.2, 9.0])['avg']);
    }

    public function test_a_score_starts_at_five_and_subtracts_deductions(): void
    {
        $this->assertSame(5.0, JudgingCalculator::scoreFromDeductions([]));
        $this->assertSame(4.5, JudgingCalculator::scoreFromDeductions([0.1, 0.1, 0.3]));
    }

    public function test_a_score_is_floored_at_zero(): void
    {
        // Правило R-3.13: оценка A не бывает отрицательной.
        $this->assertSame(0.0, JudgingCalculator::scoreFromDeductions(array_fill(0, 12, 0.5)));
    }

    public function test_a_score_has_no_float_noise(): void
    {
        // 5 − 0.1 × 3 в float = 4.699999… → должно быть ровно 4.7.
        $this->assertSame('4.700', number_format(JudgingCalculator::scoreFromDeductions([0.1, 0.1, 0.1]), ScoreRange::PRECISION, '.', ''));
    }

    public function test_code_can_be_pressed_at_most_twice(): void
    {
        // Правило R-3.14.
        $this->assertTrue(JudgingCalculator::canPressCode([], '11'));
        $this->assertTrue(JudgingCalculator::canPressCode(['11'], '11'));
        $this->assertFalse(JudgingCalculator::canPressCode(['11', '11'], '11'));
        $this->assertTrue(JudgingCalculator::canPressCode(['11', '11'], '12'));
        $this->assertSame(2, JudgingCalculator::MAX_CODE_REPEATS);
    }

    public function test_ab_total_is_sum_of_panel_averages(): void
    {
        // Правило R-4.20.
        $this->assertSame(8.667, JudgingCalculator::abTotal(4.567, 4.1));
        $this->assertSame(10.0, JudgingCalculator::abTotal(5.0, 5.0));
    }
}
