<?php

namespace Tests\Unit;

use App\Models\AgeGroup;
use App\Support\ScoreRange;
use PHPUnit\Framework\TestCase;

/**
 * Правила R-3.13, R-4.19 (docs/JUDGING_RULES.md): диапазоны панелей A/B.
 */
class ScoreRangePanelTest extends TestCase
{
    public function test_panel_a_is_always_zero_to_five(): void
    {
        $group = new AgeGroup(['min_score' => 7.0, 'max_score' => 9.0, 'b_min_score' => 3.0]);

        $range = ScoreRange::forPanel($group, 'A');

        $this->assertSame(0.0, $range->min);
        $this->assertSame(5.0, $range->max);
    }

    public function test_panel_b_uses_b_limits(): void
    {
        $group = new AgeGroup(['b_min_score' => 2.5, 'b_max_score' => 4.8]);

        $range = ScoreRange::forPanel($group, 'B');

        $this->assertSame(2.5, $range->min);
        $this->assertSame(4.8, $range->max);
    }

    public function test_panel_b_falls_back_to_category_limits_clamped_to_five(): void
    {
        // min_score 3 → 3; max_score 10 → зажимается до 5.
        $group = new AgeGroup(['min_score' => 3.0, 'max_score' => 10.0]);

        $range = ScoreRange::forPanel($group, 'B');

        $this->assertSame(3.0, $range->min);
        $this->assertSame(5.0, $range->max);
    }

    public function test_panel_b_category_min_above_five_falls_back_to_full_panel(): void
    {
        // min_score 7 → зажимается до 5, max 9 → 5: диапазон 5–5 допустим (вырожденный).
        $group = new AgeGroup(['min_score' => 7.0, 'max_score' => 9.0]);

        $range = ScoreRange::forPanel($group, 'B');

        $this->assertSame(5.0, $range->min);
        $this->assertSame(5.0, $range->max);
    }

    public function test_panel_b_broken_limits_fall_back_to_zero_five(): void
    {
        $group = new AgeGroup(['b_min_score' => 4.5, 'b_max_score' => 3.0]);

        $range = ScoreRange::forPanel($group, 'B');

        $this->assertSame(0.0, $range->min);
        $this->assertSame(5.0, $range->max);
    }

    public function test_panel_b_without_group_is_zero_to_five(): void
    {
        $range = ScoreRange::forPanel(null, 'B');

        $this->assertSame('0.000 – 5.000', $range->label());
    }

    public function test_total_range_label_keeps_three_decimals(): void
    {
        // Этап 6: итог по-прежнему форматируется с 3 знаками.
        $this->assertSame('8.667', ScoreRange::global()->format(8.6666));
        $this->assertSame('10.000', ScoreRange::global()->format(10));
    }
}
