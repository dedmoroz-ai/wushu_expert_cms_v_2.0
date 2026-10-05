<?php

namespace App\Filament\Widgets;

use App\Models\Competition;
use Filament\Widgets\Widget as BaseWidget;

/**
 * Решение заказчика (05.10): на дашборде тренера и администратора — QR-код
 * публичной страницы результатов (модалка со ссылкой и кнопкой «Копировать»,
 * разметка — по образцу qr-code-modal.blade.php). Тренеры делятся ссылкой
 * со зрителями: страница публичная, данные — результаты соревнования.
 */
class PublicResultsQrWidget extends BaseWidget
{
    /** Плашка видна сразу при загрузке дашборда — как «Актуальное соревнование». */
    protected static bool $isLazy = false;

    /**
     * Порядок на дашборде: «Добро пожаловать» (-1) → «Мой клуб» (0) →
     * «Актуальное соревнование» (1) → «Предварительный стартовый протокол» (2) →
     * этот виджет (3) → статистика (4).
     */
    protected static ?int $sort = 3;

    /** Виджет занимает половину строки (ряд из двух новых виджетов). */
    protected int|string|array $columnSpan = 1;

    /**
     * @var view-string
     */
    protected static string $view = 'filament.widgets.public-results-qr-widget';

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
        $competition = Competition::actual();

        return [
            'competition' => $competition,
            'publicUrl' => $competition?->publicResultsUrl(),
            'qrCodeUrl' => $competition ? route('competition.qr-code', $competition) : null,
        ];
    }
}
