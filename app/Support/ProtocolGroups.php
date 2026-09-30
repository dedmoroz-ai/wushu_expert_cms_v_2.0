<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Группировка протоколов с учётом группы «O» (особые спортсмены) — R-6.15.
 *
 * Решения заказчика 30.09.2026:
 *  - спортсмены группы «O» выступают в тех же номинациях, но в отдельном зачёте
 *    между собой; в протоколах подгруппа «(O)» идёт сразу после основной той же
 *    номинации, порядок номинаций не меняется;
 *  - подпись подгруппы — «(O)»;
 *  - места (в т.ч. 1–3 для дипломов) считаются внутри подгруппы.
 *
 * Чистая логика без БД — тестируется юнит-тестами (по образцу TeamStandings).
 */
final class ProtocolGroups
{
    /** Подпись подгруппы особых спортсменов в протоколах и на дипломах. */
    public const SPECIAL_SUFFIX = ' (O)';

    /**
     * Заголовок подгруппы протокола; для группы «O» — с суффиксом « (O)».
     */
    public static function title(string $styleName, string $groupName, string $ageStr, bool $isSpecial = false): string
    {
        return sprintf('%s — %s %s', $styleName, $groupName, $ageStr)
            . ($isSpecial ? self::SPECIAL_SUFFIX : '');
    }

    /**
     * Порядок строк протокола: подгруппа «(O)» идёт сразу после основной той же
     * номинации (R-6.15). Номинация = (вид, возрастная группа, пол); порядок
     * номинаций и порядок внутри подгруппы (по оценке/жребию) сохраняются как
     * во входных данных (стабильная сортировка).
     *
     * @param  iterable<int, object|array>  $rows  строки протокола (заявки);
     *        отметка берётся из is_special (модель или массив)
     * @param  callable|null  $nominationKey  ключ номинации строки; по умолчанию
     *        (style_id, age_group_id, пол спортсмена) — для Registration
     * @return Collection<int, object|array>
     */
    public static function sortSubgroups(iterable $rows, ?callable $nominationKey = null): Collection
    {
        $nominationKey ??= static function ($row): string {
            $gender = is_array($row)
                ? ($row['gender'] ?? null)
                : ($row->athlete?->gender ?? null);

            return sprintf(
                '%s-%s-%s',
                is_array($row) ? ($row['style_id'] ?? '') : ($row->style_id ?? ''),
                is_array($row) ? ($row['age_group_id'] ?? '') : ($row->age_group_id ?? ''),
                $gender ?? ''
            );
        };

        $nominationOrder = []; // ключ номинации → порядковый номер первого появления

        $prepared = collect($rows)->values()->map(function ($row, int $index) use ($nominationKey, &$nominationOrder) {
            $key = $nominationKey($row);
            if (!array_key_exists($key, $nominationOrder)) {
                $nominationOrder[$key] = count($nominationOrder);
            }

            return [
                'row' => $row,
                'nomination' => $nominationOrder[$key],
                'special' => self::isSpecial($row) ? 1 : 0,
                'index' => $index,
            ];
        });

        return $prepared
            ->sort(fn (array $a, array $b) => [$a['nomination'], $a['special'], $a['index']]
                <=> [$b['nomination'], $b['special'], $b['index']])
            ->map(fn (array $item) => $item['row'])
            ->values();
    }

    /** Отметка строки «группа O» (модель Registration или массив). */
    public static function isSpecial(object|array $row): bool
    {
        $value = is_array($row) ? ($row['is_special'] ?? false) : ($row->is_special ?? false);

        return (bool) $value;
    }
}
