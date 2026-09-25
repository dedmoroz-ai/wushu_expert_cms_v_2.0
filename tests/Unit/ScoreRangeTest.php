<?php

namespace Tests\Unit;

use App\Models\AgeGroup;
use App\Support\ScoreRange;
use PHPUnit\Framework\TestCase;

/**
 * Правила 8.1, 8.2, 8.3 (docs/JUDGING_RULES.md).
 */
class ScoreRangeTest extends TestCase
{
    public function test_global_range_is_zero_to_ten(): void
    {
        $range = ScoreRange::global();

        $this->assertSame(0.0, $range->min);
        $this->assertSame(10.0, $range->max);
        $this->assertSame('0.000 – 10.000', $range->label());
    }

    public function test_age_group_limits_narrow_the_range(): void
    {
        $group = new AgeGroup(['min_score' => 5.0, 'max_score' => 9.5]);

        $range = ScoreRange::forAgeGroup($group);

        $this->assertSame(5.0, $range->min);
        $this->assertSame(9.5, $range->max);
        $this->assertTrue($range->contains(9.5));
        $this->assertTrue($range->contains(5.0));
        $this->assertFalse($range->contains(4.999));
        $this->assertFalse($range->contains(9.501));
    }

    public function test_age_group_limits_cannot_exceed_global_range(): void
    {
        $group = new AgeGroup(['min_score' => -5, 'max_score' => 99]);

        $range = ScoreRange::forAgeGroup($group);

        $this->assertSame(0.0, $range->min);
        $this->assertSame(10.0, $range->max);
    }

    public function test_broken_limits_fall_back_to_global_range(): void
    {
        // min > max в справочнике — используем общесистемный диапазон.
        $group = new AgeGroup(['min_score' => 9.0, 'max_score' => 5.0]);

        $range = ScoreRange::forAgeGroup($group);

        $this->assertSame(0.0, $range->min);
        $this->assertSame(10.0, $range->max);
    }

    public function test_null_limits_use_global_bounds(): void
    {
        $group = new AgeGroup(['min_score' => null, 'max_score' => 8.0]);

        $range = ScoreRange::forAgeGroup($group);

        $this->assertSame(0.0, $range->min);
        $this->assertSame(8.0, $range->max);
    }

    public function test_precision_is_three_decimals(): void
    {
        $range = ScoreRange::global();

        // Правило 8.1: 8.125 не должно превращаться в 8.13.
        $this->assertSame(8.125, $range->round(8.125));
        $this->assertSame('8.125', $range->format(8.125));
        $this->assertSame(8.866, $range->round(8.8664));
        $this->assertSame(3, ScoreRange::PRECISION);
    }

    public function test_parse_accepts_comma_and_rejects_garbage(): void
    {
        $this->assertSame(8.125, ScoreRange::parse('8,125'));
        $this->assertSame(9.0, ScoreRange::parse(' 9 '));

        // Незавершённый ввод «8.» PHP считает числом — трактуем как 8.000.
        $this->assertSame(8.0, ScoreRange::parse('8.'));

        $this->assertNull(ScoreRange::parse(''));
        $this->assertNull(ScoreRange::parse('abc'));
        $this->assertNull(ScoreRange::parse('.'));
        $this->assertNull(ScoreRange::parse(null));
    }

    public function test_ten_is_allowed_in_global_range(): void
    {
        // Правило 8.2: линейный судья теперь может выставить 10.000.
        $this->assertTrue(ScoreRange::global()->contains(10.0));
        $this->assertFalse(ScoreRange::global()->contains(10.001));
    }
}
