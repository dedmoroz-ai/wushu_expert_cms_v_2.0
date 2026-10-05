<?php

/**
 * Smoke фоновой генерации AI-отчёта (см. _server/HOTFIX_AI_ASYNC.md).
 *
 *   php /tmp/smoke_ai_async.php <competition-id> start  — запуск, замер времени
 *   php /tmp/smoke_ai_async.php <competition-id> state  — текущий статус
 */

require '/var/www/html/vendor/autoload.php';

$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$competition = App\Models\Competition::find((int) ($argv[1] ?? 0));
if (! $competition) {
    fwrite(STDERR, "Соревнование не найдено\n");
    exit(1);
}

$runner = app(App\Support\AiReportRunner::class);

if (($argv[2] ?? 'state') === 'start') {
    $t0 = microtime(true);
    $launch = $runner->start($competition);
    printf("start() заняла %.2f сек\n", microtime(true) - $t0);
} else {
    $launch = $runner->state($competition);
}

echo json_encode($launch, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
