<?php

namespace Tests\Feature;

use App\Models\AgeGroup;
use App\Models\Athlete;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\Style;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Группа «O» (особые спортсмены) — R-6.15, п. 9.16: смоук протоколов,
 * публичной страницы и дипломов при работающей БД.
 *
 * Решения 30.09.2026: подгруппа «(O)» — сразу после основной той же номинации,
 * подпись «(O)», места 1–3 считаются внутри подгруппы.
 */
class SpecialGroupFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $connection = $_SERVER['DB_CONNECTION'] ?? $_ENV['DB_CONNECTION'] ?? 'sqlite';

        $driver = match ($connection) {
            'pgsql' => 'pgsql',
            'mysql', 'mariadb' => 'mysql',
            'sqlsrv' => 'sqlsrv',
            default => 'sqlite',
        };

        if (!in_array($driver, \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped("PDO-драйвер «{$driver}» не установлен (нужен для тестовой БД).");
        }

        parent::setUp();
    }

    public function test_protocols_pdf_split_into_subgroups(): void
    {
        [$competition] = $this->makeSpecialTournament();

        foreach (['start-list', 'final-results', 'title-page', 'team-standings'] as $page) {
            $this->assertPdf($this->get("/competition/{$competition->id}/{$page}"), $page);
        }
    }

    public function test_public_page_shows_o_subgroup_after_main(): void
    {
        $this->makeSpecialTournament();

        $response = $this->get('/results');
        $response->assertOk();

        // Подгруппа «(O)» идёт сразу после основной той же номинации (R-6.15).
        $response->assertSeeInOrder([
            'Чанцюань — Юниоры (12-14 лет)',
            'Чанцюань — Юниоры (12-14 лет) (O)',
        ]);
    }

    public function test_diplomas_are_split_per_subgroup(): void
    {
        [$competition, $style, $styleOnlyO, $group] = $this->makeSpecialTournament();

        // Основная подгруппа: свои дипломы.
        $this->assertPdf(
            $this->get("/competition/{$competition->id}/diplomas/{$style->id}/{$group->id}/male"),
            'diplomas main'
        );

        // Подгруппа «(O)»: свои дипломы.
        $this->assertPdf(
            $this->get("/competition/{$competition->id}/diplomas/{$style->id}/{$group->id}/male/1"),
            'diplomas (O)'
        );

        // Вид, где оценки есть только у группы «O»: основная подгруппа пуста.
        $this->get("/competition/{$competition->id}/diplomas/{$styleOnlyO->id}/{$group->id}/male")
            ->assertSessionHas('error');

        // ...а подгруппа «(O)» печатается.
        $this->assertPdf(
            $this->get("/competition/{$competition->id}/diplomas/{$styleOnlyO->id}/{$group->id}/male/1"),
            'diplomas (O) only'
        );
    }

    /**
     * Турнир: номинация «Чанцюань — Юниоры (12-14 лет)» с основной подгруппой
     * (Иванов 8.500, Сидоров 8.000) и подгруппой «(O)» (Петров 9.000 — его
     * оценка выше, но место 1 в основной подгруппе остаётся за Ивановым),
     * плюс вид «Наньцюань» с оценками только у группы «O».
     *
     * @return array{0: Competition, 1: Style, 2: Style, 3: AgeGroup}
     */
    private function makeSpecialTournament(): array
    {
        $competition = Competition::create([
            'name' => 'Турнир группы O',
            'start_date' => '2026-02-01',
            'city' => 'Москва',
            'status_code' => 1,
        ]);

        $group = AgeGroup::create(['name' => 'Юниоры', 'gender' => 'male', 'min_age' => 12, 'max_age' => 14]);
        $club = Club::create(['name' => 'Клуб ' . str()->random(5)]);

        $makeAthlete = fn (string $name, bool $isSpecial) => Athlete::create([
            'club_id' => $club->id,
            'name' => $name,
            'birth_date' => '2013-05-01',
            'gender' => 'male',
            'is_special' => $isSpecial,
        ]);

        $ivanov = $makeAthlete('Иванов Иван', false);
        $sidorov = $makeAthlete('Сидоров Сидор', false);
        $petrov = $makeAthlete('Петров Пётр', true);

        $style = Style::create(['name' => 'Чанцюань', 'sort_order' => 1]);
        $styleOnlyO = Style::create(['name' => 'Наньцюань', 'sort_order' => 2]);

        $makeReg = fn (Athlete $athlete, Style $style, bool $isSpecial, float $score, int $order) => Registration::create([
            'competition_id' => $competition->id,
            'athlete_id' => $athlete->id,
            'style_id' => $style->id,
            'age_group_id' => $group->id,
            'sort_order' => $order,
            'is_completed' => true,
            'final_score' => $score,
            'is_special' => $isSpecial,
        ]);

        // Чанцюань: основная подгруппа + «(O)» (финальная отметка — в заявке).
        $makeReg($ivanov, $style, false, 8.500, 1);
        $makeReg($sidorov, $style, false, 8.000, 2);
        $makeReg($petrov, $style, true, 9.000, 3);

        // Наньцюань: оценки только у группы «O».
        $makeReg($petrov, $styleOnlyO, true, 9.200, 4);

        return [$competition, $style, $styleOnlyO, $group];
    }

    private function assertPdf(TestResponse $response, string $page): void
    {
        $response->assertOk();

        $body = $response->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse
            ? $response->streamedContent()
            : (string) $response->getContent();

        $this->assertStringStartsWith('%PDF', $body, "Страница «{$page}» должна отдавать PDF.");
    }
}
