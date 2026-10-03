<?php

namespace App\Filament\Widgets;

use Filament\Widgets\AccountWidget as BaseAccountWidget;

/**
 * Плашка «Добро пожаловать» над статистикой на дашборде.
 *
 * Замечание заказчика (02.10): плашка занимает всю ширину контента —
 * как ряд карточек статистики под ней.
 */
class AccountWidget extends BaseAccountWidget
{
    /**
     * @var int | string | array<string, int | string | null>
     */
    protected int|string|array $columnSpan = 'full';

    /**
     * Замечание заказчика (02.10): своя вью плашки — аватар увеличен
     * до 100px (верхний аватар в шапке не меняем).
     *
     * @var view-string
     */
    protected static string $view = 'filament.widgets.account-widget';
}
