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
        // Правило R-4.6: при 1–2 оценках ничего не отбрасываем.
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

    public function test_panel_mean_keeps_all_scores(): void
    {
        // Правило R-4.19: в панелях A/B крайние не отбрасываются.
        $res = JudgingCalculator::panelMean([4.5, 4.7, 5.0]);

        $this->assertSame(4.733, $res['avg']);
        $this->assertSame([4.5, 4.7, 5.0], $res['used']);
        $this->assertSame([], $res['dropped']);
    }

    public function test_panel_mean_is_never_trimmed_even_with_many_scores(): void
    {
        // Старое правило дало бы (4.5 + 4.7 + 4.9) / 3 = 4.700; новое — по всем пяти.
        $this->assertSame(4.62, JudgingCalculator::panelMean([4.5, 4.9, 4.7, 4.1, 4.9])['avg']);
    }

    public function test_panel_mean_with_one_or_two_scores_averages_all(): void
    {
        $this->assertSame(4.2, JudgingCalculator::panelMean([4.2])['avg']);
        $this->assertSame(4.35, JudgingCalculator::panelMean([4.2, 4.5])['avg']);
    }

    public function test_panel_mean_of_empty_is_null(): void
    {
        $this->assertNull(JudgingCalculator::panelMean([])['avg']);
        $this->assertSame([], JudgingCalculator::panelMean([])['used']);
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

    // --- Подтверждение кода сбавки (R-3.13, R-3.14, уточнение заказчика 08.10) ---

    public function test_code_pressed_by_single_judge_is_not_counted(): void
    {
        $res = JudgingCalculator::confirmedPanelScores([
            1 => ['score' => 4.9, 'deductions' => [['code' => '11', 'value' => 0.1]]],
            2 => ['score' => 5.0, 'deductions' => []],
        ]);

        // Код 11 нажат суммарно ровно один раз — сбавка не учитывается.
        $this->assertSame(5.0, $res['scores'][1]);
        $this->assertSame(5.0, $res['scores'][2]);
        $this->assertSame([['code' => '11', 'value' => 0.1]], $res['ignored'][1]);
        $this->assertArrayNotHasKey(2, $res['ignored']);
        $this->assertSame(['11' => 1], $res['totals']);
        $this->assertSame(['11' => 1], $res['judges']);
    }

    public function test_code_pressed_by_two_judges_counts_for_both(): void
    {
        // Судья 1 — 1 раз, судья 2 — 1 раз → у каждого по одной сбавке.
        $res = JudgingCalculator::confirmedPanelScores([
            1 => ['score' => 4.9, 'deductions' => [['code' => '11', 'value' => 0.1]]],
            2 => ['score' => 4.9, 'deductions' => [['code' => '11', 'value' => 0.1]]],
        ]);

        $this->assertSame(4.9, $res['scores'][1]);
        $this->assertSame(4.9, $res['scores'][2]);
        $this->assertSame([], $res['ignored']);
        $this->assertSame(['11' => 2], $res['totals']);
        $this->assertSame(['11' => 2], $res['judges']);
    }

    public function test_code_pressed_twice_by_single_judge_is_not_counted(): void
    {
        // Судья 1 — 2 раза, судья 2 — 0: код заметил только один судья —
        // не считается, неважно сколько раз нажал (уточнение 08.10).
        $res = JudgingCalculator::confirmedPanelScores([
            1 => ['score' => 4.8, 'deductions' => [
                ['code' => '11', 'value' => 0.1],
                ['code' => '11', 'value' => 0.1],
            ]],
            2 => ['score' => 5.0, 'deductions' => []],
        ]);

        $this->assertSame(5.0, $res['scores'][1]);
        $this->assertSame([
            1 => [
                ['code' => '11', 'value' => 0.1],
                ['code' => '11', 'value' => 0.1],
            ],
        ], $res['ignored']);
        $this->assertSame(['11' => 2], $res['totals']);
        $this->assertSame(['11' => 1], $res['judges']);
    }

    public function test_code_with_repeats_by_two_judges_counts_fully(): void
    {
        // Судья 1 — 2 раза, судья 2 — 1 раз: код заметили два судьи —
        // учитывается каждое нажатие каждого судьи.
        $res = JudgingCalculator::confirmedPanelScores([
            1 => ['score' => 4.8, 'deductions' => [
                ['code' => '11', 'value' => 0.1],
                ['code' => '11', 'value' => 0.1],
            ]],
            2 => ['score' => 4.9, 'deductions' => [
                ['code' => '11', 'value' => 0.1],
            ]],
        ]);

        $this->assertSame(4.8, $res['scores'][1]);
        $this->assertSame(4.9, $res['scores'][2]);
        $this->assertSame([], $res['ignored']);
        $this->assertSame(['11' => 3], $res['totals']);
        $this->assertSame(['11' => 2], $res['judges']);
    }

    public function test_confirmation_filter_is_applied_per_code(): void
    {
        // 22 подтверждён (2 нажатия), 33 — одиночный и не учитывается.
        $res = JudgingCalculator::confirmedPanelScores([
            1 => ['score' => 4.2, 'deductions' => [
                ['code' => '22', 'value' => 0.3],
                ['code' => '33', 'value' => 0.5],
            ]],
            2 => ['score' => 4.7, 'deductions' => [
                ['code' => '22', 'value' => 0.3],
            ]],
        ]);

        // Судья 1: 4.200 + 0.5 = 4.700; судья 2: 4.700 без изменений.
        $this->assertSame(4.7, $res['scores'][1]);
        $this->assertSame(4.7, $res['scores'][2]);
        $this->assertSame([1 => [['code' => '33', 'value' => 0.5]]], $res['ignored']);
        $this->assertSame(['22' => 2, '33' => 1], $res['totals']);
    }

    public function test_score_without_deduction_snapshot_is_kept(): void
    {
        // Оценки без снимка сбавок (простая схема, старые данные) не пересчитываются.
        $res = JudgingCalculator::confirmedPanelScores([
            1 => ['score' => 4.5, 'deductions' => []],
            2 => ['score' => 4.7, 'deductions' => []],
        ]);

        $this->assertSame(4.5, $res['scores'][1]);
        $this->assertSame(4.7, $res['scores'][2]);
        $this->assertSame([], $res['ignored']);
    }
}
