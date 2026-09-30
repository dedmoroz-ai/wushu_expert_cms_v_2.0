<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Carbon;

class Analytics extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';
    protected static ?string $navigationLabel = 'Аналитика';
    protected static ?string $title = 'Аналитика — отчёты';
    protected static ?int $navigationSort = 100;

    protected static string $view = 'filament.pages.analytics';

    /**
     * Возвращает список отчётов из storage/app/public/reports
     */
    public function getReports(): array
    {
        $dir = storage_path('app/public/reports');

        if (! is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.html');
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
                'slug'        => $slug,
                'filename'    => $filename,
                'title'       => $title ?: $slug,
                'description' => $description,
                'url'         => '/reports/' . $filename,
                'mtime'       => Carbon::createFromTimestamp(filemtime($path)),
                'report_date' => $reportDate,
                'size'        => filesize($path),
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