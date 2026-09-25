<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Правило 8.9 (docs/JUDGING_RULES.md): журнал действий судейства (аудит).
 */
class JudgingLog extends Model
{
    public const ACTION_SCORE_CREATED = 'score_created';
    public const ACTION_SCORE_UPDATED = 'score_updated';
    public const ACTION_SCORE_DELETED = 'score_deleted';
    public const ACTION_FINAL_SCORE_CHANGED = 'final_score_changed';
    public const ACTION_PROTOCOL_FINALIZED = 'protocol_finalized';

    protected $fillable = [
        'competition_id',
        'registration_id',
        'judge_id',
        'actor_id',
        'action',
        'old_value',
        'new_value',
        'reason',
        'details',
        'ip_address',
    ];

    protected $casts = [
        'old_value' => 'double',
        'new_value' => 'double',
        // Правило R-6.13: подробности (панель, коды сбавок, состав расчёта).
        'details' => 'array',
    ];

    /**
     * Человекочитаемые названия действий.
     *
     * @return array<string, string>
     */
    public static function actionLabels(): array
    {
        return [
            self::ACTION_SCORE_CREATED => 'Оценка выставлена',
            self::ACTION_SCORE_UPDATED => 'Оценка изменена',
            self::ACTION_SCORE_DELETED => 'Оценка снята',
            self::ACTION_FINAL_SCORE_CHANGED => 'Изменён итоговый балл',
            self::ACTION_PROTOCOL_FINALIZED => 'Протокол утверждён',
        ];
    }

    /**
     * Запись события в журнал. Никогда не должна ломать судейский процесс,
     * поэтому ошибки подавляются и пишутся в лог приложения.
     */
    public static function record(string $action, array $attributes = []): ?self
    {
        try {
            return self::create(array_merge([
                'actor_id' => Auth::id(),
                'ip_address' => request()?->ip(),
            ], $attributes, [
                'action' => $action,
            ]));
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    // --- СВЯЗИ ---

    public function competition()
    {
        return $this->belongsTo(Competition::class);
    }

    public function registration()
    {
        return $this->belongsTo(Registration::class);
    }

    public function judge()
    {
        return $this->belongsTo(User::class, 'judge_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
