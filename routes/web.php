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

// --- ПЕЧАТЬ ДИПЛОМОВ ---
// Теперь принимаем ID соревнования, вида, возрастную группу и пол
Route::get('/competition/{competition}/diplomas/{style}/{age_group}/{gender}', [ExportController::class, 'downloadDiplomas'])
    ->name('export.diplomas');
