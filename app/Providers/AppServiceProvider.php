<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Filament\Http\Responses\Auth\Contracts\LoginResponse as LoginResponseContract;
use App\Http\Responses\LoginResponse;
use Illuminate\Support\Facades\URL;
use Illuminate\Http\Request; 

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Оставляем твою логику переопределения ответа при входе
        $this->app->bind(LoginResponseContract::class, LoginResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Принудительно включаем HTTPS, если мы не на локальном компьютере
        if (env('APP_ENV') !== 'local') {
            
            // 1. Заставляем генерировать ссылки с https
            URL::forceScheme('https');

            // 2. Жестко говорим Laravel, что мы доверяем прокси (Caddy/Docker)
            // Это чинит определение IP адреса и протокола
            Request::setTrustedProxies(
                ['*'],
                Request::HEADER_X_FORWARDED_FOR |
                Request::HEADER_X_FORWARDED_HOST |
                Request::HEADER_X_FORWARDED_PORT |
                Request::HEADER_X_FORWARDED_PROTO |
                Request::HEADER_X_FORWARDED_AWS_ELB
            );
            
            // 3. ФИНАЛЬНЫЙ ШТРИХ: Прямая подмена параметра запроса.
            // Если предыдущие пункты не сработали, это заставит Livewire поверить в HTTPS.
            request()->server->set('HTTPS', 'on');
        }
    }
}
