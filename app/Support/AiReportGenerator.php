<?php

namespace App\Support;

use App\Models\Competition;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Генератор AI-аналитики: числа — детерминированно (CompetitionAnalyticsBuilder),
 * текст дайджеста — LLM (AiClient, polza.ai), отчёт собирает blade.
 *
 * Разделение ответственности:
 *  - полные таблицы (судьи, пулы, топ разбросов) рендерит blade по метрикам —
 *    цифры в них всегда точны, LLM их не переписывает;
 *  - LLM пишет только аналитические разделы (наблюдения, фокусные пулы,
 *    выводы) по кодированным данным; ФИО — только призёры фокусных пулов;
 *  - HTML сохраняется в storage/app/public/reports/ и появляется на странице
 *    «Аналитика»; открывается только авторизованными пользователями через
 *    маршрут /reports/{файл} (routes/web.php, middleware auth).
 */
class AiReportGenerator
{
    /** Имя директории отчётов (как читает Analytics.php). */
    public const REPORTS_DIR = 'app/public/reports';

    /**
     * Полный цикл: метрики → дайджест → LLM → HTML-файл.
     *
     * @param  array<string, mixed>  $options  model/temperature/max_tokens для AiClient
     * @return array{path: string, url: string, model: string, usage: array<string, int>}
     *
     * @throws RuntimeException если нет завершённых выступлений или LLM не ответил
     */
    public function generate(Competition $competition, array $options = []): array
    {
        $metrics = CompetitionAnalyticsBuilder::build($competition);

        if ($metrics === null || $metrics['totals']['completed'] === 0) {
            throw new RuntimeException(
                "По турниру «{$competition->name}» нет завершённых выступлений с оценками — аналировать нечего.",
            );
        }

        $digest = CompetitionAnalyticsBuilder::llmDigest($metrics);
        [$system, $user] = $this->prompts($digest);

        $client = new AiClient;
        $result = $client->complete($system, $user, $options);
        $summary = AiClient::extractJson($result['content']);

        $html = view('reports.analytics-report', [
            'competition' => $competition,
            'metrics' => $metrics,
            'summary' => $this->normalizeSummary($summary),
            'model' => $result['model'],
            'generatedAt' => now(),
        ])->render();

        $path = $this->writeReport($competition, $html);

        return [
            'path' => $path,
            'url' => '/reports/'.rawurlencode(basename($path)),
            'model' => $result['model'],
            'usage' => $result['usage'],
        ];
    }

    /**
     * Промпт: система задаёт формат и ограничения ПД, пользователь — дайджест.
     *
     * @param  array<string, mixed>  $digest
     * @return array{0: string, 1: string}
     */
    public function prompts(array $digest): array
    {
        $system = <<<'TXT'
Ты — аналитик судейской коллегии соревнований по ушу. По JSON с рассчитанными
метриками турнира напиши аналитический дайджест строго в JSON со схемой:
{
  "overview": "строка, 3-6 предложений: общий портрет судейства",
  "judges": [{"code":"S1","comment":"строка-характеристика"}],
  "focus_pools": [{"key":"Дисциплина | Группа","comment":"строка-разбор"}],
  "conclusions": ["вывод 1", "вывод 2", "вывод 3-5"],
  "recommendations": ["рекомендация 1", "рекомендация 2-4"]
}.
Правила:
- Все числа бери только из JSON, не выдумывай и не пересчитывай.
- Судьи — только по кодам (S1, S2, …), никаких имён судей.
- Спортсменов называй по кодам (A1, A2, …); ФИО разрешены лишь там, где поле
  "name" есть в данных (призёры фокусных пулов) — и только эти ФИО.
- Пиши по-русски, деловым стилем, без воды и markdown внутри значений.
- Не оценивай личности, только судейскую практику: строгость/щедрость,
  согласованность, спорные выступления, устойчивость пулов.
- Выводы и рекомендации — конкретные и применимые (калибровка бригады,
  критерии оценки, тай-брейки, точность итогов и т.п.).
TXT;

        $user = "JSON метрик турнира:\n"
            .json_encode($digest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return [$system, $user];
    }

    /**
     * Приводит LLM-сводку к гарантированным ключам (отсутствующие — пустые).
     *
     * @param  array<string, mixed>  $summary
     * @return array{overview: string, judges: array<int, array<string, string>>, focus_pools: array<int, array<string, string>>, conclusions: array<int, string>, recommendations: array<int, string>}
     */
    public function normalizeSummary(array $summary): array
    {
        $toList = function ($value): array {
            return array_values(array_filter(
                is_array($value) ? $value : [],
                fn ($item) => is_string($item) ? trim($item) !== '' : is_array($item),
            ));
        };

        return [
            'overview' => trim((string) ($summary['overview'] ?? '')),
            'judges' => $toList($summary['judges'] ?? []),
            'focus_pools' => $toList($summary['focus_pools'] ?? []),
            'conclusions' => array_map('strval', $toList($summary['conclusions'] ?? [])),
            'recommendations' => array_map('strval', $toList($summary['recommendations'] ?? [])),
        ];
    }

    /**
     * Сохраняет HTML в storage/app/public/reports/ и возвращает путь файла.
     * Имя: analytics_<дата турнира>_<id>.html (уникально, перезаписываемо).
     */
    public function writeReport(Competition $competition, string $html): string
    {
        $dir = storage_path(self::REPORTS_DIR);
        if (! is_dir($dir) && ! File::makeDirectory($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('Не удалось создать директорию отчётов: '.$dir);
        }

        $date = $competition->start_date?->format('dmY') ?? 'undated';
        $slug = Str::slug($competition->name);
        if (mb_strlen($slug) > 40) {
            $slug = rtrim(mb_substr($slug, 0, 40), '-');
        }

        $path = $dir.'/analytics_'.$date.'_'.$competition->id.($slug !== '' ? '_'.$slug : '').'.html';
        File::put($path, $html);

        return $path;
    }
}