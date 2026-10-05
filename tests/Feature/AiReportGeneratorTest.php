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
use App\Support\AiClient;
use App\Support\AiReportGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * AI-аналитика по запросу (docs/ANALYTICS.md): числа — PHP, текст — LLM
 * (polza.ai). Http::fake: реальные запросы к LLM в тестах не уходят.
 */
class AiReportGeneratorTest extends TestCase
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
        config()->set('services.ai.model', 'xiaomi/mimo-v2.6-pro');
    }

    protected function tearDown(): void
    {
        // Каталог отчётов рабочий (страница «Аналитика» читает его напрямую) —
        // тестовые артефакты не оставляем.
        $this->removeGeneratedReports();

        parent::tearDown();
    }

    /** Удаляет отчёты, созданные тестами этого класса (конкурируют за одно имя файла). */
    private function removeGeneratedReports(): void
    {
        $pattern = storage_path(AiReportGenerator::REPORTS_DIR).'/analytics_*_ai-generaciia-turnir*.html';

        foreach (glob($pattern) ?: [] as $path) {
            File::delete($path);
        }
    }

    /** Полный цикл: Http::fake → генерация → HTML-файл с метаданными. */
    public function test_generate_writes_public_html_report(): void
    {
        Http::fake([
            'polza.test/*' => Http::response($this->llmResponse(), 200),
        ]);

        $competition = $this->makeCompetitionWithScores();
        $reportDir = storage_path(AiReportGenerator::REPORTS_DIR);

        // Имя файла отчёта стабильно и при повторной генерации перезаписывается —
        // убираем артефакты прошлых прогонов, чтобы проверять именно появление файла.
        $this->removeGeneratedReports();

        $result = app(AiReportGenerator::class)->generate($competition);

        $after = glob($reportDir.'/analytics_*.html') ?: [];
        $this->assertContains($result['path'], $after);
        $this->assertFileExists($result['path']);
        $this->assertStringStartsWith('/reports/', $result['url']);

        $html = File::get($result['path']);
        $this->assertStringContainsString('<title>AI-аналитика:', $html);
        $this->assertStringContainsString('<meta name="report-date"', $html);
        $this->assertStringContainsString('Выводы и рекомендации', $html);
        $this->assertStringContainsString('Калибровка бригады', $html);
        $this->assertStringContainsString('Судья А', $html); // ФИО судей — в blade-таблицах

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/chat/completions')
                && $request['model'] === 'xiaomi/mimo-v2.6-pro'
                && $request['response_format'] === ['type' => 'json_object'];
        });
    }

    /**
     * Оформление отчёта как на всех страницах продукта: шапка с логотипом
     * (images/logo.png, светлая — как в админке и в отчёте 02.05) и футер
     * с единой строкой заказчика (04.10):
     * «WUSHU EXPERT COMPETITION MANAGEMENT SYSTEM 3.0 © 2026 МАКС МОРОЗ (logo)».
     */
    public function test_report_shows_brand_header_and_footer(): void
    {
        Http::fake(['polza.test/*' => Http::response($this->llmResponse(), 200)]);

        $competition = $this->makeCompetitionWithScores();
        $this->removeGeneratedReports();

        $result = app(AiReportGenerator::class)->generate($competition);
        $html = File::get($result['path']);

        // Шапка: светлая, логотип слева.
        $this->assertStringContainsString('<header class="report-header">', $html);
        $this->assertStringContainsString('images/logo.png', $html);

        // Футер: единая строка заказчика (04.10).
        $this->assertStringContainsString(
            'WUSHU EXPERT COMPETITION MANAGEMENT SYSTEM '.config('app.version'),
            $html,
        );
        // Порядок (01.10): сначала знак копирайта, потом год.
        $this->assertStringContainsString('&copy; 2026', $html);
        // Порядок (04.10): имя, затем логотип разработчика.
        $this->assertStringContainsString('МАКС МОРОЗ', $html);
        $this->assertStringContainsString('images/d989.svg', $html);
        $this->assertLessThan(
            mb_strpos($html, 'images/d989.svg'),
            mb_strpos($html, 'МАКС МОРОЗ'),
        );
    }

    /** В промпт уходит кодированный дайджест: без ФИО спортсменов и судей. */
    public function test_prompt_contains_coded_digest_without_personal_data(): void
    {
        Http::fake(['polza.test/*' => Http::response($this->llmResponse(), 200)]);

        $competition = $this->makeCompetitionWithScores();
        app(AiReportGenerator::class)->generate($competition);

        Http::assertSent(function ($request) {
            $user = $request['messages'][1]['content'];

            return str_contains($user, '"S1"')
                && str_contains($user, '"A1"')
                && ! str_contains($user, 'Спортсмен Первый')
                && ! str_contains($user, 'Судья А');
        });
    }

    /** Ответ в ```json-блоке (без response_format) тоже разбирается. */
    public function test_extract_json_from_fenced_block(): void
    {
        $content = "Краткий комментарий модели:\n```json\n{\"overview\": \"ок\", \"conclusions\": [\"в1\"]}\n```\n";

        $parsed = AiClient::extractJson($content);

        $this->assertSame('ок', $parsed['overview']);
        $this->assertSame(['в1'], $parsed['conclusions']);
    }

    /** Понятная ошибка без ключа — запрос не уходит. */
    public function test_missing_api_key_fails_with_clear_message(): void
    {
        config()->set('services.ai.key', '');
        Http::fake();

        $competition = $this->makeCompetitionWithScores();

        try {
            app(AiReportGenerator::class)->generate($competition);
            $this->fail('Ожидалась RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('AI_API_KEY', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    /** HTTP-ошибка LLM пробрасывается с телом ответа. */
    public function test_http_error_from_llm_is_surfaced(): void
    {
        Http::fake(['polza.test/*' => Http::response('quota exceeded', 429)]);
        $competition = $this->makeCompetitionWithScores();

        try {
            app(AiReportGenerator::class)->generate($competition);
            $this->fail('Ожидалась RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('429', $e->getMessage());
            $this->assertStringContainsString('quota exceeded', $e->getMessage());
        }
    }

    /** Турнир без завершённых выступлений — генерация невозможна, LLM не зовём. */
    public function test_empty_competition_fails_before_llm(): void
    {
        Http::fake();
        $competition = $this->makeCompetitionWithScores(false);

        try {
            app(AiReportGenerator::class)->generate($competition);
            $this->fail('Ожидалась RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('нет завершённых выступлений', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    /** Стандартный ответ LLM для Http::fake. */
    private function llmResponse(): array
    {
        return [
            'model' => 'xiaomi/mimo-v2.6-pro',
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'overview' => 'Коллегия работала согласованно, перекос строгости умеренный.',
                        'judges' => [
                            ['code' => 'S1', 'comment' => 'Самый строгий судья коллегии.'],
                        ],
                        'focus_pools' => [
                            ['key' => 'Чанцюань | Юноши', 'comment' => 'Разброс оценок высокий.'],
                        ],
                        'conclusions' => ['Калибровка бригады требуется.'],
                        'recommendations' => ['Калибровка бригады: совместный просмотр эталонов.'],
                    ], JSON_UNESCAPED_UNICODE),
                ],
            ]],
            'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 400, 'total_tokens' => 1600],
        ];
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