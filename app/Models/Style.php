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
}
