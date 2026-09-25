<?php

namespace Tests\Unit;

use App\Filament\Pages\JudgePad;
use App\Support\ScoreRange;
use Tests\TestCase;

/**
 * Правила 8.1, 8.2, 8.3 (docs/JUDGING_RULES.md): проверка набора оценки
 * на пульте линейного судьи.
 *
 * Тестируем protected-логику addNumber/isPrefixAcceptable через прямую
 * установку публичных свойств страницы (без Livewire-рендера).
 */
class JudgePadInputTest extends TestCase
{
    private function pad(float $min, float $max): JudgePad
    {
        $pad = new JudgePad();
        $pad->canVote = true;
        $pad->score = '';
        $pad->scoreMin = $min;
        $pad->scoreMax = $max;
        $pad->scoreRangeLabel = (new ScoreRange($min, $max))->label();

        return $pad;
    }

    /**
     * Эмуляция последовательного нажатия кнопок пульта.
     */
    private function type(JudgePad $pad, array $keys): string
    {
        foreach ($keys as $key) {
            $pad->addNumber($key);
        }

        return $pad->score;
    }

    public function test_full_range_allows_ten(): void
    {
        // Правило 8.2: 10.000 больше не блокируется.
        $pad = $this->pad(0.0, 10.0);

        $this->assertSame('10.000', $this->type($pad, ['1', '0', '.', '0', '0', '0']));
    }

    public function test_three_decimals_are_allowed_and_fourth_is_ignored(): void
    {
        // Правило 8.1: 8.125 сохраняется целиком, четвёртый знак не принимается.
        $pad = $this->pad(0.0, 10.0);

        $this->assertSame('8.125', $this->type($pad, ['8', '.', '1', '2', '5', '7']));
    }

    public function test_value_above_max_is_rejected(): void
    {
        $pad = $this->pad(5.0, 9.5);

        // «9.6» вне диапазона — последняя цифра не принимается.
        $this->assertSame('9.', $this->type($pad, ['9', '.', '6']));
    }

    public function test_value_below_min_is_rejected_immediately(): void
    {
        $pad = $this->pad(5.0, 10.0);

        // «4» недостижимо: 4.000–4.999 целиком ниже минимума.
        $this->assertSame('', $this->type($pad, ['4']));
    }

    public function test_prefix_of_ten_is_allowed_when_min_is_five(): void
    {
        // Ключевой кейс: «1» само по себе ниже минимума 5,
        // но это начало «10», поэтому ввод должен разрешаться.
        $pad = $this->pad(5.0, 10.0);

        $this->assertSame('10', $this->type($pad, ['1', '0']));
        $this->assertSame('10.000', $this->type($pad, ['.', '0', '0', '0']));
    }

    public function test_integer_part_limited_to_two_digits(): void
    {
        $pad = $this->pad(0.0, 10.0);

        // «100» невозможно: третья цифра целой части не принимается.
        $this->assertSame('10', $this->type($pad, ['1', '0', '0']));
    }

    public function test_leading_dot_is_ignored(): void
    {
        $pad = $this->pad(0.0, 10.0);

        $this->assertSame('', $this->type($pad, ['.']));
    }

    public function test_second_dot_is_ignored(): void
    {
        $pad = $this->pad(0.0, 10.0);

        $this->assertSame('9.5', $this->type($pad, ['9', '.', '5', '.']));
    }

    public function test_backspace_removes_last_character(): void
    {
        $pad = $this->pad(0.0, 10.0);
        $this->type($pad, ['9', '.', '5']);

        $pad->backspace();

        $this->assertSame('9.', $pad->score);
    }

    public function test_input_blocked_when_voting_disabled(): void
    {
        $pad = $this->pad(0.0, 10.0);
        $pad->canVote = false;

        $this->assertSame('', $this->type($pad, ['9']));
    }

    // --- Сценарий A/B ---

    public function test_panel_b_integer_part_is_one_digit(): void
    {
        // Правило R-4.19: шкала судьи B 0–5 — «10» набрать нельзя.
        $pad = $this->pad(0.0, 5.0);
        $pad->panel = 'B';

        $this->assertSame('4.750', $this->type($pad, ['4', '.', '7', '5', '0']));

        $pad->score = '';
        $this->assertSame('1', $this->type($pad, ['1', '0']));
    }

    public function test_panel_b_rejects_value_above_five(): void
    {
        $pad = $this->pad(0.0, 5.0);
        $pad->panel = 'B';

        $this->assertSame('', $this->type($pad, ['6']));
        $this->assertSame('5.', $this->type($pad, ['5', '.', '1']));
    }

    public function test_keypad_is_disabled_in_codes_mode(): void
    {
        // Правило R-3.12: у судьи A нет клавиатуры.
        $pad = $this->pad(0.0, 5.0);
        $pad->panel = 'A';
        $pad->inputMode = 'codes';

        $this->assertSame('', $this->type($pad, ['4']));
    }

    private function codesPad(): JudgePad
    {
        $pad = $this->pad(0.0, 5.0);
        $pad->panel = 'A';
        $pad->inputMode = 'codes';
        $pad->deductionCodes = [
            ['id' => 1, 'code' => '11', 'label' => 'Руки', 'value' => 0.1, 'group' => null],
            ['id' => 2, 'code' => '22', 'label' => 'Падение', 'value' => 0.3, 'group' => null],
        ];

        return $pad;
    }

    public function test_codes_mode_starts_at_five_and_subtracts(): void
    {
        $pad = $this->codesPad();

        $this->assertSame('5.000', $pad->getCurrentAScoreProperty());

        $pad->pressCode(1);
        $pad->pressCode(2);

        $this->assertSame('4.600', $pad->getCurrentAScoreProperty());
    }

    public function test_code_cannot_be_pressed_third_time(): void
    {
        // Правило R-3.14: максимум 2 нажатия одного кода.
        $pad = $this->codesPad();

        $pad->pressCode(1);
        $pad->pressCode(1);
        $pad->pressCode(1);

        $this->assertSame([1, 1], $pad->pressedCodes);
        $this->assertSame([1 => 2], $pad->getPressCountsProperty());
        $this->assertSame('4.800', $pad->getCurrentAScoreProperty());
    }

    public function test_unknown_code_is_ignored(): void
    {
        $pad = $this->codesPad();

        $pad->pressCode(999);

        $this->assertSame([], $pad->pressedCodes);
    }

    public function test_undo_removes_last_code(): void
    {
        // Правило R-3.15.
        $pad = $this->codesPad();

        $pad->pressCode(1);
        $pad->pressCode(2);
        $pad->undoLastCode();

        $this->assertSame([1], $pad->pressedCodes);
        $this->assertSame('4.900', $pad->getCurrentAScoreProperty());
    }
}
