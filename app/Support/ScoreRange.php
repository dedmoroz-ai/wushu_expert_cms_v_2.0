<?php

namespace App\Support;

use App\Models\AgeGroup;
use App\Models\Registration;

/**
 * Правила 8.1, 8.2, 8.3 (docs/JUDGING_RULES.md).
 *
 * Единый источник истины для диапазона допустимых оценок и точности.
 *
 * Логика:
 *  1. Базовый (общесистемный) диапазон — GLOBAL_MIN .. GLOBAL_MAX.
 *  2. Если у возрастной категории участника заданы min_score / max_score —
 *     они сужают базовый диапазон.
 *  3. Точность единая — PRECISION знаков после точки (scores.score = decimal(5,3)).
 */
class ScoreRange
{
    /** Минимально допустимая оценка в системе. */
    public const GLOBAL_MIN = 0.0;

    /** Максимально допустимая оценка в системе. */
    public const GLOBAL_MAX = 10.0;

    /** Количество знаков после точки (соответствует decimal(5,3)). */
    public const PRECISION = 3;

    /** Правило R-4.19: максимум оценки одной панели (A или B) в сценарии A/B. */
    public const PANEL_MAX = 5.0;

    public function __construct(
        public readonly float $min,
        public readonly float $max,
    ) {
    }

    /**
     * Диапазон по умолчанию (без учёта возрастной категории).
     */
    public static function global(): self
    {
        return new self(self::GLOBAL_MIN, self::GLOBAL_MAX);
    }

    /**
     * Диапазон для конкретной заявки: лимиты возрастной категории,
     * ограниченные общесистемным диапазоном.
     */
    public static function forRegistration(?Registration $registration): self
    {
        if (!$registration) {
            return self::global();
        }

        return self::forAgeGroup($registration->ageGroup);
    }

    /**
     * Диапазон для возрастной категории.
     */
    public static function forAgeGroup(?AgeGroup $ageGroup): self
    {
        $min = self::GLOBAL_MIN;
        $max = self::GLOBAL_MAX;

        if ($ageGroup) {
            if (!is_null($ageGroup->min_score)) {
                $min = max($min, (float) $ageGroup->min_score);
            }

            if (!is_null($ageGroup->max_score)) {
                $max = min($max, (float) $ageGroup->max_score);
            }
        }

        // Защита от некорректно заполненного справочника (min > max).
        if ($min > $max) {
            return self::global();
        }

        return new self($min, $max);
    }

    /**
     * Правила R-3.13, R-4.19: диапазон оценки судьи конкретной панели (сценарий A/B).
     *
     *  - A: всегда 0.000–5.000 (оценка = 5.000 минус сбавки, не ниже 0).
     *  - B: age_groups.b_min_score / b_max_score, ограниченные 0–5;
     *       если не заданы — min_score / max_score категории, ограниченные 0–5.
     */
    public static function forPanel(?AgeGroup $ageGroup, string $panel): self
    {
        $panelMin = self::GLOBAL_MIN;
        $panelMax = self::PANEL_MAX;

        if ($panel !== 'B' || !$ageGroup) {
            return new self($panelMin, $panelMax);
        }

        $min = $ageGroup->b_min_score ?? $ageGroup->min_score;
        $max = $ageGroup->b_max_score ?? $ageGroup->max_score;

        $min = is_null($min) ? $panelMin : min($panelMax, max($panelMin, (float) $min));
        $max = is_null($max) ? $panelMax : min($panelMax, max($panelMin, (float) $max));

        if ($min > $max) {
            return new self($panelMin, $panelMax);
        }

        return new self($min, $max);
    }

    /**
     * Диапазон оценки судьи для заявки с учётом сценария турнира.
     * simple → forRegistration(); ab → forPanel() по функции судьи.
     */
    public static function forJudge(?Registration $registration, ?string $panel): self
    {
        if (!$registration || !$registration->competition?->isAbScheme() || !$panel) {
            return self::forRegistration($registration);
        }

        return self::forPanel($registration->ageGroup, $panel);
    }

    /**
     * Правила R-4.11, R-4.20: диапазон итогового балла.
     * simple → лимиты возрастной категории; ab → A + B = 0.000–10.000.
     */
    public static function totalRange(?Registration $registration): self
    {
        if ($registration && $registration->competition?->isAbScheme()) {
            return self::global();
        }

        return self::forRegistration($registration);
    }

    /**
     * Проверка значения на попадание в диапазон.
     * Сравниваем по округлённому значению, чтобы 10.0004 не «вылетало» из-за float.
     */
    public function contains(float $value): bool
    {
        $value = $this->round($value);

        return $value >= $this->round($this->min) && $value <= $this->round($this->max);
    }

    /**
     * Округление до системной точности.
     */
    public function round(float $value): float
    {
        return round($value, self::PRECISION);
    }

    /**
     * Человекочитаемая подпись диапазона — для пультов и уведомлений.
     */
    public function label(): string
    {
        return $this->format($this->min) . ' – ' . $this->format($this->max);
    }

    /**
     * Форматирование значения с системной точностью.
     */
    public function format(float $value): string
    {
        return number_format($this->round($value), self::PRECISION, '.', '');
    }

    /**
     * Нормализация пользовательского ввода: запятая → точка, обрезка пробелов.
     * Возвращает null, если ввод не является числом.
     */
    public static function parse(?string $input): ?float
    {
        if ($input === null) {
            return null;
        }

        $normalized = str_replace(',', '.', trim($input));

        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }

    /**
     * Правила 8.2, 8.3: может ли частично набранное значение в итоге
     * попасть в допустимый диапазон, если дописать цифры справа.
     *
     * Логика: строим множество достижимых интервалов.
     *  - «9.5» (1 знак, можно дописать ещё 2) → 9.500 .. 9.599
     *  - «1» (целая часть, можно дописать дробную ИЛИ ещё одну цифру целой части)
     *       → 1.000 .. 1.999  (как «1.xxx»)
     *       → 10.000 .. 19.999 (как «1x.xxx»)
     *
     * Пример для диапазона 5.000–10.000:
     *  «4» → интервалы 4.000-4.999 и 40.000-49.999 — оба вне диапазона, отклоняем.
     *  «1» → 1.000-1.999 вне, но 10.000-19.999 пересекается на 10.000 — разрешаем.
     */
    public static function isPrefixReachable(string $candidate, self $range): bool
    {
        $value = self::parse($candidate);

        if ($value === null) {
            return false;
        }

        $dotPosition = strpos($candidate, '.');
        $decimals = $dotPosition === false
            ? 0
            : strlen(substr($candidate, $dotPosition + 1));

        // Правило 8.1: больше PRECISION знаков после точки недопустимо.
        if ($decimals > self::PRECISION) {
            return false;
        }

        // Целая часть — максимум 2 цифры, без ведущего нуля («05» не вводим).
        $integerPart = $dotPosition === false ? $candidate : substr($candidate, 0, $dotPosition);

        if (strlen($integerPart) > 2 || (strlen($integerPart) === 2 && $integerPart[0] === '0')) {
            return false;
        }

        // Полностью набранное значение уже подходит.
        if ($range->contains($value)) {
            return true;
        }

        $epsilon = 1 / (10 ** self::PRECISION);

        if ($dotPosition !== false) {
            // Все знаки набраны — дописать больше нечего.
            if ($decimals >= self::PRECISION) {
                return false;
            }

            // «9.5» → 9.500 .. 9.599
            $step = $decimals === 0 ? 1.0 : 1 / (10 ** $decimals);
            $upper = $value + $step - $epsilon;

            return self::intervalsOverlap($value, $upper, $range);
        }

        // Целая часть без точки.

        // Вариант A: дописываем только дробную часть → value .. value + 0.999
        if (self::intervalsOverlap($value, $value + 1 - $epsilon, $range)) {
            return true;
        }

        // Вариант B: дописываем ещё одну цифру целой части (максимум 2 цифры).
        // Для «0» этот вариант невозможен — «05» с ведущим нулём не вводится.
        if (strlen($candidate) < 2 && $candidate !== '0') {
            $lower = $value * 10;
            $upper = $value * 10 + 9 + 1 - $epsilon;

            if (self::intervalsOverlap($lower, $upper, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Пересекается ли достижимый интервал [$lower, $upper] с допустимым диапазоном.
     */
    protected static function intervalsOverlap(float $lower, float $upper, self $range): bool
    {
        return $upper >= $range->round($range->min) && $lower <= $range->round($range->max);
    }
}
