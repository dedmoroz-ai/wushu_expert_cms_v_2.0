<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Competition extends Model
{
    use HasFactory;

    // Правило R-2.11: сценарии судейства турнира.
    public const SCHEME_SIMPLE = 'simple';
    public const SCHEME_AB = 'ab';

    // Правило R-4.18: функции (панели) судей в сценарии A/B.
    public const PANEL_A = 'A';
    public const PANEL_B = 'B';

    // Разрешаем заполнять все поля
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    public static function schemeLabels(): array
    {
        return [
            self::SCHEME_SIMPLE => 'Простой (все судьи ставят общий балл 0–10)',
            self::SCHEME_AB => 'A/B (A — сбавки от 5.000, B — оценка 0–5)',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function panelLabels(): array
    {
        return [
            self::PANEL_A => 'A — качество исполнения (сбавки)',
            self::PANEL_B => 'B — общее впечатление',
        ];
    }

    /**
     * Сценарий турнира (пустое значение трактуем как simple).
     */
    public function judgingScheme(): string
    {
        return $this->judging_scheme === self::SCHEME_AB ? self::SCHEME_AB : self::SCHEME_SIMPLE;
    }

    public function isAbScheme(): bool
    {
        return $this->judgingScheme() === self::SCHEME_AB;
    }

    /**
     * Правило R-4.18: функция судьи в бригаде турнира (A / B) или null.
     */
    public function panelOf(?User $user): ?string
    {
        if (!$user) {
            return null;
        }

        $panel = $this->judges()->whereKey($user->getKey())->first()?->pivot?->panel;

        return in_array($panel, [self::PANEL_A, self::PANEL_B], true) ? $panel : null;
    }

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'status_code' => 'integer',
    ];

    // --- СУЩЕСТВУЮЩИЕ СВЯЗИ ---

    // Связь с Федерацией
    public function federation()
    {
        return $this->belongsTo(Federation::class);
    }

    // Связь: У соревнования много заявок
    public function registrations()
    {
        return $this->hasMany(Registration::class);
    }

    /**
     * Кто сейчас на ковре?
     */
    public function currentRegistration()
    {
        return $this->belongsTo(Registration::class, 'current_registration_id');
    }

    // --- НОВЫЕ СВЯЗИ (ДЛЯ СУДЕЙСТВА) ---

    /**
     * Список судей (User), которые назначены именно на ЭТО соревнование.
     * Связь через таблицу 'competition_user'.
     */
    public function judges()
    {
        return $this->belongsToMany(User::class, 'competition_user')
                    ->withPivot('role_on_tournament', 'panel') // Роль и функция судьи (A/B)
                    ->withTimestamps();
    }

    /**
     * Правило 8.4: бригада турнира, участвующая в расчёте.
     *
     * Только судьи, привязанные к ЭТОМУ соревнованию и допущенные к судейству.
     * Судьи, не привязанные к турниру, остаются в системе, но в расчёте
     * и на пультах не участвуют.
     */
    public function activeJudges()
    {
        return $this->judges()
                    ->whereIn('role', ['judge', 'head_judge'])
                    ->where('is_active_judge', true);
    }

    /**
     * Правило 8.5: проверка, входит ли пользователь в бригаду соревнования
     * и допущен ли он к судейству.
     */
    public function hasActiveJudge(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        return $this->activeJudges()->whereKey($user->getKey())->exists();
    }

    /**
     * Журнал судейских действий по соревнованию (правило 8.9).
     */
    public function judgingLogs()
    {
        return $this->hasMany(JudgingLog::class);
    }

    /**
     * Текущее активное соревнование («идёт»).
     *
     * Вынесено в модель, чтобы пульты и табло использовали единую точку входа.
     * Правило 8.11 (один ковёр) сохраняется осознанно.
     */
    public static function active(): ?self
    {
        return static::where('status_code', 1)->first();
    }
}
