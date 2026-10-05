<?php

namespace Tests\Feature;

use App\Models\AgeGroup;
use App\Models\Athlete;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\Score;
use App\Models\Style;
use App\Models\User;
use App\Support\CompetitionAnalyticsBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Детерминированные метрики судейства для AI-аналитики
 * (CompetitionAnalyticsBuilder, docs/ANALYTICS.md): числа считаются без LLM,
 * методика совпадает со «Сводной таблицей оценок» (R-4.6).
 */
class CompetitionAnalyticsBuilderTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, User> бригада текущего турнира */
    private array $judges = [];

    private int $sortOrder = 0;

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

    /** Итоги, метрики судей и разбросы считаются по R-4.6. */
    public function test_totals_judge_metrics_and_spread(): void
    {
        $competition = $this->makeCompetition();
        [$reg1, $judges] = $this->makeRegistration($competition, 'Чанцюань', 'Юноши', 'Спортсмен Первый');
        [$reg2] = $this->makeRegistration($competition, 'Чанцюань', 'Юноши', 'Спортсмен Второй');

        // Выступление 1: 8.0 / 8.5 / 9.0 → trimmedMean 8.5, разброс 1.0.
        Score::create(['registration_id' => $reg1->id, 'judge_id' => $judges[0]->id, 'score' => 8.0]);
        Score::create(['registration_id' => $reg1->id, 'judge_id' => $judges[1]->id, 'score' => 8.5]);
        Score::create(['registration_id' => $reg1->id, 'judge_id' => $judges[2]->id, 'score' => 9.0]);
        $reg1->update(['final_score' => 8.5]);

        // Выступление 2: 8.0 / 8.2 / 8.4 → trimmedMean 8.2, разброс 0.4.
        Score::create(['registration_id' => $reg2->id, 'judge_id' => $judges[0]->id, 'score' => 8.0]);
        Score::create(['registration_id' => $reg2->id, 'judge_id' => $judges[1]->id, 'score' => 8.2]);
        Score::create(['registration_id' => $reg2->id, 'judge_id' => $judges[2]->id, 'score' => 8.4]);
        $reg2->update(['final_score' => 8.2]);

        $metrics = CompetitionAnalyticsBuilder::build($competition->fresh());

        $this->assertSame(2, $metrics['totals']['completed']);
        $this->assertSame(6, $metrics['totals']['scores']);
        $this->assertSame(3, $metrics['totals']['judges']);

        // Судьи отсортированы по Δ (сначала «щедрые»).
        $byName = collect($metrics['judges'])->keyBy('name');
        $third = $byName['Судья В'];
        $second = $byName['Судья Б'];
        $first = $byName['Судья А'];

        // Судья В: (0.5 + 0.2) / 2 — всегда выше итога строки.
        $this->assertSame(0.35, $third['delta']);
        // Судья А: (−0.5 + −0.2) / 2.
        $this->assertSame(-0.35, $first['delta']);
        // Судья Б: (0 + 0) / 2.
        $this->assertSame(0.0, $second['delta']);

        // Отсечения: минимум в обеих строках — у судьи А, максимум — у судьи В.
        $this->assertSame(2, $first['drop_min']);
        $this->assertSame(2, $third['drop_max']);
        $this->assertSame(0, $second['drop_min']);
        $this->assertSame(0, $second['drop_max']);

        // Разбросы: среднее (1.0 + 0.4) / 2, максимум 1.0, «≥ 0.7» — одно.
        $this->assertSame(0.7, $metrics['spread']['mean']);
        $this->assertSame(1.0, $metrics['spread']['max']);
        $this->assertSame(1, $metrics['spread']['ge_07']);
        $this->assertCount(2, $metrics['topSpreads']);
        $this->assertSame(1.0, $metrics['topSpreads'][0]['spread']);
        $this->assertSame('Спортсмен Первый', $metrics['topSpreads'][0]['athlete']);
    }

    /** Пулы: места по официальному итогу, ничья, отрыв победителя. */
    public function test_pools_rank_official_finals_with_ties(): void
    {
        $competition = $this->makeCompetition();
        [$regA, $judges] = $this->makeRegistration($competition, 'Гунь', 'Юноши', 'Победитель');
        [$regB] = $this->makeRegistration($competition, 'Гунь', 'Юноши', 'Серебро');
        [$regC] = $this->makeRegistration($competition, 'Гунь', 'Юноши', 'Бронза');

        foreach ([[$regA, 8.6], [$regB, 8.0], [$regC, 8.0]] as [$reg, $value]) {
            foreach ($judges as $judge) {
                Score::create(['registration_id' => $reg->id, 'judge_id' => $judge->id, 'score' => $value]);
            }
            $reg->update(['final_score' => $value]);
        }

        $metrics = CompetitionAnalyticsBuilder::build($competition->fresh());

        $this->assertCount(1, $metrics['pools']);
        $pool = $metrics['pools'][0];
        $this->assertSame('Гунь | Юноши', $pool['key']);
        $this->assertSame(3, $pool['n']);
        $this->assertSame(0.6, $pool['gap']);

        // 1-е место у Победителя; Серебро и Бронза — ничья (оба 2-е).
        $this->assertSame(1, $pool['athletes'][0]['place']);
        $this->assertSame('Победитель', $pool['athletes'][0]['athlete']);
        $this->assertSame(2, $pool['athletes'][1]['place']);
        $this->assertSame(2, $pool['athletes'][2]['place']);
    }

    /**
     * Правило R-4.6 меняет подиум — секция flips фиксирует это;
     * переписанный вручную итог попадает в mismatches.
     */
    public function test_flips_and_mismatches_sections(): void
    {
        $competition = $this->makeCompetition();
        [$regA, $judges] = $this->makeRegistration($competition, 'Бинци', 'Женщины', 'Первая');
        [$regB] = $this->makeRegistration($competition, 'Бинци', 'Женщины', 'Вторая');
        [$regC] = $this->makeRegistration($competition, 'Бинци', 'Женщины', 'Третья');

        // 4 оценки: trim 8.45, plain 8.375 → по trim первая, по plain — вторая.
        $this->fillScores($regA, $judges, [8.0, 8.4, 8.5, 8.6]);
        $regA->update(['final_score' => 8.45]);

        // trim 8.40, plain 8.40 → по plain первая.
        $this->fillScores($regB, $judges, [8.2, 8.3, 8.5, 8.6]);
        $regB->update(['final_score' => 8.4]);

        $this->fillScores($regC, $judges, [8.1, 8.2, 8.2, 8.3]);
        // Официальный итог переписан вручную (авто-расчёт 8.20).
        $regC->update(['final_score' => 8.0]);

        $metrics = CompetitionAnalyticsBuilder::build($competition->fresh());

        $this->assertCount(1, $metrics['flips']);
        $flip = $metrics['flips'][0];
        $this->assertSame('Бинци | Женщины', $flip['key']);
        $this->assertSame(['A1', 'A2', 'A3'], $flip['trim_top']);
        $this->assertSame(['A2', 'A1', 'A3'], $flip['plain_top']);

        $this->assertCount(1, $metrics['mismatches']);
        $mismatch = $metrics['mismatches'][0];
        $this->assertSame('Третья', $mismatch['athlete']);
        $this->assertSame(8.2, $mismatch['auto']);
        $this->assertSame(8.0, $mismatch['final']);
        $this->assertSame(-0.2, $mismatch['diff']);
    }

    /**
     * LLM-дайджест минимизирует персональные данные: спортсмены — коды A1…,
     * судьи — коды S1…; ФИО остаются только во внутренних полях для blade.
     */
    public function test_llm_digest_payload_is_coded(): void
    {
        $competition = $this->makeCompetition();
        [$reg, $judges] = $this->makeRegistration($competition, 'Чанцюань', 'Юноши', 'Иванов Иван');

        Score::create(['registration_id' => $reg->id, 'judge_id' => $judges[0]->id, 'score' => 8.0]);
        Score::create(['registration_id' => $reg->id, 'judge_id' => $judges[1]->id, 'score' => 8.2]);
        Score::create(['registration_id' => $reg->id, 'judge_id' => $judges[2]->id, 'score' => 8.4]);
        $reg->update(['final_score' => 8.2]);

        $metrics = CompetitionAnalyticsBuilder::build($competition->fresh());
        $payload = CompetitionAnalyticsBuilder::llmDigest($metrics);

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('Иванов Иван', $json);
        $this->assertStringNotContainsString('Судья А', $json);
        $this->assertStringContainsString('"S1"', $json);
        $this->assertStringContainsString('"A1"', $json);
        $this->assertArrayNotHasKey('name', $payload['judges'][0]);
        $this->assertArrayNotHasKey('athlete', $payload['pools'][0]['athletes'][0]);
    }

    /** ФИО разрешены только призёрам фокусных пулов (фокус = N ≥ 3, до 3 пулов). */
    public function test_focus_pool_winners_keep_names_in_digest(): void
    {
        $competition = $this->makeCompetition();
        [$regA, $judges] = $this->makeRegistration($competition, 'Дуаньбин', 'Мужчины', 'Лукашенко Юрий');
        [$regB] = $this->makeRegistration($competition, 'Дуаньбин', 'Мужчины', 'Аметов Сеитосман');
        [$regC] = $this->makeRegistration($competition, 'Дуаньбин', 'Мужчины', 'Килык Кирилл');

        $this->fillScores($regA, $judges, [8.3, 8.9, 9.1, 9.6]);
        $regA->update(['final_score' => 9.0]);
        $this->fillScores($regB, $judges, [8.1, 8.4, 8.5, 8.9]);
        $regB->update(['final_score' => 8.45]);
        $this->fillScores($regC, $judges, [8.2, 8.3, 8.4, 8.8]);
        $regC->update(['final_score' => 8.35]);

        $metrics = CompetitionAnalyticsBuilder::build($competition->fresh());
        $payload = CompetitionAnalyticsBuilder::llmDigest($metrics);

        $this->assertSame(['Дуаньбин | Мужчины'], $metrics['focusKeys']);

        $winners = $payload['focus_pools'][0]['athletes'];
        $this->assertSame('Лукашенко Юрий', $winners[0]['name']);
        $this->assertSame('Аметов Сеитосман', $winners[1]['name']);
        $this->assertSame('Килык Кирилл', $winners[2]['name']);
    }

    /** Турнир без завершённых выступлений: метрики нулевые, LLM не нужен. */
    public function test_build_returns_empty_metrics_for_unjudged_competition(): void
    {
        $competition = $this->makeCompetition();
        [$reg] = $this->makeRegistration($competition, 'Чанцюань', 'Юноши', 'Неоценённый');

        // Заявка без оценок и без итога — не завершена.
        $reg->update(['is_completed' => false, 'final_score' => null]);

        $metrics = CompetitionAnalyticsBuilder::build($competition->fresh());

        $this->assertSame(0, $metrics['totals']['completed']);
        $this->assertSame([], $metrics['judges']);
        $this->assertSame([], $metrics['pools']);
        $this->assertNull($metrics['spread']['mean']);
    }

    private function makeCompetition(): Competition
    {
        $competition = Competition::create([
            'name' => 'AI-аналитика турнир',
            'start_date' => '2026-03-01',
            'city' => 'Москва',
            'status_code' => 3,
            'judging_scheme' => Competition::SCHEME_SIMPLE,
        ]);

        // Бригада общая для турнира (как на сервере — 6 судей на все выступления).
        $this->judges = [];
        foreach (['Судья А', 'Судья Б', 'Судья В', 'Судья Г'] as $name) {
            $judge = User::create([
                'name' => $name,
                'email' => str()->random(8).'@test.local',
                'password' => Hash::make('secret'),
                'role' => 'judge',
                'is_active_judge' => true,
            ]);
            $competition->judges()->attach($judge->id);
            $this->judges[] = $judge;
        }

        return $competition;
    }

    /**
     * Заявка в пуле (дисциплина × возрастная группа).
     *
     * @return array{0: Registration, 1: array<int, User>}
     */
    private function makeRegistration(Competition $competition, string $styleName, string $groupName, string $athleteName): array
    {
        $this->sortOrder++;

        $gender = str_contains($groupName, 'Жен') || str_contains($groupName, 'Дев') ? 'female' : 'male';
        $group = AgeGroup::firstOrCreate(
            ['name' => $groupName, 'gender' => $gender, 'min_age' => 12, 'max_age' => 14],
        );
        $style = Style::firstOrCreate(['name' => $styleName], ['sort_order' => $this->sortOrder]);
        $club = Club::firstOrCreate(['name' => 'Клуб аналитики']);
        $athlete = Athlete::create([
            'club_id' => $club->id,
            'name' => $athleteName,
            'birth_date' => '2013-05-01',
            'gender' => $gender,
        ]);

        $reg = Registration::create([
            'competition_id' => $competition->id,
            'athlete_id' => $athlete->id,
            'style_id' => $style->id,
            'age_group_id' => $group->id,
            'sort_order' => $this->sortOrder,
            'is_completed' => true,
        ]);

        return [$reg, $this->judges];
    }

    /**
     * @param  array<int, User>  $judges
     * @param  array<int, float>  $values
     */
    private function fillScores(Registration $reg, array $judges, array $values): void
    {
        foreach ($values as $i => $value) {
            Score::create([
                'registration_id' => $reg->id,
                'judge_id' => $judges[$i]->id,
                'score' => $value,
            ]);
        }
    }
}