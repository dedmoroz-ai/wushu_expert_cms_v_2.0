<?php

namespace Tests\Unit;

use App\Support\ScoreRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Правила 8.2, 8.3 (docs/JUDGING_RULES.md): проверка достижимости
 * частично набранного значения — единая для обоих пультов.
 */
class ScoreRangePrefixTest extends TestCase
{
    public static function prefixProvider(): array
    {
        return [
            // [диапазон min, max, набранная строка, ожидание]

            // Полный диапазон 0–10: принимаем всё разумное.
            'full range: 0' => [0.0, 10.0, '0', true],
            'full range: 9' => [0.0, 10.0, '9', true],
            'full range: 10' => [0.0, 10.0, '10', true],
            'full range: 10.000' => [0.0, 10.0, '10.000', true],
            'full range: 11 rejected' => [0.0, 10.0, '11', false],
            'full range: 10.1 rejected' => [0.0, 10.0, '10.1', false],

            // Диапазон 5–10: «4» недостижимо, «1» достижимо как начало «10».
            'min5: 4 rejected' => [5.0, 10.0, '4', false],
            'min5: 1 allowed as prefix of 10' => [5.0, 10.0, '1', true],
            'min5: 10 allowed' => [5.0, 10.0, '10', true],
            'min5: 19 rejected' => [5.0, 10.0, '19', false],
            'min5: 5 allowed' => [5.0, 10.0, '5', true],
            'min5: 0 rejected' => [5.0, 10.0, '0', false],

            // Диапазон 5–9.5: верхняя граница дробная.
            'max9.5: 9.5 allowed' => [5.0, 9.5, '9.5', true],
            'max9.5: 9.6 rejected' => [5.0, 9.5, '9.6', false],
            'max9.5: 9.4 allowed' => [5.0, 9.5, '9.4', true],
            'max9.5: 1 rejected (10 above max)' => [5.0, 9.5, '1', false],
            'max9.5: 9.50 allowed' => [5.0, 9.5, '9.50', true],
            'max9.5: 9.51 rejected' => [5.0, 9.5, '9.51', false],

            // Точность: 4-й знак недостижим.
            'precision: 8.125 allowed' => [0.0, 10.0, '8.125', true],
            'precision: 8.1259 rejected' => [0.0, 10.0, '8.1259', false],

            // Мусорный ввод.
            'garbage rejected' => [0.0, 10.0, 'abc', false],
            'empty rejected' => [0.0, 10.0, '', false],
        ];
    }

    #[DataProvider('prefixProvider')]
    public function test_prefix_reachability(float $min, float $max, string $candidate, bool $expected): void
    {
        $range = new ScoreRange($min, $max);

        $this->assertSame(
            $expected,
            ScoreRange::isPrefixReachable($candidate, $range),
            "Ввод «{$candidate}» в диапазоне {$range->label()}"
        );
    }
}
