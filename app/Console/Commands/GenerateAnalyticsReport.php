<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Support\AiReportGenerator;
use App\Support\AiReportRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * AI-аналитика по запросу: числа считаются детерминированно, текст — LLM.
 * Результат — HTML в storage/app/public/reports/ (появится на странице «Аналитика»).
 *
 *   php artisan analytics:generate {competition}   # id или часть названия
 */
class GenerateAnalyticsReport extends Command
{
    protected $signature = 'analytics:generate
        {competition : ID соревнования или уникальная часть названия}
        {--model= : Переопределить AI_MODEL (например, для прогона)}
        {--timeout= : Переопределить AI_TIMEOUT, сек}';

    protected $description = 'Сгенерировать AI-аналитику по судейству турнира (HTML в reports/)';

    public function handle(AiReportGenerator $generator, AiReportRunner $runner): int
    {
        $competition = $this->resolveCompetition((string) $this->argument('competition'));
        if (! $competition) {
            $this->error('Соревнование не найдено. Укажите ID или уникальную часть названия.');

            return self::FAILURE;
        }

        $options = array_filter([
            'model' => $this->option('model') ?: null,
            'timeout' => $this->option('timeout') ? (int) $this->option('timeout') : null,
        ]);

        $this->info("Турнир: {$competition->name} ({$competition->datesLabel()})");
        $this->line('Расчёт метрик, запрос к LLM (может занять до 3 минут)…');

        try {
            $result = $generator->generate($competition, $options);
        } catch (Throwable $e) {
            // Фоновый запуск (AiReportRunner): итог пишется в cache — по нему
            // UI показывает статус генерации (docs/ANALYTICS.md).
            $runner->markError($competition, $e->getMessage());
            $this->error('Генерация не удалась: '.$e->getMessage());

            return self::FAILURE;
        }

        $runner->markDone($competition, $result['url']);

        $this->info('Отчёт готов: '.$result['path']);
        $this->line('Ссылка: '.$result['url']);
        $this->line('Модель: '.$result['model']);
        if ($result['usage'] !== []) {
            $this->line('Токены: '.json_encode($result['usage']));
        }

        return self::SUCCESS;
    }

    /** ID или уникальная часть названия (безопасно для повторных запусков). */
    private function resolveCompetition(string $query): ?Competition
    {
        if (ctype_digit($query)) {
            return Competition::find((int) $query);
        }

        $matches = Competition::where('name', 'like', '%'.$query.'%')->get();
        if ($matches->count() === 1) {
            return $matches->first();
        }

        if ($matches->count() > 1) {
            $this->warn('Найдено несколько соревнований:');
            foreach ($matches as $c) {
                $this->line("  [{$c->id}] {$c->name} ({$c->datesLabel()})");
            }
        }

        return null;
    }
}