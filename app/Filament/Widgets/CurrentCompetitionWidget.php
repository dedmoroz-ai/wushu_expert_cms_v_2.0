<?php

namespace App\Filament\Widgets;

use App\Models\Competition;
use Filament\Widgets\Widget as BaseWidget;

/**
 * Замечание заказчика (02.10): полноширокая плашка «Актуальное соревнование»
 * под «Добро пожаловать»: логотип федерации (из настроек соревнования),
 * название, даты, адрес проведения и статус.
 *
 * Статус на плашке зависит от роли:
 *  - администратор и тренер — статус сессии регистрации заявок от тренеров
 *    («Ожидает открытия» / «Идёт регистрация» / «Регистрация завершена»);
 *  - судья и старший судья — статус самого соревнования: «Скоро» /
 *    «Запущено» / «На паузе» / «Завершено» (замечание заказчика 02.10).
 *
 * Видна администратору, тренеру и судьям (линейному и старшему).
 */
class CurrentCompetitionWidget extends BaseWidget
{
    /**
     * Замечание заказчика (02.10): плашка видна сразу при загрузке дашборда —
     * вместе с «Добро пожаловать» (там lazy тоже отключён), без доп. запроса.
     */
    protected static bool $isLazy = false;

    /**
     * Порядок на дашборде: «Добро пожаловать» → «Актуальное соревнование» →
     * статистика (AccountWidget сортируется как -1 по умолчанию).
     */
    protected static ?int $sort = 1;

    /**
     * @var int | string | array<string, int | string | null>
     */
    protected int|string|array $columnSpan = 'full';

    /**
     * @var view-string
     */
    protected static string $view = 'filament.widgets.current-competition-widget';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->isAdmin() || $user->isCoach() || $user->isJudge());
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = auth()->user();

        return [
            'competition' => Competition::actual(),
            // Судьям показываем статус соревнования, остальным — статус
            // сессии регистрации заявок от тренеров (замечание заказчика 02.10).
            'showCompetitionStatus' => $user !== null && $user->isJudge(),
        ];
    }
}
