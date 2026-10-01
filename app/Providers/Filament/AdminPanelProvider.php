<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Illuminate\Support\Facades\Blade;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('Wushu Expert CMS')
            ->colors([
                'primary' => Color::Sky,
                'danger' => '#dd0000'
            ])
            ->navigationGroups([
                'Управление',
                'Справочники',
                'Турнир',
            ])
            // Логотип в меню
            ->brandLogo(asset('images/logo.png'))
            ->brandLogoHeight('6rem')
            ->font('Exo 2')
            // 1. ОСНОВНОЙ ФАВИКОН
            ->favicon(asset('favicon/favicon.ico'))

            // 2. ПОДКЛЮЧЕНИЕ МАНИФЕСТА И ИКОНОК
            ->renderHook(
                'panels::head.start',
                fn (): string => Blade::render(<<<HTML
                    <link rel="apple-touch-icon" sizes="180x180" href="/favicon/apple-touch-icon.png">
                    <link rel="icon" type="image/png" sizes="32x32" href="/favicon/favicon-32x32.png">
                    <link rel="icon" type="image/png" sizes="16x16" href="/favicon/favicon-16x16.png">
                    <link rel="manifest" href="/favicon/site.webmanifest">
                    <meta name="msapplication-TileColor" content="#da532c">
                    <meta name="theme-color" content="#ffffff">
                HTML)
            )

            // 3. ИСПРАВЛЕННЫЙ CSS
            ->renderHook(
                'panels::head.end',
                fn (): string => '<style>
                    /* 1. ЛЕВАЯ КОЛОНКА (Логотип) */
                    .fi-sidebar-header {
                        height: 8rem !important; 
                        min-height: 8rem !important;
                        padding-top: 0 !important;
                        padding-bottom: 0 !important;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                    }
                    
                    .fi-sidebar-header .fi-logo {
                        height: auto;
                        max-height: 6rem;
                    }

                    /* 2. ПРАВАЯ КОЛОНКА (Топбар) */
                    .fi-topbar {
                        height: 8rem !important;
                        min-height: 8rem !important;
                        padding-top: 0 !important;
                        padding-bottom: 0 !important;
                    }

                    /* 3. Центрируем содержимое внутри Топбара */
                    .fi-topbar > nav {
                        height: 100% !important;
                        min-height: 100% !important;
                        padding-top: 0 !important;
                        padding-bottom: 0 !important;
                        display: flex;
                        align-items: center;
                    }

                    /* 4. ФУТЕР ПРИЛОЖЕНИЯ (50px, на всю ширину окна).
                          Стили здесь, а не Tailwind-классами: HTML renderHook
                          не попадает в сборку CSS Filament. */
                    .wushu-app-footer {
                        position: fixed;
                        left: 0;
                        right: 0;
                        bottom: 0;
                        z-index: 20;
                        height: 50px;
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                        padding: 0 1.5rem;
                        background: #f9fafb;
                        color: #030712;
                        border-top: 1px solid rgba(0, 0, 0, 0.1);
                        font-family: "Exo 2", sans-serif;
                        font-size: 12px;
                    }

                    /* Тёмная тема — белый текст, тёмный фон (bg-gray-950) */
                    :root.dark .wushu-app-footer {
                        background: #030712;
                        color: #fff;
                        border-top-color: rgba(255, 255, 255, 0.12);
                    }

                    .wushu-app-footer__copy {
                        display: inline-flex;
                        align-items: center;
                        gap: 0.5rem;
                    }

                    .wushu-app-footer img {
                        height: 22px;
                        width: auto;
                    }

                    /* Логотип разработчика зависит от темы: в светлой — d989.svg,
                       в тёмной — c989.svg (обе версии в разметке, переключение CSS) */
                    .wushu-app-footer__logo-light-theme {
                        display: inline-block;
                    }

                    .wushu-app-footer__logo-dark-theme {
                        display: none;
                    }

                    :root.dark .wushu-app-footer__logo-light-theme {
                        display: none;
                    }

                    :root.dark .wushu-app-footer__logo-dark-theme {
                        display: inline-block;
                    }

                    /* Компенсация фиксированной полосы футера */
                    body {
                        padding-bottom: 50px;
                    }

                    .fi-layout {
                        min-height: calc(100vh - 50px) !important;
                    }

                    .fi-sidebar-nav {
                        padding-bottom: 50px;
                    }
                </style>'
            )

            // 4. ФУТЕР ПРИЛОЖЕНИЯ (50px): версия слева, год + копирайт + лого разработчика справа
            ->renderHook(
                'panels::body.end',
                fn (): string => Blade::render(<<<'HTML'
                    <footer class="wushu-app-footer">
                        <span>Wushu Expert CMS {{ config('app.version') }}</span>
                        <span class="wushu-app-footer__copy">
                            <span>2026 &copy;</span>
                            <img class="wushu-app-footer__logo-light-theme" src="{{ asset('images/d989.svg') }}" alt="Max Moroz">
                            <img class="wushu-app-footer__logo-dark-theme" src="{{ asset('images/c989.svg') }}" alt="Max Moroz">
                            <span>Макс Мороз</span>
                        </span>
                    </footer>
                HTML),
            )

            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                // Pages\Dashboard::class, // <--- УБРАЛИ, ЧТОБЫ РАБОТАЛ НАШ КАСТОМНЫЙ Dashboard.php
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
