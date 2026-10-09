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
use App\Support\AiReportGenerator;
use App\Support\AiReportRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Фоновая генерация AI-отчёта (docs/ANALYTICS.md): кнопка не ждёт LLM
 * (иначе nginx обрывает ответ по fastcgi_read_timeout — 504), статус
 * running|done|error живёт в cache, протухший running = «прервано».
 * Process::fake: реальные фоновые процессы в тестах не запускаются.
 */
class AiReportRunnerTest extends TestCase
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

        config()->set('services.ai.key', 'test-key');
        config()->set('services.ai.base_url', 'https://polza.test/api/v1');
        config()->set('services.ai.model', 'anthropic/claude-haiku-5.5');
    }

    protected function tearDown(): void
    {
        // Каталог отчётов рабочий (страница «Аналитика» читает его напрямую) —
        // тестовые артефакты не оставляем.
        $this->removeGeneratedReports();

        parent::tearDown();
    }

    /** Фоновый запуск: шелл-команда уходит в Process, статус — running. */
    public function test_start_launches_background_command_and_marks_running(): void
    {
        Process::fake();
        $competition = $this->makeCompetition();

        $launch = app(AiReportRunner::class)->start($competition);

        $this->assertTrue($launch['started']);
        $this->assertFalse($launch['already_running']);
        $this->assertSame(AiReportRunner::STATUS_RUNNING, $launch['state']['status']);

        // Команда отвязана от запроса: `nohup … &` с выводом в лог.
        Process::assertRan(function ($process) use ($competition) {
            $command = (string) $process->command;

            return str_contains($command, 'analytics:generate '.$competition->id)
                && str_contains($command, 'nohup')
                && str_contains($command, ' &');
        });
    }

    /** Пока генерация идёт, повторный запуск блокируется. */
    public function test_start_refuses_while_running(): void
    {
        Process::fake();
        $competition = $this->makeCompetition();
        $runner = app(AiReportRunner::class);

        $runner->start($competition);
        $second = $runner->start($competition);

        $this->assertFalse($second['started']);
        $this->assertTrue($second['already_running']);
        $this->assertSame(AiReportRunner::STATUS_RUNNING, $second['state']['status']);

        Process::assertRanTimes(
            fn ($process) => str_contains((string) $process->command, 'analytics:generate'),
            1,
        );
    }

    /** Протухший running (процесс погиб) = «прервано», повторный запуск разрешён. */
    public function test_stale_running_is_reported_as_error_and_allows_restart(): void
    {
        Process::fake();
        $competition = $this->makeCompetition();
        $runner = app(AiReportRunner::class);

        $runner->start($competition);

        $this->travel(AiReportRunner::STALE_AFTER_SECONDS / 60 + 1)->minutes();

        $state = $runner->state($competition);
        $this->assertSame(AiReportRunner::STATUS_ERROR, $state['status']);
        $this->assertStringContainsString('прервана', $state['message']);

        $launch = $runner->start($competition);
        $this->assertTrue($launch['started']);
    }

    /** Завершение команды фиксируется в cache: done с url отчёта. */
    public function test_command_marks_done_state_with_report_url(): void
    {
        Http::fake(['polza.test/*' => Http::response($this->llmResponse(), 200)]);
        $competition = $this->makeCompetitionWithScores();

        $this->artisan('analytics:generate', ['competition' => $competition->id])
            ->assertSuccessful();

        $state = app(AiReportRunner::class)->state($competition);
        $this->assertSame(AiReportRunner::STATUS_DONE, $state['status']);
        $this->assertStringStartsWith('/reports/', $state['url']);
    }

    /** Ошибка генерации фиксируется в cache: error с текстом. */
    public function test_command_marks_error_state_on_failure(): void
    {
        Http::fake();
        $competition = $this->makeCompetitionWithScores(false);

        $this->artisan('analytics:generate', ['competition' => $competition->id])
            ->assertFailed();

        $state = app(AiReportRunner::class)->state($competition);
        $this->assertSame(AiReportRunner::STATUS_ERROR, $state['status']);
        $this->assertStringContainsString('нет завершённых выступлений', $state['message']);
    }

    /** Кнопка в UI запускает фоновую генерацию, не дожидаясь LLM. */
    public function test_generate_action_starts_background_generation(): void
    {
        Process::fake();
        $competition = $this->makeCompetitionWithScores();
        $admin = User::create([
            'name' => 'Админ Тестовый',
            'email' => 'admin-runner@test.local',
            'password' => Hash::make('secret'),
            'role' => 'admin',
        ]);

        $this->actingAs($admin);

        Livewire::test(ScoresSummary::class)
            ->set('competitionId', $competition->id)
            ->call('generateAiAnalytics');

        $state = app(AiReportRunner::class)->state($competition);
        $this->assertSame(AiReportRunner::STATUS_RUNNING, $state['status']);

        Process::assertRan(
            fn ($process) => str_contains((string) $process->command, 'analytics:generate '.$competition->id),
        );
    }

    /** Удаляет отчёты, созданные тестами этого класса (конкурируют за одно имя файла). */
    private function removeGeneratedReports(): void
    {
        $pattern = storage_path(AiReportGenerator::REPORTS_DIR).'/analytics_*_ai-generaciia-turnir*.html';

        foreach (glob($pattern) ?: [] as $path) {
            File::delete($path);
        }
    }

    /** Стандартный ответ LLM для Http::fake. */
    private function llmResponse(): array
    {
        return [
            'model' => 'anthropic/claude-haiku-5.5',
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'overview' => 'Коллегия работала согласованно.',
                        'judges' => [
                            ['code' => 'S1', 'comment' => 'Самый строгий судья коллегии.'],
                        ],
                        'focus_pools' => [],
                        'conclusions' => ['Калибровка бригады требуется.'],
                        'recommendations' => ['Калибровка бригады: совместный просмотр эталонов.'],
                    ], JSON_UNESCAPED_UNICODE),
                ],
            ]],
            'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 400, 'total_tokens' => 1600],
        ];
    }

    /** Турнир без оценок (только каркас). */
    private function makeCompetition(): Competition
    {
        return Competition::create([
            'name' => 'AI-фоновый запуск турнир',
            'start_date' => '2026-03-01',
            'city' => 'Москва',
            'status_code' => 3,
            'judging_scheme' => Competition::SCHEME_SIMPLE,
        ]);
    }

    /** Турнир с бригадой, одним завершённым выступлением и оценками. */
    private function makeCompetitionWithScores(bool $withScores = true): Competition
    {
        $competition = Competition::create([
            'name' => 'AI-генерация турнир',
            'start_date' => '2026-03-01',
            'city' => 'Москва',
            'status_code' => 3,
            'judging_scheme' => Competition::SCHEME_SIMPLE,
        ]);

        $group = AgeGroup::create(['name' => 'Юноши', 'gender' => 'male', 'min_age' => 12, 'max_age' => 14]);
        $style = Style::create(['name' => 'Чанцюань', 'sort_order' => 1]);
        $club = Club::create(['name' => 'Клуб генерации']);
        $athlete = Athlete::create([
            'club_id' => $club->id,
            'name' => 'Спортсмен Первый',
            'birth_date' => '2013-05-01',
            'gender' => 'male',
        ]);

        $reg = Registration::create([
            'competition_id' => $competition->id,
            'athlete_id' => $athlete->id,
            'style_id' => $style->id,
            'age_group_id' => $group->id,
            'sort_order' => 1,
            'is_completed' => $withScores,
            'final_score' => $withScores ? 8.5 : null,
        ]);

        if ($withScores) {
            foreach ([['Судья А', 8.0], ['Судья Б', 8.5], ['Судья В', 9.0]] as [$name, $value]) {
                $judge = User::create([
                    'name' => $name,
                    'email' => str()->random(8).'@test.local',
                    'password' => Hash::make('secret'),
                    'role' => 'judge',
                    'is_active_judge' => true,
                ]);
                $competition->judges()->attach($judge->id);
                Score::create([
                    'registration_id' => $reg->id,
                    'judge_id' => $judge->id,
                    'score' => $value,
                ]);
            }
        }

        return $competition->fresh();
    }
}
