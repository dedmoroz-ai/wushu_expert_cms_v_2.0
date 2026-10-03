<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Facades\Auth;

class Dashboard extends BaseDashboard
{
    // Иконка и название
    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = 'Инфопанель';

    /**
     * Замечание заказчика (01.10): «Инфопанель» — первый пункт меню судей
     * (старшего и линейных), после авторизации они попадают именно сюда.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    /** У судей «Инфопанель» идёт первой в их наборе пунктов меню. */
    public static function getNavigationSort(): ?int
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        return $user && $user->isJudge() ? 1 : parent::getNavigationSort();
    }
}
