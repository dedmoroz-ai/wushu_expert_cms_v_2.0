<?php

namespace App\Http\Controllers;

use App\Models\AgeGroup;
use App\Models\Competition;
use App\Support\ScoreRange;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Памятка судьям: лимиты оценок по возрастным группам (PDF) — печать
 * и раздача судьям. Две таблицы, как в форме справочника «Возрастные
 * группы»: «Лимиты оценок для этой категории» (min_score / max_score)
 * и «Сценарий A/B: лимиты оценок судей B» (b_min_score / b_max_score).
 * Показываются эффективные диапазоны: пустые поля справочника =
 * общий диапазон системы (категория 0.000–10.000, судья B 0.000–5.000).
 */
class AgeGroupsMemoPdfController extends Controller
{
    public function __invoke(Competition $competition)
    {
        // Доступ — как к памятке по кодам сбавок (админ и старший судья).
        abort_unless(static::canAccess(), 403);

        $html = view('pdf.age-groups-memo', static::memoData($competition))->render();

        if (! str_contains($html, 'charset=utf-8')) {
            $html = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>'.$html;
        }

        $pdf = Pdf::loadHTML($html);
        $pdf->setPaper('a4', 'portrait');
        $pdf->setOption(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => true]);

        return $pdf->stream('age-groups-memo-'.$competition->id.'.pdf');
    }

    /** Доступ к памятке: админ и старший судья (как к памятке по кодам сбавок). */
    public static function canAccess(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        return $user !== null && ($user->isAdmin() || $user->isHeadJudge());
    }

    /**
     * Данные памятки: все возрастные группы в порядке протокола
     * с эффективными лимитами оценок (правила 8.2, 8.3, R-4.19).
     */
    public static function memoData(Competition $competition): array
    {
        $rows = [];
        foreach (AgeGroup::query()->orderBy('sort_order')->orderBy('id')->get() as $group) {
            $category = ScoreRange::forAgeGroup($group);
            $panelB = ScoreRange::forPanel($group, 'B');

            $rows[] = [
                'name' => $group->name,
                'gender' => static::genderLabel($group->gender),
                'age' => $group->min_age.'–'.$group->max_age,
                'categoryMin' => static::format($category->min),
                'categoryMax' => static::format($category->max),
                'bMin' => static::format($panelB->min),
                'bMax' => static::format($panelB->max),
            ];
        }

        Carbon::setLocale('ru');
        $dateStr = $competition->start_date
            ? Carbon::parse($competition->start_date)->translatedFormat('j F Y').' г.'
            : '';

        return [
            'competition' => $competition,
            'rows' => $rows,
            'dateStr' => $dateStr,
            'schemeLabel' => Competition::schemeLabels()[$competition->judgingScheme()] ?? '',
        ];
    }

    /** Пол — как в таблице справочника. */
    protected static function genderLabel(?string $gender): string
    {
        return match ($gender) {
            'male' => 'Муж.',
            'female' => 'Жен.',
            'mixed' => 'Смеш.',
            default => (string) $gender,
        };
    }

    /** Формат оценки — три знака после точки (правило 8.1). */
    protected static function format(float $value): string
    {
        return number_format($value, ScoreRange::PRECISION, '.', '');
    }
}
