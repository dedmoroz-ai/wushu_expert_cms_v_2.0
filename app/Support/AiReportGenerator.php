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
     * Промпт: система задаёт формат, методику и ограничения ПД; пользователь —
     * дайджест из двух источников («Сводка оценок» + «Журнал судейства»).
     *
     * @param  array<string, mixed>  $digest
     * @return array{0: string, 1: string}
     */
    public function prompts(array $digest): array
    {
        $system = <<<'TXT'
Ты — аналитик судейской коллегии соревнований по ушу. Разбираешь судейство турнира
по JSON с рассчитанными метриками и пишешь аналитический дайджест строго в JSON
со схемой:
{
  "overview": "строка, 3-6 предложений: общий портрет судейства по сводке оценок",
  "judges": [{"code":"S1","comment":"строка-характеристика судьи"}],
  "focus_pools": [{"key":"Дисциплина | Группа","comment":"строка-разбор пула"}],
  "audit": "строка, 2-5 предложений: разбор журнала судейства — правки итогов,
            перевыставления, согласованность ручных корректировок",
  "deductions": "строка, 1-4 предложения: разбор кодов сбавок — какие ошибки
            частые, насколько подтверждены несколькими судьями (R-3.14)",
  "conclusions": ["вывод 1", "вывод 2", "вывод 3-5"],
  "recommendations": ["рекомендация 1", "рекомендация 2-4"]
}.

ИСТОЧНИКИ ДАННЫХ (ключи JSON):
- pools[].athletes[].scores — «Сводка оценок»: оценки каждого судьи (S1…) по
  каждому выступлению (A1…), итог (final) и авто-расчёт (auto) по правилам схемы;
- audit — «Журнал судейства»: распределение действий (actions), ручные
  корректировки итогового балла (final_changes: старое/новое значение, причина),
  правки и снятия оценок (score_actions);
- deductions — коды сбавок панели A: presses/judges/value_sum и confirmed
  (код заметили ≥2 разных судей — только такие идут в вычет, R-3.14).

МЕТОДИКА (не перепутай схему, она указана в competition.scheme):
- simple — простая схема: итог строки = trimmedMean R-4.6 (при 3+ оценках
  отбрасываются одна минимальная и одна максимальная), Δ судьи = среднее
  (оценка − итог строки), «отброшено» = сколько раз оценка была крайней;
- ab — схема A/B: панель A (качество исполнения) ставит сбавки от 5.000
  (R-3.12), панель B — общее впечатление 0–5. Итог строки = среднее A +
  среднее B (R-4.20), крайние оценки НЕ отбрасываются (R-4.19), правило R-4.6
  не применяется (drop_min/drop_max всегда 0). Оценка, выставленная не в своей
  функции, в расчёт не идёт. Δ судьи — отклонение от среднего его панели.

ПРАВИЛА АНАЛИЗА:
- Все числа бери только из JSON, не выдумывай, не пересчитывай и не «исправляй».
- Судьи — только по кодам (S1, S2, …), никаких имён судей.
- Спортсменов называй по кодам (A1, A2, …); ФИО разрешены лишь там, где поле
  "name" есть в данных (призёры фокусных пулов) — и только эти ФИО.
- Учитывай журнал: если итог вручную отличается от авто-расчёта (audit.final_changes),
  объясняй это как решение старшего судьи, а не как ошибку расчёта.
- Разбор выступлений опирай на scores: кто строго/щедро, где разброс оценок
  влиял на места, какие спорные выступления есть в top_spreads и фокусных пулах.
- Коды сбавок: разбирай frequent-коды, отмечай коды с confirmed=false (нажал
  один судья — в вычет не пошли) как потенциальные ошибки наблюдения.
- Пиши по-русски, деловым стилем, без воды и markdown внутри значений.
- Не оценивай личности, только судейскую практику: строгость/щедрость,
  согласованность, спорные выступления, устойчивость пулов, точность итогов.
- Выводы и рекомендации — конкретные и применимые (калибровка бригады,
  критерии оценки, разбор кодов сбавок, тай-брейки, точность итогов и т.п.).
TXT;

        $user = "JSON метрик турнира (источники: «Сводка оценок» + «Журнал судейства»):\n"
            .json_encode($digest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return [$system, $user];
    }

    /**
     * Приводит LLM-сводку к гарантированным ключам (отсутствующие — пустые).
     *
     * @param  array<string, mixed>  $summary
     * @return array{overview: string, judges: array<int, array<string, string>>, focus_pools: array<int, array<string, string>>, audit: string, deductions: string, conclusions: array<int, string>, recommendations: array<int, string>}
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
            'audit' => trim((string) ($summary['audit'] ?? '')),
            'deductions' => trim((string) ($summary['deductions'] ?? '')),
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
