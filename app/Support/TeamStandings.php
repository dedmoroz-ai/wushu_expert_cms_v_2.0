<?php

namespace App\Support;

use App\Models\Competition;
use Illuminate\Support\Collection;

/**
 * Командный (клубный) зачёт — R-6.14 / п. 9.13 (docs/JUDGING_RULES.md).
 *
 * Решения заказчика от 30.09.2026:
 *  - Шкала очков «очки за медаль»: 1 место — 3, 2 место — 2, 3 место — 1.
 *    Учитываются только места 1–3 (как дипломы).
 *  - Категория и места определяются как для дипломов (R-6.4):
 *    style_id + age_group_id + пол спортсмена; равные баллы делят место
 *    (нумерация 1, 2, 2, 4).
 *  - Пары: медаль каждому участнику (пары всегда из одного клуба),
 *    то есть пара на 1 месте приносит клубу 2 медали = 6 очков.
 *  - Ничьи клубов в итоговой таблице: делят места (одинаковые очки = одно
 *    место), тайбрейка по сумме баллов нет.
 *  - Участники (и партнёры) без клуба в зачёт не идут.
 */
final class TeamStandings
{
    /** Очки за место («очки за медаль» 3–2–1). */
    public const POINTS_BY_PLACE = [1 => 3, 2 => 2, 3 => 1];

    /**
     * Таблица командного зачёта для соревнования.
     *
     * @return Collection<int, array{place: int, club_id: int|string|null, club_name: string, points: int}>
     */
    public static function forCompetition(Competition $competition): Collection
    {
        $registrations = $competition->registrations()
            ->with(['athlete.club', 'partner.club'])
            ->where('is_completed', true)
            ->whereNotNull('final_score')
            ->get();

        $entries = $registrations->map(function ($reg) {
            // Медаль каждому участнику: пара приносит клубу две медали
            // (по одной на каждого; пары всегда из одного клуба).
            $clubs = [];
            foreach ([$reg->athlete, $reg->partner] as $person) {
                if ($person && $person->club) {
                    $clubs[] = [
                        'id' => $person->club->id,
                        'name' => $person->club->name ?? '',
                    ];
                }
            }

            return [
                // Категория как для дипломов (R-6.4). Группа «O» (R-6.15): места
                // считаются внутри подгруппы, поэтому флаг входит в ключ категории;
                // медали группы «O» при этом включаются в общий зачёт (решение #2).
                'category' => self::categoryKey($reg->style_id, $reg->age_group_id, $reg->athlete?->gender, (bool) $reg->is_special),
                'score' => (float) $reg->final_score,
                'clubs' => array_values($clubs),
            ];
        });

        return self::compute($entries);
    }

    /**
     * Ключ категории: style_id-age_group_id-пол[‑O]. Для группы «O» — отдельный
     * ключ, чтобы места 1–3 считались внутри подгруппы (R-6.15).
     */
    public static function categoryKey($styleId, $ageGroupId, ?string $gender, bool $isSpecial = false): string
    {
        return sprintf('%s-%s-%s%s', $styleId, $ageGroupId, $gender, $isSpecial ? '-O' : '');
    }

    /**
     * Чистый алгоритм без БД (тестируемый юнит-тестами).
     *
     * Каждое выступление:
     *   ['category' => string, 'score' => float,
     *    'clubs' => list<array{id: int|string, name: string}>]
     *
     * @param  iterable<int, array{category: string, score: float, clubs: list<array{id: int|string, name: string}>}>  $entries
     * @return Collection<int, array{place: int, club_id: int|string|null, club_name: string, points: int}>
     */
    public static function compute(iterable $entries): Collection
    {
        $totals = []; // clubId => ['name' => string, 'points' => int]

        foreach (collect($entries)->groupBy('category') as $categoryEntries) {
            $sorted = $categoryEntries->sortByDesc('score')->values();

            // Места с общими позициями при равных баллах (как в diplomas()): 1, 2, 2, 4.
            $place = 1;
            $prevScore = null;
            $index = 0;

            foreach ($sorted as $entry) {
                $score = (float) $entry['score'];

                if ($prevScore === null) {
                    $place = 1;
                } elseif ($score < $prevScore) {
                    $place = $index + 1;
                }

                $prevScore = $score;

                $points = self::POINTS_BY_PLACE[$place] ?? 0;
                if ($points > 0) {
                    foreach ($entry['clubs'] as $club) {
                        $clubId = $club['id'];
                        if (!isset($totals[$clubId])) {
                            $totals[$clubId] = ['name' => $club['name'], 'points' => 0];
                        }
                        $totals[$clubId]['points'] += $points;
                    }
                }

                $index++;
            }
        }

        $rows = collect($totals)
            ->map(fn ($total, $clubId) => [
                'club_id' => $clubId,
                'club_name' => $total['name'],
                'points' => $total['points'],
            ])
            ->values()
            ->sortByDesc('points')
            ->values();

        // Ничьи клубов: делят места (1, 2, 2, 4), без тайбрейка.
        $standings = collect();
        $place = 1;
        $prevPoints = null;

        foreach ($rows as $index => $row) {
            if ($prevPoints === null) {
                $place = 1;
            } elseif ($row['points'] < $prevPoints) {
                $place = $index + 1;
            }

            $prevPoints = $row['points'];

            $standings->push([
                'place' => $place,
                'club_id' => $row['club_id'],
                'club_name' => $row['club_name'],
                'points' => $row['points'],
            ]);
        }

        return $standings;
    }
}