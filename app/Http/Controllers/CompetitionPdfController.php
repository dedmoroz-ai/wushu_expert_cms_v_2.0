<?php

namespace App\Http\Controllers;

use App\Models\Competition;
use App\Models\Athlete;
use App\Models\Club;
use App\Models\User;
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

        // Группа «O» (R-6.15): подгруппа «(O)» идёт сразу после основной той же
        // номинации; порядок номинаций не меняется.
        $grouped = \App\Support\ProtocolGroups::sortSubgroups($registrations)
            ->groupBy(function ($reg) {
                $ageStr = $this->formatAgeGroup($reg->ageGroup);
                $styleName = $reg->style?->name ?? 'Стиль';
                $groupName = $reg->ageGroup?->name ?? 'Группа';
                // Тире в UTF-8
                return \App\Support\ProtocolGroups::title($styleName, $groupName, $ageStr, (bool) $reg->is_special);
            });

        return $this->renderPdfWithCharset('pdf.start-list', compact(
            'competition', 'grouped', 'organizerName', 'formattedDate', 'address',
            'logoBase64', 'judgeSignBase64', 'secSignBase64', 'stampBase64',
            'judgeName', 'secName'
        ))->stream('start-list.pdf');
    }

    /**
     * 2. ИТОГОВЫЙ ПРОТОКОЛ (без титульного листа)
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

        // ===== ОСНОВНЫЕ РЕГИСТРАЦИИ ДЛЯ ПРОТОКОЛА =====
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

        // Группа «O» (R-6.15): подгруппа «(O)» идёт сразу после основной той же
        // номинации; порядок номинаций не меняется.
        $grouped = \App\Support\ProtocolGroups::sortSubgroups($registrations)
            ->groupBy(function ($reg) {
                $ageStr = $this->formatAgeGroup($reg->ageGroup);
                $styleName = $reg->style?->name ?? 'Стиль';
                $groupName = $reg->ageGroup?->name ?? 'Группа';
                return \App\Support\ProtocolGroups::title($styleName, $groupName, $ageStr, (bool) $reg->is_special);
            });

        // ===== ДАННЫЕ ДЛЯ ЛИСТА КОМАНД =====
        $athleteIds = $competition->registrations()
            ->where('is_completed', true)
            ->pluck('athlete_id')
            ->unique();

        $clubIds = Athlete::whereIn('id', $athleteIds)
            ->pluck('club_id')
            ->filter()
            ->unique();

        $teams = Club::whereIn('id', $clubIds)
            ->orderBy('city')
            ->orderBy('name')
            ->get()
            ->map(function ($club) use ($athleteIds) {
                $count = Athlete::where('club_id', $club->id)
                    ->whereIn('id', $athleteIds)
                    ->count();
                return [
                    'city' => $club->city,
                    'name' => $club->name,
                    'count' => $count,
                ];
            })
            ->toArray();

        // ===== ИТОГОВАЯ СТАТИСТИКА (для footer-а в teams-page) =====
        $totalAthletes = $athleteIds->count();
        $totalClubs = $clubIds->count();

        // ===== ДАННЫЕ ДЛЯ ЛИСТА АДМИНИСТРАЦИИ =====
        $chiefJudgeName = $competition->chief_judge_name;
        $chiefSecretaryName = $competition->chief_secretary_name;

        // ХОТФИКС 8.4/8.5: в листе «СОСТАВ СУДЕЙСКОЙ КОЛЛЕГИИ» — только бригада
        // ЭТОГО турнира (competition_user), а не все активные судьи системы.
        // Судьи вне бригады остаются в системе, но в протокол не попадают.
        $headJudges = $competition->judges()
            ->with('club')
            ->where('users.role', 'head_judge')
            ->where('users.is_active_judge', true)
            ->orderBy('users.name')
            ->get();

        $lineJudges = $competition->judges()
            ->with('club')
            ->where('users.role', 'judge')
            ->where('users.is_active_judge', true)
            ->orderBy('users.name')
            ->get();

        return $this->renderPdfWithCharset('pdf.final-results', compact(
            'competition', 'grouped', 'organizerName', 'formattedDate', 'address',
            'logoBase64', 'judgeSignBase64', 'secSignBase64', 'stampBase64',
            'judgeName', 'secName',
            'teams', 'totalAthletes', 'totalClubs',
            'chiefJudgeName', 'chiefSecretaryName', 'headJudges', 'lineJudges'
        ))->stream('final-results.pdf');
    }

    /**
     * 2b. ТИТУЛЬНЫЙ ЛИСТ (отдельный PDF, страница 1)
     */
    public function titlePage(Competition $competition)
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

        // Уникальные спортсмены, которые реально выступили
        $athleteIds = $competition->registrations()
            ->where('is_completed', true)
            ->pluck('athlete_id')
            ->unique();

        $totalAthletes = $athleteIds->count();

        $totalClubs = Athlete::whereIn('id', $athleteIds)
            ->pluck('club_id')
            ->filter()
            ->unique()
            ->count();

        return $this->renderPdfWithCharset('pdf.title-page-standalone', compact(
            'competition', 'organizerName', 'formattedDate', 'address',
            'logoBase64', 'judgeSignBase64', 'secSignBase64', 'stampBase64',
            'judgeName', 'secName',
            'totalAthletes', 'totalClubs'
        ))->stream('title-page.pdf');
    }

    /**
     * 3. КОМАНДНЫЙ (КЛУБНЫЙ) ЗАЧЁТ (R-6.14, решения 30.09.2026)
     */
    public function teamStandings(Competition $competition)
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

        $standings = \App\Support\TeamStandings::forCompetition($competition);

        return $this->renderPdfWithCharset('pdf.team-standings', compact(
            'competition', 'standings', 'organizerName', 'formattedDate', 'address',
            'logoBase64', 'judgeSignBase64', 'secSignBase64', 'stampBase64',
            'judgeName', 'secName'
        ))->stream('team-standings.pdf');
    }
}
