<?php

namespace App\Http\Controllers;

use App\Filament\Pages\ScoresSummary;
use App\Models\Competition;
use App\Models\Registration;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class ScoresSummaryPdfController extends Controller
{
    public function __invoke(Competition $competition)
    {
        $user = Auth::user();
        abort_unless(
            $user && in_array($user->email, ScoresSummary::ALLOWED_EMAILS, true),
            403
        );

        $registrations = Registration::query()
            ->where('competition_id', $competition->id)
            ->where('is_completed', true)
            ->with(['athlete', 'athlete.club', 'partner', 'style', 'ageGroup', 'scores.judge'])
            ->join('styles', 'registrations.style_id', '=', 'styles.id')
            ->join('age_groups', 'registrations.age_group_id', '=', 'age_groups.id')
            ->join('athletes', 'registrations.athlete_id', '=', 'athletes.id')
            ->orderBy('styles.sort_order')
            ->orderBy('age_groups.sort_order')
            ->orderBy('athletes.gender')
            ->orderBy('registrations.sort_order')
            ->select('registrations.*')
            ->get();

        $judgesById = [];
        foreach ($registrations as $reg) {
            foreach ($reg->scores as $score) {
                if ($score->judge && ! isset($judgesById[$score->judge_id])) {
                    $judgesById[$score->judge_id] = $score->judge;
                }
            }
        }
        $judges = collect($judgesById)->sortBy('name')->values();

        $rows = [];
        foreach ($registrations as $reg) {
            $cells = [];
            $values = [];
            foreach ($judges as $judge) {
                $score = $reg->scores->firstWhere('judge_id', $judge->id);
                $val = $score?->score;
                $cells[$judge->id] = $val !== null ? (float) $val : null;
                if ($val !== null) $values[] = (float) $val;
            }
            $avg = count($values) ? array_sum($values) / count($values) : null;

            $rows[] = [
                'reg'   => $reg,
                'cells' => $cells,
                'avg'   => $avg,
                'final' => $reg->final_score !== null ? (float) $reg->final_score : null,
            ];
        }

        Carbon::setLocale('ru');
        $dateStr = $competition->start_date
            ? Carbon::parse($competition->start_date)->translatedFormat('j F Y') . ' г.'
            : '';

        $html = view('pdf.scores-summary', [
            'competition' => $competition,
            'judges'      => $judges,
            'rows'        => $rows,
            'dateStr'     => $dateStr,
            'warn'        => ScoresSummary::WARN_THRESHOLD,
            'danger'      => ScoresSummary::DANGER_THRESHOLD,
        ])->render();

        if (! str_contains($html, 'charset=utf-8')) {
            $html = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>' . $html;
        }

        $pdf = Pdf::loadHTML($html);
        $pdf->setPaper('a4', 'landscape');
        $pdf->setOption(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => true]);

        return $pdf->stream('scores-summary-' . $competition->id . '.pdf');
    }
}