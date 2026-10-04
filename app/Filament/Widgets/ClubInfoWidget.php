<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget as BaseWidget;

/**
 * Замечание заказчика (04.10): на дашборде тренера рядом с плашкой «Добро
 * пожаловать» (в половину строки) — виджет клуба в той же половине строки:
 * логотип клуба и данные клуба из его карточки (раздел «Клубы»): название,
 * город, регион. Виден только тренерам — дашборды администратора и судей
 * не трогаем.
 */
class ClubInfoWidget extends BaseWidget
{
    /** Как «Добро пожаловать» — сразу при загрузке, без доп. запроса. */
    protected static bool $isLazy = false;

    /**
     * Порядок на дашборде: «Добро пожаловать» (AccountWidget, -1) →
     * «Мой клуб» (0) → «Актуальное соревнование» (1) → статистика (2).
     * Первые две плашки укладываются в одну строку — обе в полстроки.
     */
    protected static ?int $sort = 0;

    /**
     * Замечание заказчика (04.10): виджет занимает половину строки —
     * вторую половину занимает плашка «Добро пожаловать».
     *
     * @var int | string | array<string, int | string | null>
     */
    protected int|string|array $columnSpan = 1;

    /**
     * @var view-string
     */
    protected static string $view = 'filament.widgets.club-info-widget';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->isCoach();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var \App\Models\User|null $user */
        $user = auth()->user();

        return [
            'club' => $user?->club,
        ];
    }
}