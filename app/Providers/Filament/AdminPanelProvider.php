<?php

namespace App\Providers\Filament;

use App\Filament\Widgets\AccountWidget;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

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
            // Единые цвета кнопок (замечание заказчика 05.10): пять стандартов —
            // серый #E6E9E8 (текст #272727), синий #0A92BA, зелёный #229954,
            // оранжевый #E67E22, красный #DC3532 (текст #FFFFFF). Shade 600 —
            // заливка кнопки (bg-custom-600), shade 500 — осветлённый hover.
            // Палитру `gray` НЕ перебиваем (правка 05.10): она красит не только
            // кнопки, но и текст пунктов меню сайдбара (светлая тема) и фоны
            // тёмной темы. Серые КНОПКИ — стандарт #E6E9E8/#272727 инлайн-CSS ниже.
            ->colors([
                'primary' => self::buttonPalette('#0A92BA'),
                'info' => self::buttonPalette('#0A92BA'),
                'success' => self::buttonPalette('#229954'),
                'warning' => self::buttonPalette('#E67E22'),
                'danger' => self::buttonPalette('#DC3532'),
            ])
            ->navigationGroups([
                'Управление',
                'Справочники',
                'Турнир',
                'Документация',
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
                fn (): string => Blade::render(<<<'HTML'
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
                        justify-content: center;
                        gap: 0.5rem;
                        flex-wrap: wrap;
                        padding: 0 1.5rem;
                        background: #f9fafb;
                        color: #030712;
                        border-top: 1px solid rgba(0, 0, 0, 0.1);
                        font-family: "Exo 2", sans-serif;
                        font-size: 12px;
                    }

                    /* Тёмная тема — белый текст, тёмный фон (bg-gray-950) */
                    :root.dark .wushu-app-footer {
                        background: #000000;
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

                    /* Компенсация фиксированной полосы футера (50px).
                           Правка 05.10 («съезжающая» шапка/сайдбар): padding-bottom
                           на body нельзя — он даёт «мёртвый ход» прокрутки, из-за
                           которого в конце страницы sticky-топбар и sticky-сайдбар
                           (h-screen) уезжают вверх на 50px и разъезжаются с контентом.
                           Компенсация футера — padding-bottom контента и навигации. */
                    .fi-layout {
                        min-height: 100vh !important;
                    }

                    .fi-main {
                        padding-bottom: 60px;
                    }

                    .fi-sidebar-nav {
                        padding-bottom: 50px;
                    }

                    /* Сайдбар всегда прилипает к верху окна и не «съезжает»
                           вместе с контентом в конце прокрутки */
                    .fi-sidebar.fi-main-sidebar {
                        top: 0;
                    }

                    /* Серые кнопки — стандарт #E6E9E8 (текст #272727):
                           встроенный стиль Filament (белая заливка) перебивается,
                           т.к. панель грузит только @filamentStyles */
                    .fi-btn.fi-color-gray {
                        background-color: #E6E9E8 !important;
                        color: #272727 !important;
                    }

                    .fi-btn.fi-color-gray:hover {
                        background-color: #d8dddc !important;
                    }

                    .fi-btn.fi-color-gray .fi-btn-icon {
                        color: #272727 !important;
                    }

                    /* Тёмная тема (замечание заказчика 05.10): серые КНОПКИ
                           становятся тёмными — фон #18181B, текст и иконки белые.
                           Только кнопки: меню сайдбара, фоны и таблицы не тронуты
                           (палитра `gray` остаётся дефолтной Filament). */
                    :root.dark .fi-btn.fi-color-gray {
                        background-color: #18181B !important;
                        color: #FFFFFF !important;
                    }

                    :root.dark .fi-btn.fi-color-gray:hover {
                        background-color: #27272A !important;
                    }

                    :root.dark .fi-btn.fi-color-gray .fi-btn-icon {
                        color: #FFFFFF !important;
                    }

                    /* Серые кнопки кастомных страниц (пульты судей, «Сводка
                           оценок»): общий класс вместо инлайн-заливок #E6E9E8,
                           чтобы тёмная тема перекрашивала кнопки через :root.dark,
                           а не ломалась JS-ховерами, сбрасывающими фон в светлый. */
                    .btn-soft-gray {
                        background-color: #E6E9E8 !important;
                        color: #272727 !important;
                        border-color: rgba(0, 0, 0, 0.08) !important;
                    }

                    .btn-soft-gray:hover:not(:disabled) {
                        background-color: #d8dddc !important;
                    }

                    .btn-soft-gray:disabled {
                        opacity: 0.5;
                        cursor: not-allowed;
                    }

                    :root.dark .btn-soft-gray {
                        background-color: #18181B !important;
                        color: #FFFFFF !important;
                        border-color: rgba(255, 255, 255, 0.12) !important;
                    }

                    :root.dark .btn-soft-gray:hover:not(:disabled) {
                        background-color: #27272A !important;
                    }

                    /* Тонкие ползунки скролла (замечание заказчика 05.10):
                           бегунок — синий #0A92BA в обеих темах,
                           дорожка — серая #E6E9E8 (светлая тема) и #18181B (тёмная) */
                    * {
                        scrollbar-width: thin;
                        scrollbar-color: #0A92BA #E6E9E8;
                    }

                    :root.dark * {
                        scrollbar-color: #0A92BA #18181B;
                    }

                    ::-webkit-scrollbar {
                        width: 6px;
                        height: 6px;
                    }

                    ::-webkit-scrollbar-track {
                        background: #E6E9E8;
                    }

                    :root.dark ::-webkit-scrollbar-track {
                        background: #18181B;
                    }

                    ::-webkit-scrollbar-thumb {
                        background-color: #0A92BA;
                        border-radius: 3px;
                    }
                </style>'
            )

            // 4. ФУТЕР ПРИЛОЖЕНИЯ (50px): название + версия, копирайт + год + имя + лого разработчика —
            // по центру. Заказчик (04.10): единая строка
            // «WUSHU EXPERT COMPETITION MANAGEMENT SYSTEM 3.0 © 2026 МАКС МОРОЗ (logo)».
            ->renderHook(
                'panels::body.end',
                fn (): string => Blade::render(<<<'HTML'
                    <footer class="wushu-app-footer">
                        <span>WUSHU EXPERT COMPETITION MANAGEMENT SYSTEM {{ config('app.version') }}</span>
                        <span class="wushu-app-footer__copy">
                            <span>&copy; 2026</span>
                            <span>МАКС МОРОЗ</span>
                            <img class="wushu-app-footer__logo-light-theme" src="{{ asset('images/d989.svg') }}" alt="Max Moroz">
                            <img class="wushu-app-footer__logo-dark-theme" src="{{ asset('images/c989.svg') }}" alt="Max Moroz">
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
                AccountWidget::class,
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

    /**
     * Палитра Filament (shade 50–950) из «плоского» hex стандартного цвета
     * кнопки (замечание заказчика 05.10). Shade 600 — заливка кнопки
     * (bg-custom-600) равен базовому цвету; shade 500 (hover) и светлые
     * оттенки — осветление к белому, 700–950 — затемнение.
     *
     * @return array<int, string> RGB-строки «r, g, b»
     */
    private static function buttonPalette(string $hex): array
    {
        $red = (int) hexdec(substr($hex, 1, 2));
        $green = (int) hexdec(substr($hex, 3, 2));
        $blue = (int) hexdec(substr($hex, 5, 2));

        $light = static fn (float $intensity): string => sprintf(
            '%d, %d, %d',
            (int) round((255 - $red) * $intensity + $red),
            (int) round((255 - $green) * $intensity + $green),
            (int) round((255 - $blue) * $intensity + $blue),
        );

        $dark = static fn (float $factor): string => sprintf(
            '%d, %d, %d',
            (int) round($red * $factor),
            (int) round($green * $factor),
            (int) round($blue * $factor),
        );

        return [
            50 => $light(0.95),
            100 => $light(0.9),
            200 => $light(0.75),
            300 => $light(0.6),
            400 => $light(0.35),
            500 => $light(0.12),
            600 => "{$red}, {$green}, {$blue}",
            700 => $dark(0.9),
            800 => $dark(0.75),
            900 => $dark(0.6),
            950 => $dark(0.4),
        ];
    }
}
