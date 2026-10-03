<?php

namespace App\Filament\Widgets;

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Registration;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class StatsOverview extends BaseWidget
{
    // Обновлять данные раз в 30 секунд (по желанию)
    protected static ?string $pollingInterval = '30s';

    /** Замечание заказчика (02.10): после плашки «Актуальное соревнование». */
    protected static ?int $sort = 2;

    protected function getStats(): array
    {
        $user = Auth::user();

        // --- ЛОГИКА ДЛЯ ТРЕНЕРА ---
        // Замечание заказчика (02.10): дашборд тренера — три плашки:
        // спортсмены клуба, заявки клуба в актуальной сессии регистрации
        // и все заявки этой сессии (по всем клубам).
        if ($user && $user->isCoach()) {
            $clubId = $user->club_id;
            $actual = Competition::actual();

            $clubRegistrations = ($actual && $clubId)
                ? Registration::where('competition_id', $actual->id)
                    ->whereHas('athlete', function ($q) use ($clubId) {
                        $q->where('club_id', $clubId);
                    })->count()
                : 0;

            return [
                Stat::make('Всего спортсменов в клубе', $clubId ? Athlete::where('club_id', $clubId)->count() : 0)
                    ->description('Активные спортсмены')
                    ->descriptionIcon('heroicon-m-user-group')
                    ->color('info'),

                Stat::make('Всего заявок клуба в текущей сессии', $clubRegistrations)
                    ->description('Актуальная сессия регистрации')
                    ->descriptionIcon('heroicon-m-clipboard-document-check')
                    ->color('success'),

                Stat::make('Всего заявок в текущей сессии (общее)', $actual ? Registration::where('competition_id', $actual->id)->count() : 0)
                    ->description('Все клубы')
                    ->descriptionIcon('heroicon-m-clipboard-document-list')
                    ->color('warning'),
            ];
        }

        // --- ЛОГИКА ДЛЯ АДМИНА И СУДЕЙ (линейного и старшего) ---
        // Замечание заказчика (02.10): три плашки в строчку — «Всего клубов»,
        // «Всего спортсменов» и «Всего заявок». Третья плашка — не «Все
        // соревнования», а сколько заявок подано в актуальной сессии регистрации.
        $actual = Competition::actual();

        return [
            Stat::make('Всего клубов', Club::count())
                ->description('Зарегистрированные организации')
                ->chart([7, 2, 10, 3, 15, 4, 17]) // Просто график для красоты
                ->color('primary'),

            Stat::make('Всего спортсменов', Athlete::count())
                ->description('Общая база')
                ->color('success'),

            Stat::make('Всего заявок', $actual ? Registration::where('competition_id', $actual->id)->count() : 0)
                ->description('Актуальная сессия регистрации')
                ->color('warning'),
        ];
    }
}
