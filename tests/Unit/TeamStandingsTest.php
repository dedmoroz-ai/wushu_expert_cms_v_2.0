<?php

namespace Tests\Unit;

use App\Support\TeamStandings;
use PHPUnit\Framework\TestCase;

/**
 * Командный (клубный) зачёт — R-6.14, п. 9.13 (docs/JUDGING_RULES.md).
 *
 * Решения 30.09.2026: очки за медаль 3–2–1 (только места 1–3, как дипломы),
 * категории и места — как в дипломах (R-6.4, равные баллы делят место),
 * пара — медаль каждому участнику, ничьи клубов — делят места (без тайбрейка).
 */
class TeamStandingsTest extends TestCase
{
    private function entry(string $category, float $score, array $clubs): array
    {
        return ['category' => $category, 'score' => $score, 'clubs' => $clubs];
    }

    private function club(int $id): array
    {
        return ['id' => $id, 'name' => 'Клуб ' . $id];
    }

    public function test_points_3_2_1_for_top_three_only(): void
    {
        $standings = TeamStandings::compute([
            $this->entry('c1', 5.0, [$this->club(1)]),
            $this->entry('c1', 4.8, [$this->club(2)]),
            $this->entry('c1', 4.6, [$this->club(3)]),
            $this->entry('c1', 4.4, [$this->club(4)]),
        ]);

        // Клуб 4 — без медали, в таблицу не попадает.
        $this->assertCount(3, $standings);
        $this->assertSame(
            [
                ['place' => 1, 'club_id' => 1, 'club_name' => 'Клуб 1', 'points' => 3],
                ['place' => 2, 'club_id' => 2, 'club_name' => 'Клуб 2', 'points' => 2],
                ['place' => 3, 'club_id' => 3, 'club_name' => 'Клуб 3', 'points' => 1],
            ],
            $standings->all()
        );
    }

    public function test_equal_scores_share_athlete_places(): void
    {
        // Два первых места делят победу (по 3 очка), следующее — 3-е (1 очко).
        $standings = TeamStandings::compute([
            $this->entry('c1', 5.0, [$this->club(1)]),
            $this->entry('c1', 5.0, [$this->club(2)]),
            $this->entry('c1', 4.5, [$this->club(3)]),
        ]);

        $this->assertSame(3, $standings->firstWhere('club_id', 1)['points']);
        $this->assertSame(3, $standings->firstWhere('club_id', 2)['points']);
        $this->assertSame(1, $standings->firstWhere('club_id', 3)['points']);
        $this->assertSame(1, $standings->firstWhere('club_id', 1)['place']);
        $this->assertSame(1, $standings->firstWhere('club_id', 2)['place']);
        $this->assertSame(3, $standings->firstWhere('club_id', 3)['place']);
    }

    public function test_pair_gives_a_medal_to_each_participant(): void
    {
        // Пара на 1 месте: медаль каждому участнику — клуб получает 2 × 3 = 6 очков.
        $standings = TeamStandings::compute([
            $this->entry('c1', 5.0, [$this->club(1), $this->club(1)]),
            $this->entry('c1', 4.5, [$this->club(2)]),
        ]);

        $this->assertSame(6, $standings->firstWhere('club_id', 1)['points']);
        $this->assertSame(2, $standings->firstWhere('club_id', 2)['points']);
    }

    public function test_pair_split_between_clubs_awards_each_club(): void
    {
        // Защитная ветка: если партнёры из разных клубов — каждый клуб получает медаль.
        $standings = TeamStandings::compute([
            $this->entry('c1', 5.0, [$this->club(1), $this->club(2)]),
        ]);

        $this->assertSame(3, $standings->firstWhere('club_id', 1)['points']);
        $this->assertSame(3, $standings->firstWhere('club_id', 2)['points']);
    }

    public function test_clubs_with_equal_points_share_places(): void
    {
        // Ничьи клубов: делят места (1, 1, 3, 3, 5), без тайбрейка по баллам.
        $standings = TeamStandings::compute([
            $this->entry('c1', 5.0, [$this->club(1)]), // 3
            $this->entry('c1', 4.8, [$this->club(2)]), // 2
            $this->entry('c1', 4.6, [$this->club(5)]), // 1
            $this->entry('c2', 5.0, [$this->club(4)]), // 3
            $this->entry('c2', 4.8, [$this->club(3)]), // 2
        ]);

        $this->assertSame(1, $standings->firstWhere('club_id', 1)['place']);
        $this->assertSame(1, $standings->firstWhere('club_id', 4)['place']);
        $this->assertSame(3, $standings->firstWhere('club_id', 2)['place']);
        $this->assertSame(3, $standings->firstWhere('club_id', 3)['place']);
        $this->assertSame(5, $standings->firstWhere('club_id', 5)['place']);
    }

    public function test_points_accumulate_across_categories(): void
    {
        $standings = TeamStandings::compute([
            $this->entry('c1', 5.0, [$this->club(1)]),
            $this->entry('c2', 5.0, [$this->club(1)]),
            $this->entry('c2', 4.9, [$this->club(2)]),
        ]);

        $this->assertSame(6, $standings->firstWhere('club_id', 1)['points']);
        $this->assertSame(2, $standings->firstWhere('club_id', 2)['points']);
    }

    public function test_participants_without_club_are_ignored(): void
    {
        // Победитель без клуба не даёт очков никому, но место занимает.
        $standings = TeamStandings::compute([
            $this->entry('c1', 5.0, []),
            $this->entry('c1', 4.8, [$this->club(2)]),
            $this->entry('c1', 4.6, [$this->club(3)]),
        ]);

        $this->assertCount(2, $standings);
        $this->assertSame(2, $standings->firstWhere('club_id', 2)['points']);
        $this->assertSame(1, $standings->firstWhere('club_id', 3)['points']);
    }

    public function test_empty_entries_give_empty_table(): void
    {
        $this->assertCount(0, TeamStandings::compute([]));
    }
}