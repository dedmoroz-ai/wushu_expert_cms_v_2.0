<?php

namespace App\Support;

use App\Models\Competition;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Фоновый запуск генерации AI-отчёта (docs/ANALYTICS.md).
 *
 * Кнопка «AI-аналитика» не должна ждать LLM в HTTP-запросе: генерация идёт
 * до ~3 минут, а nginx обрывает долгий ответ по fastcgi_read_timeout —
 * пользователь получает 504, хотя отчёт потом дописывается. Поэтому
 * генерация уходит в отдельный CLI-процесс (php artisan analytics:generate),
 * а в UI показывается статус (обновляется по wire:poll).
 *
 * Статус — в cache (CACHE_STORE=database):
 *   ai-report:state:{id} => ['status' => running|done|error, 'url', 'message',
 *                            'started_at', 'finished_at'], TTL 1 час.
 *
 * Процесс отвязывается от PHP-FPM-воркера шелл-запуском `nohup … &` с выводом
 * в storage/logs/ai-report.log: процесс переживает конец HTTP-запроса,
 * а пайпы Symfony Process не «зависают» (вывод команды идёт в файл).
 *
 * Статус-машина:
 *   start() — running (повторный запуск блокируется);
 *   GenerateAnalyticsReport — done (url) | error (message) по завершении;
 *   state() — running дольше STALE_AFTER_SECONDS трактуется как прерванное
 *   (процесс погиб, например при рестарте) — перезапуск разрешается.
 */
class AiReportRunner
{
    /** Статус: генерация выполняется. */
    public const STATUS_RUNNING = 'running';

    /** Статус: отчёт готов (url в 'url'). */
    public const STATUS_DONE = 'done';

    /** Статус: ошибка (текст в 'message'). */
    public const STATUS_ERROR = 'error';

    /** TTL записи статуса, минуты (дальше считаем, что генераций не было). */
    public const STATE_TTL_MINUTES = 60;

    /** Старше этого срока running = процесс погиб; секунды. */
    public const STALE_AFTER_SECONDS = 600;

    /** Лог фонового процесса (путь относительно storage/). */
    public const LOG_FILE = 'logs/ai-report.log';

    /** Префикс ключа cache со статусом генерации. */
    private const STATE_PREFIX = 'ai-report:state:';

    /**
     * Запустить генерацию в фоне, не дожидаясь результата.
     *
     * @return array{started: bool, already_running: bool, state: ?array}
     */
    public function start(Competition $competition): array
    {
        $state = $this->state($competition);
        if (is_array($state) && $state['status'] === self::STATUS_RUNNING) {
            return ['started' => false, 'already_running' => true, 'state' => $state];
        }

        $this->markRunning($competition);

        // nohup + &: шелл сразу возвращает управление, CLI-процесс становится
        // «внуком» FPM-воркера и живёт после конца HTTP-запроса. Вывод команды —
        // в лог (ai-report.log), stdin — /dev/null. PHP — по абсолютному пути
        // (PATH у FPM-воркера может отличаться от шелла).
        $php = PHP_BINDIR.'/php';
        $command = sprintf(
            'nohup %s %s analytics:generate %d < /dev/null >> %s 2>&1 &',
            escapeshellarg($php),
            escapeshellarg(base_path('artisan')),
            $competition->id,
            escapeshellarg(storage_path(self::LOG_FILE)),
        );

        try {
            Process::run($command);
        } catch (Throwable $e) {
            $this->markError($competition, 'Не удалось запустить генерацию: '.$e->getMessage());

            return ['started' => false, 'already_running' => false, 'state' => $this->state($competition)];
        }

        return ['started' => true, 'already_running' => false, 'state' => $this->state($competition)];
    }

    /**
     * Текущий статус генерации (null — генераций не было / TTL истёк).
     * Протухший running переключается в error и отдаётся уже как error.
     */
    public function state(Competition $competition): ?array
    {
        $state = Cache::get(self::STATE_PREFIX.$competition->id);
        if (! is_array($state)) {
            return null;
        }

        if ($state['status'] === self::STATUS_RUNNING) {
            $startedAt = Carbon::parse($state['started_at']);

            if ($startedAt->diffInSeconds(now()) > self::STALE_AFTER_SECONDS) {
                $this->markError($competition, 'Генерация была прервана (превышено время ожидания). Запустите её заново.');

                return Cache::get(self::STATE_PREFIX.$competition->id);
            }
        }

        return $state;
    }

    /** Статус: генерация запущена. */
    public function markRunning(Competition $competition): void
    {
        $this->write($competition, [
            'status' => self::STATUS_RUNNING,
            'started_at' => now()->toIso8601String(),
        ]);
    }

    /** Статус: отчёт готов (пишет GenerateAnalyticsReport по завершении). */
    public function markDone(Competition $competition, string $url): void
    {
        $this->write($competition, [
            'status' => self::STATUS_DONE,
            'url' => $url,
            'finished_at' => now()->toIso8601String(),
        ]);
    }

    /** Статус: ошибка (пишет GenerateAnalyticsReport по завершении). */
    public function markError(Competition $competition, string $message): void
    {
        $this->write($competition, [
            'status' => self::STATUS_ERROR,
            'message' => $message,
            'finished_at' => now()->toIso8601String(),
        ]);
    }

    /** Пишет статус целиком (все ключи — чтобы форма статуса в UI была полной). */
    private function write(Competition $competition, array $patch): void
    {
        $state = array_merge([
            'status' => null,
            'url' => null,
            'message' => null,
            'started_at' => null,
            'finished_at' => null,
        ], $patch);

        Cache::put(
            self::STATE_PREFIX.$competition->id,
            $state,
            now()->addMinutes(self::STATE_TTL_MINUTES),
        );
    }
}
