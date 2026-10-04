<?php

namespace App\Filament\Widgets;

use Filament\Widgets\AccountWidget as BaseAccountWidget;

/**
 * Плашка «Добро пожаловать» над статистикой на дашборде.
 *
 * Замечание заказчика (02.10): плашка занимает всю ширину контента —
 * как ряд карточек статистики под ней.
 *
 * Замечание заказчика (04.10): у тренера плашка занимает половину строки —
 * рядом в той же строке виджет клуба (ClubInfoWidget). У администратора
 * и судей плашка по-прежнему во всю ширину — их дашборды не трогаем.
 */
class AccountWidget extends BaseAccountWidget
{
    /**
     * @var int | string | array<string, int | string | null>
     */
    protected int|string|array $columnSpan = 'full';

    /**
     * Замечание заказчика (04.10): у тренера плашка — в половину строки
     * (колонка 2-колоночного грида дашборда), рядом — виджет клуба.
     * Остальные роли — во всю ширину, как раньше.
     *
     * @return int | string | array<string, int | string | null>
     */
    public function getColumnSpan(): int|string|array
    {
        /** @var \App\Models\User|null $user */
        $user = auth()->user();

        return $user !== null && $user->isCoach() ? 1 : 'full';
    }

    /**
     * Замечание заказчика (02.10): своя вью плашки — аватар увеличен
     * до 100px (верхний аватар в шапке не меняем).
     *
     * @var view-string
     */
    protected static string $view = 'filament.widgets.account-widget';
}
