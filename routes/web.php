<?php

use Illuminate\Support\Facades\Route;
use App\Livewire\Scoreboard;
use App\Http\Controllers\CompetitionPdfController;
use App\Http\Controllers\ExportController;
use App\Models\Competition;

// Главная страница
Route::get('/', function () {
    // БЕРЕМ САМОЕ СВЕЖЕЕ СОРЕВНОВАНИЕ
    $activeCompetition = Competition::orderBy('start_date', 'desc')->first();

    return view('welcome', compact('activeCompetition'));
});

// Публичное табло
Route::get('/scoreboard', Scoreboard::class)->name('scoreboard');

// Публичная страница результатов
Route::get('/results/{token?}', \App\Livewire\PublicResults::class)->name('public.results');

// Генерация QR-кода
Route::get('/competition/{competition}/qr-code', [\App\Http\Controllers\QrCodeController::class, 'generate'])
    ->name('competition.qr-code');

// --- ГЕНЕРАЦИЯ PDF ПРОТОКОЛОВ ---
Route::get('/competition/{competition}/start-list', [CompetitionPdfController::class, 'startList'])
    ->name('competition.start-list');

Route::get('/competition/{competition}/final-results', [CompetitionPdfController::class, 'finalResults'])
    ->name('competition.final-results');

// Титульный лист (отдельный PDF)
Route::get('/competition/{competition}/title-page', [CompetitionPdfController::class, 'titlePage'])
    ->name('competition.title-page');

// Командный (клубный) зачёт (R-6.14)
Route::get('/competition/{competition}/team-standings', [CompetitionPdfController::class, 'teamStandings'])
    ->name('competition.team-standings');

// --- СВОДКА ОЦЕНОК (PDF) ---
Route::get('/competition/{competition}/scores-summary', \App\Http\Controllers\ScoresSummaryPdfController::class)
    ->middleware('auth')
    ->name('competition.scores-summary');

// --- ПАМЯТКА СУДЬЯМ: КОДЫ СБАВОК (PDF, печать/раздача) ---
Route::get('/competition/{competition}/deduction-codes-memo', \App\Http\Controllers\DeductionCodesMemoPdfController::class)
    ->middleware('auth')
    ->name('competition.deduction-codes-memo');

// --- ПАМЯТКА СУДЬЯМ: ЛИМИТЫ ОЦЕНОК ПО ВОЗРАСТНЫМ ГРУППАМ (PDF, печать/раздача) ---
Route::get('/competition/{competition}/age-groups-memo', \App\Http\Controllers\AgeGroupsMemoPdfController::class)
    ->middleware('auth')
    ->name('competition.age-groups-memo');

// --- ПЕЧАТЬ ДИПЛОМОВ ---
// Теперь принимаем ID соревнования, вида, возрастную группу и пол.
// Группа «O» (R-6.15): необязательный флаг is_special (0/1) — отдельная
// подгруппа «(O)», места считаются внутри подгруппы.
Route::get('/competition/{competition}/diplomas/{style}/{age_group}/{gender}/{is_special?}', [ExportController::class, 'downloadDiplomas'])
    ->where('is_special', '[01]')
    ->name('export.diplomas');

// --- ОТЧЁТЫ АНАЛИТИКИ (AI) ---
// Файлы физически лежат в storage/app/public/reports/, но статический путь
// /storage/reports/… закрыт на уровне nginx и Caddy (см. docs/ANALYTICS.md) —
// отчёт открывается только авторизованными пользователями через этот маршрут.
Route::get('/reports/{filename}', function (string $filename) {
    // Только «плоские» имена *.html — без каталогов и traversal.
    abort_unless(
        $filename === basename($filename)
        && ! str_contains($filename, '\\')
        && ! str_contains($filename, "\0")
        && str_ends_with(strtolower($filename), '.html'),
        404,
    );

    // Тот же круг видимости, что и у страницы «Аналитика».
    abort_unless(\App\Filament\Pages\Analytics::canAccess(), 403);

    $path = storage_path('app/public/reports/'.$filename);
    abort_unless(is_file($path), 404);

    return response(\Illuminate\Support\Facades\File::get($path), 200, [
        'Content-Type' => 'text/html; charset=UTF-8',
        'X-Content-Type-Options' => 'nosniff',
    ]);
})->middleware('auth')->name('reports.show');
