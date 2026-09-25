<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
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
        'club_id',
        'role',
        'is_active_judge',
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
        ];
    }

    // --- Логика Filament ---

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
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
