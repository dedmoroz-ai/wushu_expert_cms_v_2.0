<?php

namespace App\Http\Controllers;

use App\Models\Competition;
use App\Support\JudgingCalculator;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Памятка судьям: активные коды сбавок с пояснениями (PDF) — печать
 * и раздача судьям. Состав кодов — как на пульте судьи A (R-7.8):
 * только активные (`is_active`), в порядке пульта (`sort_order`, `code`).
 */
class DeductionCodesMemoPdfController extends Controller
{
    public function __invoke(Competition $competition)
    {
        // Доступ — как в разделе «Коды сбавок» (админ и старший судья).
        abort_unless(static::canAccess(), 403);

        $html = view('pdf.deduction-codes-memo', static::memoData($competition))->render();

        if (! str_contains($html, 'charset=utf-8')) {
            $html = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>'.$html;
        }

        $pdf = Pdf::loadHTML($html);
        $pdf->setPaper('a4', 'portrait');
        $pdf->setOption(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => true]);

        return $pdf->stream('deduction-codes-memo-'.$competition->id.'.pdf');
    }

    /** Доступ к памятке: админ и старший судья (как к справочнику кодов). */
    public static function canAccess(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        return $user !== null && ($user->isAdmin() || $user->isHeadJudge());
    }

    /**
     * Данные памятки: активные коды, сгруппированные по `group_label`
     * ('' — без группы) в порядке пульта.
     */
    public static function memoData(Competition $competition): array
    {
        $groups = [];
        // Решение заказчика (05.10): памятка печатается по эффективному набору
        // кодов этого соревнования (свой набор или глобальный активный).
        foreach ($competition->padDeductionCodes() as $code) {
            $groups[$code->group_label ?: ''][] = $code;
        }

        Carbon::setLocale('ru');
        $dateStr = $competition->start_date
            ? Carbon::parse($competition->start_date)->translatedFormat('j F Y').' г.'
            : '';

        return [
            'competition' => $competition,
            'groups' => $groups,
            'dateStr' => $dateStr,
            'schemeLabel' => Competition::schemeLabels()[$competition->judgingScheme()] ?? '',
            'maxRepeats' => JudgingCalculator::MAX_CODE_REPEATS,
        ];
    }
}
