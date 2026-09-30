<?php

namespace App\Http\Controllers;

use App\Filament\Pages\ScoresSummary;
use App\Models\Competition;
use App\Support\ScoresSummaryMatrix;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

class ScoresSummaryPdfController extends Controller
{
    public function __invoke(Competition $competition)
    {
        // Доступ — как у страницы «Сводка оценок» (админы + список e-mail).
        abort_unless(ScoresSummary::canAccess(), 403);

        // Матрица с учётом схемы судейства (simple / A/B).
        $matrix = ScoresSummaryMatrix::build($competition);

        Carbon::setLocale('ru');
        $dateStr = $competition->start_date
            ? Carbon::parse($competition->start_date)->translatedFormat('j F Y').' г.'
            : '';

        $html = view('pdf.scores-summary', [
            'competition' => $competition,
            'matrix' => $matrix,
            'dateStr' => $dateStr,
            'warn' => ScoresSummary::WARN_THRESHOLD,
            'danger' => ScoresSummary::DANGER_THRESHOLD,
        ])->render();

        if (! str_contains($html, 'charset=utf-8')) {
            $html = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>'.$html;
        }

        $pdf = Pdf::loadHTML($html);
        $pdf->setPaper('a4', 'landscape');
        $pdf->setOption(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => true]);

        return $pdf->stream('scores-summary-'.$competition->id.'.pdf');
    }
}
