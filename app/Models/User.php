<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser, HasAvatar
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar_path',
        'club_id',
        'role',
        'is_active_judge',
        'judge_category',
        'show_analytics',
        'show_scores_summary',
        'show_judging_log',
    ];

    /**
     * Значения по умолчанию (замечание заказчика 01.10): «Аналитика» включена
     * по умолчанию, «Сводка оценок» и «Журнал судейства» включает админ.
     */
    protected $attributes = [
        'show_analytics' => true,
        'show_scores_summary' => false,
        'show_judging_log' => false,
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active_judge' => 'boolean',
            'show_analytics' => 'boolean',
            'show_scores_summary' => 'boolean',
            'show_judging_log' => 'boolean',
        ];
    }

    // --- Логика Filament ---

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /**
     * Замечание заказчика (02.10): вместо заглушки ui-avatars — круглое
     * фото профиля, загруженное админом в карточке пользователя.
     */
    public function getFilamentAvatarUrl(): ?string
    {
        return filled($this->avatar_path)
            ? asset('storage/'.$this->avatar_path)
            : null;
    }

    // --- Хелперы для проверки ролей ---

    public function isAdmin()
    {
        return $this->role === 'admin';
    }

    public function isHeadJudge()
    {
        return $this->role === 'head_judge';
    }

    public function isJudge()
    {
        return in_array($this->role, ['judge', 'head_judge']);
    }

    /**
     * Тренер: роль coach или привязка к клубу (к клубу может быть
     * привязано несколько тренеров). Судьи тренерами не считаются.
     * Замечание заказчика (02.10).
     */
    public function isCoach()
    {
        return ! $this->isAdmin()
            && ! $this->isJudge()
            && ($this->role === 'coach' || $this->club_id !== null);
    }

    // --- Судейская категория (настройки судейской коллегии) ---

    /**
     * Судейские категории: код => полное название (как в карточке судьи
     * в разделе «Судейская коллегия»).
     *
     * @return array<string, string>
     */
    public static function judgeCategoryOptions(): array
    {
        return [
            'ССМК' => 'Международная категория (ССМК)',
            'ССВК' => 'Всероссийская категория (ССВК)',
            'СС1К' => 'Первая категория (СС1К)',
            'СС2К' => 'Вторая категория (СС2К)',
            'СС3К' => 'Третья категория (СС3К)',
            'ЮС' => 'Юный судья (ЮС)',
        ];
    }

    /**
     * Полное название судейской категории судьи (или сам код, если он
     * неизвестен словарю). null — категория не задана.
     */
    public function judgeCategoryLabel(): ?string
    {
        if (blank($this->judge_category)) {
            return null;
        }

        return self::judgeCategoryOptions()[$this->judge_category] ?? $this->judge_category;
    }

    // --- Связи ---

    /**
     * Связь: Пользователь принадлежит Клубу.
     */
    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    /**
     * Связь: Соревнования (как судья).
     * ПЕРЕИМЕНОВАЛИ ИЗ competitionsAsJudge В competitions, ЧТОБЫ УБРАТЬ ОШИБКУ
     */
    public function competitions()
    {
        return $this->belongsToMany(Competition::class, 'competition_user')
            ->withPivot('role_on_tournament', 'panel')
            ->withTimestamps();
    }
}
