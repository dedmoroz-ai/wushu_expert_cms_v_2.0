<?php

namespace App\Filament\Widgets;

use App\Models\Competition;
use Filament\Widgets\Widget as BaseWidget;

/**
 * Решение заказчика (05.10): на дашборде тренера и администратора — кнопка
 * «Предварительный стартовый протокол» (по поданным заявкам на актуальное
 * соревнование). Открывается в новой вкладке; страница — только авторизованным
 * (тренер и администратор), HTML-формат в стиле публичной страницы результатов.
 */
class PreStartListWidget extends BaseWidget
{
    /** Плашка видна сразу при загрузке дашборда — как «Актуальное соревнование». */
    protected static bool $isLazy = false;

    /**
     * Порядок на дашборде: «Добро пожаловать» (-1) → «Мой клуб» (0) →
     * «Актуальное соревнование» (1) → этот виджет (2) → QR-код (3) → статистика (4).
     */
    protected static ?int $sort = 2;

    /** Виджет занимает половину строки (ряд из двух новых виджетов). */
    protected int|string|array $columnSpan = 1;

    /**
     * @var view-string
     */
    protected static string $view = 'filament.widgets.pre-start-list-widget';

    /** Решение заказчика (05.10): видят только тренер и администратор (судьям — нет). */
    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->isAdmin() || $user->isCoach());
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'competition' => Competition::actual(),
        ];
    }
}
