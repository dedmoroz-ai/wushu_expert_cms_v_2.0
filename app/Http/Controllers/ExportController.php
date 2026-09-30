<?php

namespace App\Http\Controllers;

use App\Models\Competition;
use App\Models\Registration;
use App\Models\Style;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class ExportController extends Controller
{
    public function downloadDiplomas($competitionId, $styleId, $ageGroupId, $gender, $isSpecial = 0)
    {
        $competition = Competition::findOrFail($competitionId);
        $style = Style::findOrFail($styleId);

        // Группа «O» (R-6.15): отдельная выборка подгруппы; места 1–3 считаются
        // внутри подгруппы (алгоритм мест не меняется, меняется только выборка).
        $allParticipants = Registration::where('competition_id', $competitionId)
            ->where('style_id', $styleId)
            ->where('age_group_id', $ageGroupId)
            ->where('is_special', (bool) $isSpecial)
            ->whereHas('athlete', function ($query) use ($gender) {
                $query->where('gender', $gender);
            })
            ->whereNotNull('final_score') 
            ->where('final_score', '>', 0) 
            ->orderByDesc('final_score')   
            ->with(['athlete', 'partner', 'ageGroup']) 
            ->get();

        if ($allParticipants->isEmpty()) {
            return back()->with('error', 'В этой категории нет оценок.');
        }

        // Коллекция именно ДЛЯ ПЕЧАТИ (плоский список людей)
        $printList = collect(); 
        
        $rank = 0;            
        $prevScore = -1;      

        foreach ($allParticipants as $participant) {
            // Расчет места
            if ((string)$participant->final_score !== (string)$prevScore) {
                $rank++; 
            }

            // Только 1, 2 и 3 места
            if ($rank > 3) {
                break;
            }

            // --- 1. Добавляем ОСНОВНОГО спортсмена ---
            $printList->push([
                'name' => $participant->athlete->surname . ' ' . $participant->athlete->name,
                'place' => $rank,
                'registration' => $participant, // Ссылка на общие данные (группа, возраст)
            ]);

            // --- 2. Если есть ПАРТНЕР — добавляем и его отдельно ---
            if ($participant->partner) {
                $printList->push([
                    'name' => $participant->partner->surname . ' ' . $participant->partner->name,
                    'place' => $rank,
                    'registration' => $participant,
                ]);
            }

            $prevScore = $participant->final_score;
        }

        if ($printList->isEmpty()) {
             return back()->with('error', 'Нет призеров для печати');
        }

        $printDate = now();
        if ($competition->end_date && $competition->end_date < now()) {
             $printDate = $competition->end_date;
        }

        $pdf = Pdf::loadView('pdf.diplomas_blank', [
            'competition' => $competition,
            'style' => $style,
            'winners' => $printList, // Передаем плоский список людей
            'date' => $printDate->format('d.m.Y'),
        ]);

        $pdf->setPaper('a4', 'portrait');

        return $pdf->stream('diplomas.pdf');
    }
}
