<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Registration extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'events' => 'array',
        'is_completed' => 'boolean',
        // Группа «O» (особые спортсмены) — R-6.15: финальная отметка,
        // управляет разбивкой протоколов на подгруппы «(O)».
        'is_special' => 'boolean',
        // ВАЖНО: 'double' заставляет Laravel принимать любые дробные числа без округления
        'final_score' => 'double', 
        'score' => 'double', // На всякий случай для второй колонки
        // Правило R-4.20: средние панелей A и B (сценарий A/B).
        'score_a' => 'double',
        'score_b' => 'double',
    ];

    // --- СВЯЗИ ---

    public function competition()
    {
        return $this->belongsTo(Competition::class);
    }

    public function athlete()
    {
        return $this->belongsTo(Athlete::class);
    }

    public function partner()
    {
        return $this->belongsTo(Athlete::class, 'partner_id');
    }

    public function style()
    {
        return $this->belongsTo(Style::class);
    }

    public function ageGroup()
    {
        return $this->belongsTo(AgeGroup::class);
    }

    public function scores()
    {
        return $this->hasMany(Score::class);
    }

    /**
     * Журнал судейских действий по этой заявке (правило 8.9).
     */
    public function judgingLogs()
    {
        return $this->hasMany(JudgingLog::class);
    }

    /**
     * Правила 8.2, 8.3: диапазон допустимых оценок для этого выступления
     * (с учётом лимитов возрастной категории).
     */
    public function scoreRange(): \App\Support\ScoreRange
    {
        return \App\Support\ScoreRange::forRegistration($this);
    }
}
