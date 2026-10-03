<?php

namespace App\Http\Responses;

use Filament\Http\Responses\Auth\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    /**
     * Куда перенаправлять пользователя сразу после входа.
     *
     * Замечание заказчика (01.10): старший судья и линейные судьи после авторизации
     * попадают на дашборд («Инфопанель»), а не сразу в судейские пульты.
     * Пульты доступны им из сайдбара («Судейский пульт»).
     */
    public function toResponse($request)
    {
        // Все роли (Админ, Тренер, Старший судья, Линейный судья) -> на стандартную
        // Главную (Dashboard).
        return redirect()->to(filament()->getHomeUrl());
    }
}
