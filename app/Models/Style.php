<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Style extends Model
{
    use HasFactory;

    protected $guarded = [];

    /**
     * Категории видов программ — единый канон названий.
     *
     * Справочник «Виды программы» и форма заявки берут подписи только отсюда,
     * чтобы названия не разъезжались между экранами.
     */
    public const CATEGORIES = [
        'taolu' => 'Таолу (Комплексы)',
        'traditional' => 'Традиционное ушу',
        'yongchun' => 'Юнчуньцюань',
    ];

    /** Название категории по ключу (неизвестный ключ возвращается как есть). */
    public static function categoryLabel(?string $key): string
    {
        return self::CATEGORIES[$key] ?? (string) $key;
    }

    /**
     * Дуйлянь — парный вид: заявка ведётся с партнёром.
     */
    public function isDuilian(): bool
    {
        return str_contains(mb_strtolower($this->name), 'дуйлянь');
    }

    /**
     * Гуйдин Дуйда — парный вид: заявка ведётся с партнёром.
     */
    public function isDuida(): bool
    {
        return str_contains(mb_strtolower($this->name), 'дуйда');
    }

    /**
     * Парный вид (Дуйлянь / Дуйда): у заявки заполняется партнёр (R-6.15).
     */
    public function isPair(): bool
    {
        return $this->isDuilian() || $this->isDuida();
    }
}
