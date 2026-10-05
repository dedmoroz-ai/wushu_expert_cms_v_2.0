<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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
        if (! $user) {
            return null;
        }

        $panel = $this->judges()->whereKey($user->getKey())->first()?->pivot?->panel;

        return in_array($panel, [self::PANEL_A, self::PANEL_B], true) ? $panel : null;
    }

    /**
     * Решение заказчика (05.10): ссылка на публичную страницу результатов
     * (для QR-кода и виджета дашборда). Токен public_token генерируется при
     * первом обращении; если колонки нет — временный токен «comp_<id>».
     */
    public function publicResultsUrl(): string
    {
        try {
            if (Schema::hasColumn('competitions', 'public_token')) {
                if (! $this->public_token) {
                    $this->public_token = Str::random(32);
                    $this->save();
                }

                return route('public.results', ['token' => $this->public_token]);
            }

            return route('public.results', ['token' => 'comp_'.$this->id]);
        } catch (\Exception $e) {
            return route('public.results', ['token' => 'comp_'.$this->id]);
        }
    }

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'status_code' => 'integer',
        // Замечание заказчика (02.10): окно подачи заявок тренерами.
        'registration_opens_at' => 'datetime',
        'registration_closes_at' => 'datetime',
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
     * Решение заказчика (05.10, вариант A): набор кодов сбавок соревнования
     * (override для пульта судьи A; порядок — как в глобальном справочнике).
     */
    public function deductionCodes()
    {
        return $this->belongsToMany(DeductionCode::class, 'competition_deduction_codes')
            ->withTimestamps()
            ->orderBy('deduction_codes.sort_order')
            ->orderBy('deduction_codes.code');
    }

    /**
     * Решение заказчика (05.10, вариант A): эффективный набор кодов сбавок для
     * пульта судьи A этого турнира — свой набор соревнования, иначе глобальный
     * активный набор справочника (значение по умолчанию).
     *
     * @return \Illuminate\Support\Collection<int, DeductionCode>
     */
    public function padDeductionCodes()
    {
        $codes = $this->deductionCodes()->get();

        return $codes->isNotEmpty()
            ? $codes
            : DeductionCode::active()->ordered()->get();
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
        if (! $user) {
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

    // --- ЗАМЕЧАНИЕ ЗАКАЗЧИКА (02.10): АКТУАЛЬНОЕ СОРЕВНОВАНИЕ И СЕССИЯ РЕГИСТРАЦИИ ---

    /**
     * Статус сессии регистрации заявок от тренеров: окно ещё не открыто.
     */
    public const REG_SESSION_PENDING = 'pending';

    /**
     * Статус сессии регистрации: заявки принимаются прямо сейчас.
     */
    public const REG_SESSION_OPEN = 'open';

    /**
     * Статус сессии регистрации: приём заявок закрыт.
     */
    public const REG_SESSION_CLOSED = 'closed';

    /**
     * Статус сессии регистрации: окно дат в настройках не задано.
     */
    public const REG_SESSION_UNKNOWN = 'unknown';

    /**
     * «Актуальное» соревнование для дашборда администратора (плашка
     * «Актуальное соревнование» и счётчик заявок текущей сессии).
     *
     * Приоритет: идёт прямо сейчас (status_code = 1) → ближайшее по дате
     * начала (сегодня или позже) → последнее по дате начала.
     */
    public static function actual(): ?self
    {
        $running = static::active();

        if ($running) {
            return $running;
        }

        $upcoming = static::whereDate('start_date', '>=', now()->toDateString())
            ->orderBy('start_date')
            ->orderBy('id')
            ->first();

        return $upcoming ?? static::orderByDesc('start_date')->orderByDesc('id')->first();
    }

    /**
     * Статус сессии регистрации заявок от тренеров.
     *
     * Считается от текущего времени по окну дат из настроек соревнования:
     * до открытия — «Ожидает открытия», в окне — «Идёт регистрация»,
     * после закрытия — «Регистрация завершена», окно не задано — «Не задано».
     */
    public function registrationSessionStatus(): string
    {
        if (! $this->registration_opens_at && ! $this->registration_closes_at) {
            return self::REG_SESSION_UNKNOWN;
        }

        $now = now();

        if ($this->registration_opens_at && $now->lt($this->registration_opens_at)) {
            return self::REG_SESSION_PENDING;
        }

        if ($this->registration_closes_at && $now->gt($this->registration_closes_at)) {
            return self::REG_SESSION_CLOSED;
        }

        return self::REG_SESSION_OPEN;
    }

    public function registrationSessionStatusLabel(): string
    {
        return match ($this->registrationSessionStatus()) {
            self::REG_SESSION_PENDING => 'Ожидает открытия',
            self::REG_SESSION_OPEN => 'Идёт регистрация',
            self::REG_SESSION_CLOSED => 'Регистрация завершена',
            default => 'Не задано',
        };
    }

    /**
     * Цвет бейджа статуса (цвета badge: gray / warning / success / danger).
     */
    public function registrationSessionStatusColor(): string
    {
        return match ($this->registrationSessionStatus()) {
            self::REG_SESSION_PENDING => 'warning',
            self::REG_SESSION_OPEN => 'success',
            self::REG_SESSION_CLOSED => 'danger',
            default => 'gray',
        };
    }

    // --- ЗАМЕЧАНИЕ ЗАКАЗЧИКА (02.10): СТАТУС СОРЕВНОВАНИЯ НА ДАШБОРДЕ СУДЬИ ---

    /**
     * Статус соревнования (status_code): «Скоро», «Запущено», «На паузе»,
     * «Завершено» — как на пульте управления (ManageCompetition).
     */
    public function statusLabel(): string
    {
        return match ((int) $this->status_code) {
            1 => 'Запущено',
            2 => 'На паузе',
            3 => 'Завершено',
            default => 'Скоро',
        };
    }

    /**
     * Цвет бейджа статуса соревнования (цвета badge: gray / info / success / warning).
     */
    public function statusColor(): string
    {
        return match ((int) $this->status_code) {
            1 => 'success',
            2 => 'warning',
            3 => 'gray',
            default => 'info',
        };
    }

    /**
     * Логотип федерации (из настроек соревнования: organization_logo),
     * с фолбэком на логотип федерации — как на табло.
     */
    public function logoUrl(): ?string
    {
        $path = $this->organization_logo;

        if (! $path && $this->federation) {
            $path = $this->federation->logo_path ?? $this->federation->logo;
        }

        return $path ? asset('storage/'.$path) : null;
    }

    /**
     * Аватар самого турнира (из настроек соревнования).
     *
     * Показывается справа от логотипа (в том же размере) на виджете
     * «Актуальное соревнование», публичной странице результатов и табло.
     * В документы (протоколы, дипломы) сознательно не входит.
     */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? asset('storage/'.$this->avatar_path) : null;
    }

    /**
     * Календарные даты проведения: «01.02.2026» или «01.02.2026 — 03.02.2026».
     */
    public function datesLabel(): string
    {
        if (! $this->start_date) {
            return '—';
        }

        $start = $this->start_date->format('d.m.Y');

        if (! $this->end_date || $this->end_date->isSameDay($this->start_date)) {
            return $start;
        }

        return $start.' — '.$this->end_date->format('d.m.Y');
    }

    /**
     * Адрес проведения: «Москва, Дворец спорта «Лужники»».
     */
    public function placeLabel(): string
    {
        $parts = array_filter([$this->city, $this->address], fn ($value) => filled($value));

        return $parts ? implode(', ', $parts) : '—';
    }
}
