<?php

namespace App\Support;

use App\Models\Competition;
use App\Models\DeductionCode;
use App\Models\JudgingLog;
use App\Models\Registration;
use App\Models\Score;
use App\Models\ScoreDeduction;
use Illuminate\Support\Facades\DB;

/**
 * Правила R-3.8, R-3.9, R-3.12, R-6.13 (docs/JUDGING_RULES.md).
 *
 * Единая запись оценки судьи (линейного или старшего) вместе со сбавками
 * судьи A и подробным аудитом. Оценка и её сбавки пишутся в одной транзакции.
 */
class ScoreWriter
{
    /**
     * Превращает список id нажатых кодов (с повторами, в порядке нажатия)
     * в снимки сбавок. Неизвестные / неактивные коды пропускаются.
     *
     * @param  array<int, int|string>  $codeIds
     * @return array<int, array{id: int, code: string, label: string, value: float}>
     */
    public static function snapshotDeductions(array $codeIds): array
    {
        if ($codeIds === []) {
            return [];
        }

        $codes = DeductionCode::active()
            ->whereIn('id', array_unique(array_map('intval', $codeIds)))
            ->get()
            ->keyBy('id');

        $result = [];

        foreach ($codeIds as $id) {
            $code = $codes->get((int) $id);

            if (!$code) {
                continue;
            }

            $result[] = [
                'id' => $code->id,
                'code' => (string) $code->code,
                'label' => (string) $code->label,
                'value' => round((float) $code->value, ScoreRange::PRECISION),
            ];
        }

        return $result;
    }

    /**
     * Сохранить (создать или исправить) оценку судьи.
     *
     * @param  array<int, array{id: int|null, code: string, label: string, value: float}>  $deductions
     * @return bool  true — оценка создана, false — исправлена
     */
    public static function save(
        Competition $competition,
        Registration $registration,
        int $judgeId,
        float $value,
        ?string $panel,
        array $deductions = [],
        string $updateReason = 'Исправление оценки',
    ): bool {
        $value = round($value, ScoreRange::PRECISION);

        return DB::transaction(function () use ($competition, $registration, $judgeId, $value, $panel, $deductions, $updateReason) {
            $existing = Score::where('registration_id', $registration->id)
                ->where('judge_id', $judgeId)
                ->lockForUpdate()
                ->first();

            $oldValue = $existing ? (float) $existing->score : null;
            $oldDeductions = $existing ? self::deductionsOf($existing) : [];

            if ($existing) {
                $existing->score = $value;
                $existing->panel = $panel;
                $existing->save();
                $score = $existing;

                // Правило R-3.16: исправление полностью переписывает набор сбавок.
                $score->deductions()->delete();
            } else {
                $score = Score::create([
                    'registration_id' => $registration->id,
                    'judge_id' => $judgeId,
                    'score' => $value,
                    'panel' => $panel,
                ]);
            }

            foreach ($deductions as $d) {
                ScoreDeduction::create([
                    'score_id' => $score->id,
                    'deduction_code_id' => $d['id'] ?? null,
                    'code' => $d['code'],
                    'label' => $d['label'],
                    'value' => $d['value'],
                ]);
            }

            $details = self::details($competition, $panel, $deductions);

            if ($existing) {
                $details['old_deductions'] = $oldDeductions;
            }

            $summary = self::summary($panel, $deductions);

            JudgingLog::record(
                $existing ? JudgingLog::ACTION_SCORE_UPDATED : JudgingLog::ACTION_SCORE_CREATED,
                [
                    'competition_id' => $competition->id,
                    'registration_id' => $registration->id,
                    'judge_id' => $judgeId,
                    'old_value' => $oldValue,
                    'new_value' => $value,
                    'reason' => $existing
                        ? trim($updateReason . ($summary ? '. ' . $summary : ''))
                        : ($summary ?: null),
                    'details' => $details,
                ]
            );

            return !$existing;
        });
    }

    /**
     * Снимок сбавок существующей оценки (для аудита).
     *
     * @return array<int, array{code: string, label: string, value: float}>
     */
    public static function deductionsOf(Score $score): array
    {
        return $score->deductions()->get()
            ->map(fn (ScoreDeduction $d) => [
                'code' => (string) $d->code,
                'label' => (string) $d->label,
                'value' => (float) $d->value,
            ])
            ->values()
            ->all();
    }

    /**
     * Правило R-6.13: подробности для judging_logs.details.
     */
    public static function details(Competition $competition, ?string $panel, array $deductions): array
    {
        $details = [
            'scheme' => $competition->judgingScheme(),
            'panel' => $panel,
        ];

        if ($panel === Competition::PANEL_A) {
            $details['start'] = JudgingCalculator::A_START;
            $details['deductions'] = array_map(fn (array $d) => [
                'code' => $d['code'],
                'label' => $d['label'],
                'value' => (float) $d['value'],
            ], $deductions);
            $details['deductions_total'] = round(array_sum(array_column($deductions, 'value')), ScoreRange::PRECISION);
        }

        return $details;
    }

    /**
     * Краткое резюме для judging_logs.reason, например:
     * «Панель A: 5.000 − (11 −0.100, 11 −0.100, 23 −0.300) = 4.500».
     */
    public static function summary(?string $panel, array $deductions): string
    {
        if (!$panel) {
            return '';
        }

        if ($panel !== Competition::PANEL_A) {
            return 'Панель ' . $panel;
        }

        if ($deductions === []) {
            return 'Панель A: без сбавок';
        }

        $parts = array_map(
            fn (array $d) => $d['code'] . ' −' . number_format((float) $d['value'], ScoreRange::PRECISION, '.', ''),
            $deductions
        );

        $result = JudgingCalculator::scoreFromDeductions(array_column($deductions, 'value'));

        return 'Панель A: 5.000 − (' . implode(', ', $parts) . ') = '
            . number_format($result, ScoreRange::PRECISION, '.', '');
    }
}
