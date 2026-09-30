<?php

namespace Database\Seeders;

use App\Models\Style;
use Illuminate\Database\Seeder;

class StyleSeeder extends Seeder
{
    public function run(): void
    {
        $styles = [
            // --- ТАОЛУ (Спортивное) ---
            ['name' => 'Гуньшу', 'category' => 'taolu'],
            ['name' => 'Наньцюань', 'category' => 'taolu'],
            ['name' => 'Дуйлянь', 'category' => 'taolu'],
            ['name' => 'Дуйлянь Цисе', 'category' => 'taolu'],
            ['name' => 'Наньгунь', 'category' => 'taolu'],
            ['name' => 'Наньдао', 'category' => 'taolu'],
            ['name' => 'Чанцюань', 'category' => 'taolu'],
            ['name' => 'Тайцзицзянь', 'category' => 'taolu'], // Исправил опечатку скрина (слитно)
            ['name' => 'Тайцзицюань', 'category' => 'taolu'],
            ['name' => 'Цзитисянму', 'category' => 'taolu'],
            ['name' => 'Цзяньшу', 'category' => 'taolu'],
            ['name' => 'Цяншу', 'category' => 'taolu'],
            ['name' => 'Даошу', 'category' => 'taolu'],
            ['name' => 'Многоборье', 'category' => 'taolu'],

            // --- КУНГФУ (Традиционное) ---
            ['name' => 'Традиционное ушу Гуньшу', 'category' => 'traditional'],
            ['name' => 'Традиционное ушу Дуаньбин', 'category' => 'traditional'],
            ['name' => 'Традиционное ушу Дуйлянь', 'category' => 'traditional'],
            ['name' => 'Традиционное ушу Дуйлянь Цисе', 'category' => 'traditional'],
            ['name' => 'Традиционное ушу Жуаньбин', 'category' => 'traditional'],
            ['name' => 'Традиционное ушу Цюаньшу - 1 группа', 'category' => 'traditional'],
            ['name' => 'Традиционное ушу Цюаньшу - 2 группа', 'category' => 'traditional'],
            ['name' => 'Традиционное ушу Цюаньшу - 3 группа', 'category' => 'traditional'],
            ['name' => 'Традиционное ушу Цюаньшу - 4 группа', 'category' => 'traditional'],
            ['name' => 'Традиционное ушу Цюаньшу - 5 группа', 'category' => 'traditional'],
            ['name' => 'Традиционное ушу Цюаньшу - 6 группа', 'category' => 'traditional'],
            ['name' => 'Традиционное ушу Чанбин', 'category' => 'traditional'],
            ['name' => 'Традиционное ушу Шуанбин', 'category' => 'traditional'],
            ['name' => 'Традиционное ушу Бинци', 'category' => 'traditional'],
            ['name' => 'Традиционное ушу - Многоборье', 'category' => 'traditional'],
            ['name' => 'Традиционное ушу Тайцзицюань', 'category' => 'traditional'],

            // --- ЮНЧУНЬЦЮАНЬ (Вин Чунь) ---
            // Стандартизация категорий: раньше ошибочно лежали в 'traditional'.
            ['name' => 'Юнчуньцюань - Традиционные формы', 'category' => 'yongchun'],
            ['name' => 'Юнчуньцюань - Мужэньчжуан', 'category' => 'yongchun'],
            ['name' => 'Юнчуньцюань - Гуйдин', 'category' => 'yongchun'],
            ['name' => 'Юнчуньцюань - Гуйдин Дуйда', 'category' => 'yongchun'],
            ['name' => 'Юнчуньцюань - Гунь', 'category' => 'yongchun'],
            ['name' => 'Юнчуньцюань - Шуандао', 'category' => 'yongchun'],
        ];

        foreach ($styles as $style) {
            Style::firstOrCreate($style);
        }
    }
}
