<?php

namespace Tests\Unit;

use App\Support\ProtocolGroups;
use PHPUnit\Framework\TestCase;

/**
 * Группа «O» (особые спортсмены) — R-6.15: группировка протоколов.
 *
 * Решения 30.09.2026: подгруппа «(O)» идёт сразу после основной той же
 * номинации (порядок номинаций не меняется), подпись — « (O)».
 */
class ProtocolGroupsTest extends TestCase
{
    private function row(int $style, int $group, bool $special, string $gender = 'male', ?string $name = null): array
    {
        return [
            'style_id' => $style,
            'age_group_id' => $group,
            'gender' => $gender,
            'is_special' => $special,
            'name' => $name ?? 'Участник',
        ];
    }

    public function test_title_gets_o_suffix_only_for_special(): void
    {
        $this->assertSame(
            'Чанцюань — Мальчики (7-8 лет)',
            ProtocolGroups::title('Чанцюань', 'Мальчики', '(7-8 лет)')
        );
        $this->assertSame(
            'Чанцюань — Мальчики (7-8 лет) (O)',
            ProtocolGroups::title('Чанцюань', 'Мальчики', '(7-8 лет)', true)
        );
    }

    public function test_special_subgroup_goes_right_after_main(): void
    {
        // Вход: O-группа номинации 1 перемешана с номинацией 2.
        $rows = [
            $this->row(1, 1, true, 'male', 'A'),
            $this->row(2, 1, false, 'male', 'B'),
            $this->row(1, 1, false, 'male', 'C'),
            $this->row(1, 1, true, 'male', 'D'),
            $this->row(2, 1, true, 'male', 'E'),
            $this->row(1, 1, false, 'male', 'F'),
        ];

        // Ожидание: номинация 1 (C, F) → номинация 1 (O) (A, D) → номинация 2 (B) → номинация 2 (O) (E).
        $sorted = ProtocolGroups::sortSubgroups($rows)
            ->map(fn (array $r) => $r['name'])
            ->all();

        $this->assertSame(['C', 'F', 'A', 'D', 'B', 'E'], $sorted);
    }

    public function test_order_inside_subgroups_is_preserved(): void
    {
        // Порядок внутри подгруппы не меняется (например, по убыванию оценки).
        $rows = [
            $this->row(1, 1, true, 'male', '8.5'),
            $this->row(1, 1, true, 'male', '9.0'),
            $this->row(1, 1, false, 'male', '9.5'),
            $this->row(1, 1, false, 'male', '7.0'),
        ];

        $sorted = ProtocolGroups::sortSubgroups($rows)
            ->map(fn (array $r) => $r['name'])
            ->all();

        $this->assertSame(['9.5', '7.0', '8.5', '9.0'], $sorted);
    }

    public function test_gender_is_part_of_nomination(): void
    {
        // Пол — часть номинации: мужская подгруппа «(O)» идёт после основной
        // мужской, а не за женской (подгруппы полов не смешиваются).
        $rows = [
            $this->row(1, 1, false, 'male', 'М'),
            $this->row(1, 1, false, 'female', 'Ж'),
            $this->row(1, 1, true, 'male', 'МO'),
        ];

        $sorted = ProtocolGroups::sortSubgroups($rows)
            ->map(fn (array $r) => $r['name'])
            ->all();

        $this->assertSame(['М', 'МO', 'Ж'], $sorted);
    }

    public function test_is_special_accepts_models_and_arrays(): void
    {
        $this->assertTrue(ProtocolGroups::isSpecial(['is_special' => true]));
        $this->assertFalse(ProtocolGroups::isSpecial(['is_special' => false]));
        $this->assertFalse(ProtocolGroups::isSpecial([]));

        $model = new \stdClass();
        $model->is_special = 1;
        $this->assertTrue(ProtocolGroups::isSpecial($model));
    }

    public function test_empty_rows_give_empty_collection(): void
    {
        $this->assertCount(0, ProtocolGroups::sortSubgroups([]));
    }
}
