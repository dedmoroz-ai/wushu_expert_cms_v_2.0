<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgeGroup extends Model
{
    use HasFactory;
    
    protected $guarded = [];

    protected $casts = [
        // Правила 8.2, 8.3: лимиты баллов возрастной категории.
        'min_score' => 'double',
        'max_score' => 'double',
        // Правило R-4.19: лимиты оценок судей B (сценарий A/B), шкала 0–5.
        'b_min_score' => 'double',
        'b_max_score' => 'double',
    ];

    /**
     * Диапазон допустимых оценок для этой возрастной категории.
     */
    public function scoreRange(): \App\Support\ScoreRange
    {
        return \App\Support\ScoreRange::forAgeGroup($this);
    }
}
