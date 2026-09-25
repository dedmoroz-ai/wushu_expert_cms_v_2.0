<?php

namespace App\Http\Controllers;

use App\Models\Competition;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class CompetitionPdfController extends Controller
{
    /**
     * Хелпер для получения картинки
     */
    private function getImageBase64($path)
    {
        if (!$path) return null;
        $fullPath = storage_path('app/public/' . $path);

        if (file_exists($fullPath)) {
            try {
                $type = pathinfo($fullPath, PATHINFO_EXTENSION);
                $data = file_get_contents($fullPath);
                return 'data:image/' . $type . ';base64,' . base64_encode($data);
            } catch (\Exception $e) {
                return null;
            }
        }
        return null;
    }

    /**
     * Хелпер для даты
     */
    private function getFormattedDate($date)
    {
        if (!$date) return '';
        try {
            Carbon::setLocale('ru');
            return Carbon::parse($date)->translatedFormat('j F Y') . ' г.';
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Вспомогательная функция для возраста
     */
    private function formatAgeGroup($ageGroup)
    {
        if (!$ageGroup) return '';
        if ($ageGroup->min_age && $ageGroup->max_age) {
            return "({$ageGroup->min_age}-{$ageGroup->max_age} лет)";
        } elseif ($ageGroup->min_age) {
            return "({$ageGroup->min_age}+ лет)";
        }
        return '';
    }

    /**
     * ГЛАВНАЯ ФУНКЦИЯ РЕНДЕРА (ЗАЩИТА КОДИРОВКИ)
     * Добавляет мета-тег UTF-8 и настраивает шрифт
     */
    private function renderPdfWithCharset($viewName, $data, $orientation = 'portrait')
    {
        $html = view($viewName, $data)->render();
        
        // Принудительно добавляем мета-тег, если его нет
        if (!str_contains($html, 'charset=utf-8')) {
            $html = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>' . $html;
        }

        $pdf = Pdf::loadHTML($html);
        $pdf->setPaper('a4', $orientation);
        $pdf->setOption(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => true]);

        return $pdf;
    }

    /**
     * 1. СТАРТОВЫЙ ПРОТОКОЛ
     */
    public function startList(Competition $competition)
    {
        $organizerName = $competition->federation?->name ?? 'ОРГАНИЗАТОР НЕ УКАЗАН';

        $logoBase64 = $this->getImageBase64($competition->organization_logo);
        $judgeSignBase64 = $this->getImageBase64($competition->chief_judge_signature);
        $secSignBase64 = $this->getImageBase64($competition->chief_secretary_signature);
        $stampBase64 = $this->getImageBase64($competition->organization_stamp);

        $judgeName = $competition->chief_judge_name;
        $secName = $competition->chief_secretary_name;
        $formattedDate = $this->getFormattedDate($competition->start_date);
        
        $locParts = [];
        if ($competition->city) $locParts[] = $competition->city;
        if ($competition->address) $locParts[] = $competition->address;
        $address = implode(', ', $locParts);

        $registrations = $competition->registrations()
            ->with(['athlete', 'athlete.club', 'style', 'ageGroup', 'partner'])
            ->join('styles', 'registrations.style_id', '=', 'styles.id')
            ->join('age_groups', 'registrations.age_group_id', '=', 'age_groups.id')
            ->join('athletes', 'registrations.athlete_id', '=', 'athletes.id')
            ->orderBy('styles.sort_order')
            ->orderBy('age_groups.sort_order')
            ->orderBy('athletes.gender') 
            ->orderBy('registrations.sort_order') 
            ->select('registrations.*')
            ->get();

        $grouped = $registrations->groupBy(function ($reg) {
            $ageStr = $this->formatAgeGroup($reg->ageGroup);
            $styleName = $reg->style?->name ?? 'Стиль';
            $groupName = $reg->ageGroup?->name ?? 'Группа';
            // Тире в UTF-8
            return sprintf("%s — %s %s", $styleName, $groupName, $ageStr);
        });

        return $this->renderPdfWithCharset('pdf.start-list', compact(
            'competition', 'grouped', 'organizerName', 'formattedDate', 'address',
            'logoBase64', 'judgeSignBase64', 'secSignBase64', 'stampBase64',
            'judgeName', 'secName'
        ))->stream('start-list.pdf');
    }

    /**
     * 2. ИТОГОВЫЙ ПРОТОКОЛ
     */
    public function finalResults(Competition $competition)
    {
        $organizerName = $competition->federation?->name ?? 'ОРГАНИЗАТОР НЕ УКАЗАН';
        
        $logoBase64 = $this->getImageBase64($competition->organization_logo);
        $judgeSignBase64 = $this->getImageBase64($competition->chief_judge_signature);
        $secSignBase64 = $this->getImageBase64($competition->chief_secretary_signature);
        $stampBase64 = $this->getImageBase64($competition->organization_stamp);

        $judgeName = $competition->chief_judge_name;
        $secName = $competition->chief_secretary_name;
        $formattedDate = $this->getFormattedDate($competition->start_date);
        
        $locParts = [];
        if ($competition->city) $locParts[] = $competition->city;
        if ($competition->address) $locParts[] = $competition->address;
        $address = implode(', ', $locParts);

        $registrations = $competition->registrations()
            ->with(['athlete', 'athlete.club', 'style', 'ageGroup', 'partner'])
            ->where('is_completed', true)
            ->join('styles', 'registrations.style_id', '=', 'styles.id')
            ->join('age_groups', 'registrations.age_group_id', '=', 'age_groups.id')
            ->join('athletes', 'registrations.athlete_id', '=', 'athletes.id')
            ->orderBy('styles.sort_order')
            ->orderBy('age_groups.sort_order')
            ->orderBy('athletes.gender')
            ->orderByDesc('registrations.final_score') 
            ->select('registrations.*')
            ->get();

        $grouped = $registrations->groupBy(function ($reg) {
            $ageStr = $this->formatAgeGroup($reg->ageGroup);
            $styleName = $reg->style?->name ?? 'Стиль';
            $groupName = $reg->ageGroup?->name ?? 'Группа';
            return sprintf("%s — %s %s", $styleName, $groupName, $ageStr);
        });

        return $this->renderPdfWithCharset('pdf.final-results', compact(
            'competition', 'grouped', 'organizerName', 'formattedDate', 'address',
            'logoBase64', 'judgeSignBase64', 'secSignBase64', 'stampBase64',
            'judgeName', 'secName'
        ))->stream('final-results.pdf');
    }

    /**
     * 3. ДИПЛОМЫ (ВОССТАНОВЛЕННАЯ ФУНКЦИЯ)
     */
    public function diplomas(Competition $competition)
    {
        // Получаем всех оцененных участников
        $registrations = $competition->registrations()
            ->with(['athlete', 'athlete.club', 'style', 'ageGroup', 'partner'])
            ->where('is_completed', true)
            ->whereNotNull('final_score')
            ->join('styles', 'registrations.style_id', '=', 'styles.id')
            ->join('age_groups', 'registrations.age_group_id', '=', 'age_groups.id')
            ->orderBy('styles.sort_order')
            ->orderBy('age_groups.sort_order')
            ->orderByDesc('final_score')
            ->select('registrations.*')
            ->get();

        $winners = collect();

        // Группируем по категории
        $grouped = $registrations->groupBy(function ($reg) {
            return $reg->style_id . '-' . $reg->age_group_id . '-' . $reg->athlete->gender;
        });

        // Определяем 1, 2, 3 места
        foreach ($grouped as $groupItems) {
            $rank = 1;
            $prevScore = null;
            $count = 0;

            foreach ($groupItems as $reg) {
                if ($prevScore !== $reg->final_score) {
                    $rank = $count + 1;
                }
                
                if ($rank <= 3) {
                    $reg->calculated_rank = $rank;
                    $winners->push($reg);
                }

                $count++;
                $prevScore = $reg->final_score;
            }
        }

        // Подготовка данных
        $organizerName = $competition->federation?->name ?? 'Федерация Ушу';
        $city = $competition->city ?? 'Город';
        $date = $this->getFormattedDate($competition->start_date);
        
        $judgeSignBase64 = $this->getImageBase64($competition->chief_judge_signature);
        $secSignBase64 = $this->getImageBase64($competition->chief_secretary_signature);
        $stampBase64 = $this->getImageBase64($competition->organization_stamp);
        
        $judgeName = $competition->chief_judge_name;
        $secName = $competition->chief_secretary_name;

        // ВАЖНО: 'landscape' - альбомная ориентация
        return $this->renderPdfWithCharset('pdf.diplomas_blank', compact(
            'winners', 'competition', 'organizerName', 'city', 'date',
            'judgeSignBase64', 'secSignBase64', 'stampBase64', 'judgeName', 'secName'
        ), 'landscape')->stream('diplomas.pdf');
    }
}
