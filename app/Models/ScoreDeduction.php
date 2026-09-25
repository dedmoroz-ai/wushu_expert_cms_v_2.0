<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Правило R-3.12 (docs/JUDGING_RULES.md): одна нажатая судьёй A сбавка.
 * code / label / value — снимок справочника на момент оценки.
 */
class ScoreDeduction extends Model
{
    protected $fillable = ['score_id', 'deduction_code_id', 'code', 'label', 'value'];

    protected $casts = [
        'value' => 'double',
    ];

    public function score()
    {
        return $this->belongsTo(Score::class);
    }

    public function deductionCode()
    {
        return $this->belongsTo(DeductionCode::class);
    }
}
