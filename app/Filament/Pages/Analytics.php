<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class Analytics extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Аналитика';

    protected static ?string $title = 'Аналитика — отчёты';

    protected static ?int $navigationSort = 100;

    protected static string $view = 'filament.pages.analytics';

    /**
     * Замечание заказчика (01.10): раздел «Аналитика» входит в набор меню судей
     * и включён по умолчанию; админ может выключить его в настройках судей.
     */
    public static function canAccess(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        return $user && (! $user->isJudge() || $user->show_analytics);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** Позиция в наборе пунктов меню судьи: Инфопанель(1), Пульт(2), Аналитика(3), … */
    public static function getNavigationSort(): ?int
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        return $user && $user->isJudge() ? 3 : parent::getNavigationSort();
    }

    /**
     * Возвращает список отчётов из storage/app/public/reports
     */
    public function getReports(): array
    {
        $dir = storage_path('app/public/reports');

        if (! is_dir($dir)) {
            return [];
        }

        $files = glob($dir.'/*.html');
        if (! $files) {
            return [];
        }

        $reports = [];

        foreach ($files as $path) {
            $filename = basename($path);
            $slug = pathinfo($filename, PATHINFO_FILENAME);

            // Читаем первые 4 КБ файла, чтобы вытащить <title> и <meta>
            $head = @file_get_contents($path, false, null, 0, 4096) ?: '';

            $title = $slug;
            if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $head, $m)) {
                $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
            }

            $description = null;
            if (preg_match('/<meta\s+name=["\']description["\']\s+content=["\']([^"\']+)["\']/i', $head, $m)) {
                $description = trim($m[1]);
            }

            $reportDate = null;
            if (preg_match('/<meta\s+name=["\']report-date["\']\s+content=["\']([^"\']+)["\']/i', $head, $m)) {
                try {
                    $reportDate = Carbon::parse($m[1]);
                } catch (\Throwable $e) {
                    // ignore
                }
            }

            $reports[] = [
                'slug' => $slug,
                'filename' => $filename,
                'title' => $title ?: $slug,
                'description' => $description,
                // Отчёт открывается только авторизованными пользователями:
                // маршрут /reports/{файл} (routes/web.php, middleware auth).
                // Статический /storage/reports/ закрыт веб-сервером — см. docs/ANALYTICS.md.
                'url' => '/reports/'.rawurlencode($filename),
                'mtime' => Carbon::createFromTimestamp(filemtime($path)),
                'report_date' => $reportDate,
                'size' => filesize($path),
            ];
        }

        // Сортировка: сначала по report-date (если задана), иначе по mtime — новые сверху
        usort($reports, function ($a, $b) {
            $aDate = $a['report_date'] ?? $a['mtime'];
            $bDate = $b['report_date'] ?? $b['mtime'];

            return $bDate <=> $aDate;
        });

        return $reports;
    }
}
