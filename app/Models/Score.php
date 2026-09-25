<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Score extends Model
{
    protected $fillable = ['registration_id', 'judge_id', 'score', 'panel'];

    protected $casts = [
        // Правило 8.1: scores.score = decimal(5,3), единая точность 3 знака.
        // 'double' гарантирует, что оценка 8.865 не превратится в 8.87
        'score' => 'double', 
    ];

    public function logs()
    {
        return $this->hasMany(JudgingLog::class, 'registration_id', 'registration_id');
    }

    public function judge()
    {
        return $this->belongsTo(User::class, 'judge_id');
    }

    public function registration()
    {
        return $this->belongsTo(Registration::class);
    }

    /**
     * Правило R-3.12: сбавки судьи A, из которых сложилась оценка.
     */
    public function deductions()
    {
        return $this->hasMany(ScoreDeduction::class)->orderBy('id');
    }
}
