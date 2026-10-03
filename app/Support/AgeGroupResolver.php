<?php

namespace App\Support;

use App\Models\AgeGroup;
use App\Models\Athlete;
use App\Models\Competition;

/**
 * Единый источник истины для определения возрастной группы заявки.
 *
 * Создание и редактирование заявки используют один и тот же расчёт,
 * чтобы age_group_id и age_group_label не разъезжались между экранами
 * (от них зависят протоколы, дипломы и лимиты оценок — см. ScoreRange).
 *
 * Логика:
 *  1. Возраст = год начала соревнования − год рождения (как во всём проекте).
 *  2. Группа ищется в справочнике age_groups по полу и диапазону
 *     min_age..max_age.
 *  3. Метка группы из справочника — единый формат «Название (min-max лет)».
 *  4. Если группы в справочнике нет — резервная метка по полу и возрасту
 *     («Мальчики (9-11 лет)» и т.п.).
 */
class AgeGroupResolver
{
    /** Метка, когда определить категорию невозможно. */
    public const UNKNOWN_LABEL = 'Не определено';

    /**
     * Определить возрастную группу для пары «спортсмен + соревнование».
     *
     * @return array{age_group_id: int|null, age_group_label: string}
     */
    public static function resolve(?Athlete $athlete, ?Competition $competition): array
    {
        if (! $athlete || ! $competition || ! $athlete->birth_date) {
            return ['age_group_id' => null, 'age_group_label' => self::UNKNOWN_LABEL];
        }

        $age = $competition->start_date->year - $athlete->birth_date->year;

        $group = self::findGroup($athlete, $age);

        if ($group) {
            return [
                'age_group_id' => $group->id,
                'age_group_label' => self::label($group),
            ];
        }

        return [
            'age_group_id' => null,
            'age_group_label' => self::manualLabel($athlete, $age),
        ];
    }

    /** Метка группы из справочника — единый формат «Название (min-max лет)». */
    public static function label(AgeGroup $group): string
    {
        return "{$group->name} ({$group->min_age}-{$group->max_age} лет)";
    }

    /** Группа из справочника по полу и возрасту (null — если в справочнике нет). */
    public static function findGroup(Athlete $athlete, int $age): ?AgeGroup
    {
        return AgeGroup::query()
            ->where('gender', self::normalizeGender($athlete))
            ->where('min_age', '<=', $age)
            ->where('max_age', '>=', $age)
            ->first();
    }

    /**
     * Нормализация пола: всё, что не «мужской», считается женским
     * (как в форме создания заявки).
     */
    public static function normalizeGender(Athlete $athlete): string
    {
        $genderRaw = mb_strtolower($athlete->gender ?? '');

        return in_array($genderRaw, ['male', 'm', 'man', 'мужской', 'муж', 'м']) ? 'male' : 'female';
    }

    /** Резервная метка, когда группы нет в справочнике. */
    public static function manualLabel(Athlete $athlete, int $age): string
    {
        $isMale = self::normalizeGender($athlete) === 'male';

        if ($age < 9) {
            return $isMale ? 'Мальчики (до 9 лет)' : 'Девочки (до 9 лет)';
        }
        if ($age >= 9 && $age <= 11) {
            return $isMale ? 'Мальчики (9-11 лет)' : 'Девочки (9-11 лет)';
        }
        if ($age >= 12 && $age <= 14) {
            return $isMale ? 'Юноши (12-14 лет)' : 'Девушки (12-14 лет)';
        }
        if ($age >= 15 && $age <= 17) {
            return $isMale ? 'Юниоры (15-17 лет)' : 'Юниорки (15-17 лет)';
        }
        if ($age >= 18 && $age <= 35) {
            return $isMale ? 'Мужчины (18-35 лет)' : 'Женщины (18-35 лет)';
        }
        if ($age >= 36) {
            return $isMale ? 'Ветераны (36+ лет)' : 'Ветераны-женщины (36+ лет)';
        }

        return "Категория ($age лет)";
    }
}
