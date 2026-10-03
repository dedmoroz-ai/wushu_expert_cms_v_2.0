<?php

namespace Tests\Feature;

use App\Filament\Pages\ScoresSummary;
use App\Models\AgeGroup;
use App\Models\Athlete;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\Score;
use App\Models\Style;
use App\Models\User;
use App\Support\ScoresSummaryMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Сводная таблица оценок судей (страница ScoresSummary + PDF scores-summary):
 * матрица учитывает схему судейства — простую и A/B (R-2.11, R-4.6, R-4.18–R-4.20).
 */
class ScoresSummaryTest extends TestCase
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

        if (! in_array($driver, \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped("PDO-драйвер «{$driver}» не установлен (нужен для тестовой БД).");
        }

        parent::setUp();
    }

    /** Простая схема: все судьи в одной группе, среднее = trimmedMean (R-4.6). */
    public function test_simple_scheme_matrix_uses_trimmed_mean_and_marks_min_max(): void
    {
        [$competition, $reg, $judges] = $this->makeTournament(Competition::SCHEME_SIMPLE);

        Score::create(['registration_id' => $reg->id, 'judge_id' => $judges['j1']->id, 'score' => 8.0]);
        Score::create(['registration_id' => $reg->id, 'judge_id' => $judges['j2']->id, 'score' => 8.5]);
        Score::create(['registration_id' => $reg->id, 'judge_id' => $judges['j3']->id, 'score' => 9.0]);

        $matrix = ScoresSummaryMatrix::build($competition->fresh());

        $this->assertFalse($matrix['isAb']);
        $this->assertSame([ScoresSummaryMatrix::GROUP_NONE], $matrix['groupsOrder']);

        $row = $matrix['rows'][0];

        // 3 оценки: мин. и макс. отброшены → 8.500.
        $this->assertSame(8.5, $row['groups'][ScoresSummaryMatrix::GROUP_NONE]['avg']);
        $this->assertSame(8.5, $row['avg']);
        $this->assertSame($judges['j1']->id, $row['groups'][ScoresSummaryMatrix::GROUP_NONE]['minJudgeId']);
        $this->assertSame($judges['j3']->id, $row['groups'][ScoresSummaryMatrix::GROUP_NONE]['maxJudgeId']);
        $this->assertSame(8.5, $row['final']);
    }

    /** A/B: судьи сгруппированы по панелям, итог расчёта = среднее A + среднее B (R-4.20). */
    public function test_ab_scheme_matrix_groups_panels_and_totals(): void
    {
        [$competition, $reg, $judges] = $this->makeTournament(Competition::SCHEME_AB);

        // Панель A: 4.500, 4.700, 4.900 → 4.700 (без мин./макс.).
        Score::create(['registration_id' => $reg->id, 'judge_id' => $judges['j1']->id, 'score' => 4.5, 'panel' => 'A']);
        Score::create(['registration_id' => $reg->id, 'judge_id' => $judges['j2']->id, 'score' => 4.7, 'panel' => 'A']);
        Score::create(['registration_id' => $reg->id, 'judge_id' => $judges['j3']->id, 'score' => 4.9, 'panel' => 'A']);
        // Панель B: 4.000, 4.200 → 4.100 (2 оценки без отбрасывания).
        Score::create(['registration_id' => $reg->id, 'judge_id' => $judges['j4']->id, 'score' => 4.0, 'panel' => 'B']);
        Score::create(['registration_id' => $reg->id, 'judge_id' => $judges['j5']->id, 'score' => 4.2, 'panel' => 'B']);

        $matrix = ScoresSummaryMatrix::build($competition->fresh());

        $this->assertTrue($matrix['isAb']);
        $this->assertSame(
            [Competition::PANEL_A, Competition::PANEL_B, ScoresSummaryMatrix::GROUP_NONE],
            $matrix['groupsOrder']
        );

        // Судьи разложены по панелям назначения.
        $this->assertCount(3, $matrix['judgesByGroup'][Competition::PANEL_A]);
        $this->assertCount(2, $matrix['judgesByGroup'][Competition::PANEL_B]);
        $this->assertCount(0, $matrix['judgesByGroup'][ScoresSummaryMatrix::GROUP_NONE]);

        $row = $matrix['rows'][0];

        $this->assertSame(4.7, $row['groups'][Competition::PANEL_A]['avg']);
        $this->assertSame(4.1, $row['groups'][Competition::PANEL_B]['avg']);
        $this->assertSame($judges['j1']->id, $row['groups'][Competition::PANEL_A]['minJudgeId']);
        $this->assertSame($judges['j3']->id, $row['groups'][Competition::PANEL_A]['maxJudgeId']);

        // Расчёт A/B = 4.700 + 4.100 = 8.800.
        $this->assertSame(8.8, $row['avg']);
    }

    /** Оценка, выставленная не в своей функции, показывается, но не участвует в расчёте (R-4.18). */
    public function test_ab_score_out_of_own_panel_is_not_counted(): void
    {
        [$competition, $reg, $judges] = $this->makeTournament(Competition::SCHEME_AB);

        Score::create(['registration_id' => $reg->id, 'judge_id' => $judges['j1']->id, 'score' => 4.5, 'panel' => 'A']);
        // Судья A поставил оценку в функции B — не засчитывается.
        Score::create(['registration_id' => $reg->id, 'judge_id' => $judges['j2']->id, 'score' => 4.7, 'panel' => 'B']);
        Score::create(['registration_id' => $reg->id, 'judge_id' => $judges['j4']->id, 'score' => 4.0, 'panel' => 'B']);

        $matrix = ScoresSummaryMatrix::build($competition->fresh());
        $row = $matrix['rows'][0];

        $this->assertTrue($row['counted'][$judges['j1']->id]);
        $this->assertFalse($row['counted'][$judges['j2']->id]);
        $this->assertTrue($row['counted'][$judges['j4']->id]);

        // В расчёте панели A — только 4.500; панель B — только 4.000.
        $this->assertSame(4.5, $row['groups'][Competition::PANEL_A]['avg']);
        $this->assertSame(4.0, $row['groups'][Competition::PANEL_B]['avg']);
        $this->assertSame(8.5, $row['avg']);
    }

    /** Админ видит страницу сводки и PDF. */
    public function test_admin_sees_scores_summary_page_and_pdf(): void
    {
        [$competition, $reg, $judges] = $this->makeTournament(Competition::SCHEME_SIMPLE);
        Score::create(['registration_id' => $reg->id, 'judge_id' => $judges['j1']->id, 'score' => 8.0]);
        Score::create(['registration_id' => $reg->id, 'judge_id' => $judges['j2']->id, 'score' => 8.5]);
        Score::create(['registration_id' => $reg->id, 'judge_id' => $judges['j3']->id, 'score' => 9.0]);

        $admin = $this->makeUser('admin@test.local', 'admin');

        $this->actingAs($admin)
            ->get('/admin/scores-summary?competitionId='.$competition->id)
            ->assertOk()
            ->assertSee('Сводная таблица оценок судей');

        $this->actingAs($admin)
            ->get(route('competition.scores-summary', $competition))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    /**
     * Кнопка «Скачать PDF» ведёт на PDF-роут и открывает PDF в браузере (inline),
     * а не вызывает печать; вёрстка таблицы адаптирована для мобильных (03.10).
     */
    public function test_pdf_button_links_to_inline_pdf_and_layout_is_mobile_friendly(): void
    {
        [$competition, $reg, $judges] = $this->makeTournament(Competition::SCHEME_SIMPLE);
        Score::create(['registration_id' => $reg->id, 'judge_id' => $judges['j1']->id, 'score' => 8.0]);

        $admin = $this->makeUser('admin-pdf@test.local', 'admin');

        $this->actingAs($admin)
            ->get('/admin/scores-summary?competitionId='.$competition->id)
            ->assertOk()
            ->assertSee('href="'.route('competition.scores-summary', $competition).'"', false)
            ->assertSee('target="_blank"', false)
            ->assertSee('Скачать PDF')
            ->assertSee('Печать')
            ->assertSee('@media (max-width: 767px)', false)
            ->assertSee('min-width: 720px', false);

        // PDF открывается в браузере (Content-Disposition: inline), а не скачивается файлом.
        $response = $this->actingAs($admin)->get(route('competition.scores-summary', $competition));
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
    }

    /**
     * PDF-шаблон выводит ФИО спортсмена и партнёра (в модели Athlete одно поле name;
     * раньше шаблон читал несуществующие last_name/first_name — вместо имён стояло «—»),
     * а таблица укладывается в ширину листа: table-layout: fixed + процентные ширины
     * колонок (иначе dompdf расширял её за край страницы, и правые колонки обрезались).
     */
    public function test_pdf_template_shows_athlete_names_and_fits_table_to_page_width(): void
    {
        [$competition, $reg, $judges] = $this->makeTournament(Competition::SCHEME_SIMPLE);
        Score::create(['registration_id' => $reg->id, 'judge_id' => $judges['j1']->id, 'score' => 8.0]);

        $partner = Athlete::create([
            'club_id' => $reg->athlete->club_id,
            'name' => 'Петрова Мария',
            'birth_date' => '2013-06-01',
            'gender' => 'female',
        ]);
        $reg->update(['partner_id' => $partner->id]);

        $html = view('pdf.scores-summary', [
            'competition' => $competition,
            'matrix' => ScoresSummaryMatrix::build($competition->fresh()),
            'dateStr' => '1 марта 2026 г.',
            'warn' => ScoresSummary::WARN_THRESHOLD,
            'danger' => ScoresSummary::DANGER_THRESHOLD,
        ])->render();

        // ФИО спортсмена и партнёра видны, «—» вместо имени не подставляется.
        $this->assertStringContainsString('Иванов Иван', $html);
        $this->assertStringContainsString('Петрова Мария', $html);
        $this->assertStringNotContainsString('<b>—</b>', $html);

        // Таблица фиксирована по ширине листа, длинный текст переносится.
        $this->assertStringContainsString('table-layout: fixed', $html);
        $this->assertStringContainsString('overflow-wrap: anywhere', $html);
        $this->assertStringContainsString('width:22%', $html);
    }

    /** Рендер A/B: двухуровневая шапка панелей на странице и в PDF. */
    public function test_ab_page_and_pdf_render_with_panel_headers(): void
    {
        [$competition, $reg, $judges] = $this->makeTournament(Competition::SCHEME_AB);
        Score::create(['registration_id' => $reg->id, 'judge_id' => $judges['j1']->id, 'score' => 4.5, 'panel' => 'A']);
        Score::create(['registration_id' => $reg->id, 'judge_id' => $judges['j2']->id, 'score' => 4.7, 'panel' => 'A']);
        Score::create(['registration_id' => $reg->id, 'judge_id' => $judges['j3']->id, 'score' => 4.9, 'panel' => 'A']);
        Score::create(['registration_id' => $reg->id, 'judge_id' => $judges['j4']->id, 'score' => 4.0, 'panel' => 'B']);
        Score::create(['registration_id' => $reg->id, 'judge_id' => $judges['j5']->id, 'score' => 4.2, 'panel' => 'B']);

        $admin = $this->makeUser('admin-ab@test.local', 'admin');

        $this->actingAs($admin)
            ->get('/admin/scores-summary?competitionId='.$competition->id)
            ->assertOk()
            ->assertSee('Панель A — качество исполнения (сбавки)')
            ->assertSee('Панель B — общее впечатление')
            ->assertSee('Расчёт (A+B)');

        $this->actingAs($admin)
            ->get(route('competition.scores-summary', $competition))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        // PDF-шаблон A/B: имена на месте, таблица укладывается в ширину листа.
        $html = view('pdf.scores-summary', [
            'competition' => $competition,
            'matrix' => ScoresSummaryMatrix::build($competition->fresh()),
            'dateStr' => '1 марта 2026 г.',
            'warn' => ScoresSummary::WARN_THRESHOLD,
            'danger' => ScoresSummary::DANGER_THRESHOLD,
        ])->render();

        $this->assertStringContainsString('Иванов Иван', $html);
        $this->assertStringContainsString('table-layout: fixed', $html);
        $this->assertStringContainsString('width:22%', $html);
        $this->assertStringContainsString('Расчёт (A+B)', $html);
    }

    /** Судья не из списка доступа получает 403 и на странице, и в PDF. */
    public function test_stranger_gets_403_on_scores_summary_page_and_pdf(): void
    {
        [$competition, $reg, $judges] = $this->makeTournament(Competition::SCHEME_SIMPLE);
        Score::create(['registration_id' => $reg->id, 'judge_id' => $judges['j1']->id, 'score' => 8.0]);

        $stranger = $this->makeUser('stranger@test.local', 'judge');

        $this->actingAs($stranger)
            ->get('/admin/scores-summary?competitionId='.$competition->id)
            ->assertForbidden();

        $this->actingAs($stranger)
            ->get(route('competition.scores-summary', $competition))
            ->assertForbidden();
    }

    /**
     * @return array{0: Competition, 1: Registration, 2: array<string, User>}
     */
    private function makeTournament(string $scheme): array
    {
        $competition = Competition::create([
            'name' => 'Сводка турнир',
            'start_date' => '2026-03-01',
            'city' => 'Москва',
            'status_code' => 1,
            'judging_scheme' => $scheme,
        ]);

        $group = AgeGroup::create(['name' => 'Юниоры', 'gender' => 'male', 'min_age' => 12, 'max_age' => 14]);
        $club = Club::create(['name' => 'Клуб '.str()->random(5)]);
        $athlete = Athlete::create([
            'club_id' => $club->id,
            'name' => 'Иванов Иван',
            'birth_date' => '2013-05-01',
            'gender' => 'male',
        ]);
        $style = Style::create(['name' => 'Чанцюань', 'sort_order' => 1]);

        $reg = Registration::create([
            'competition_id' => $competition->id,
            'athlete_id' => $athlete->id,
            'style_id' => $style->id,
            'age_group_id' => $group->id,
            'sort_order' => 1,
            'is_completed' => true,
            'final_score' => 8.5,
        ]);

        $judges = [
            'j1' => $this->makeUser('j1'.str()->random(6).'@test.local', 'judge', 'Судья А'),
            'j2' => $this->makeUser('j2'.str()->random(6).'@test.local', 'judge', 'Судья Б'),
            'j3' => $this->makeUser('j3'.str()->random(6).'@test.local', 'judge', 'Судья В'),
            'j4' => $this->makeUser('j4'.str()->random(6).'@test.local', 'judge', 'Судья Г'),
            'j5' => $this->makeUser('j5'.str()->random(6).'@test.local', 'judge', 'Судья Д'),
        ];

        if ($scheme === Competition::SCHEME_AB) {
            $competition->judges()->attach($judges['j1']->id, ['panel' => 'A']);
            $competition->judges()->attach($judges['j2']->id, ['panel' => 'A']);
            $competition->judges()->attach($judges['j3']->id, ['panel' => 'A']);
            $competition->judges()->attach($judges['j4']->id, ['panel' => 'B']);
            $competition->judges()->attach($judges['j5']->id, ['panel' => 'B']);
        } else {
            foreach ($judges as $judge) {
                $competition->judges()->attach($judge->id);
            }
        }

        return [$competition->fresh(), $reg, $judges];
    }

    private function makeUser(string $email, string $role, string $name = 'Пользователь'): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('secret'),
            'role' => $role,
            'is_active_judge' => $role !== 'admin',
        ]);
    }
}
