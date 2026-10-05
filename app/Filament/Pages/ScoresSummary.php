<?php

namespace App\Filament\Pages;

use App\Models\Competition;
use App\Support\AiReportRunner;
use App\Support\ScoresSummaryMatrix;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class ScoresSummary extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationLabel = 'Сводка оценок';

    protected static ?string $navigationGroup = 'Турнир';

    protected static ?string $title = 'Сводная таблица оценок судей';

    protected static ?int $navigationSort = 50;

    protected static string $view = 'filament.pages.scores-summary';

    /** Кому разрешён доступ (дополнительно к админам) */
    public const ALLOWED_EMAILS = [
        'quadroraid@yandex.ru',
    ];

    /** Пороги отклонения от средней */
    public const WARN_THRESHOLD = 0.10; // жёлтый

    public const DANGER_THRESHOLD = 0.20; // красный

    /** Выбранное соревнование (приходит из GET ?competitionId=) */
    public ?int $competitionId = null;

    /* ============== Доступ ============== */

    /**
     * Доступ: администратор — всегда; остальные роли (судья, старший судья,
     * тренер) — по переключателю «Сводка оценок» в карточке пользователя
     * (раздел «Пользователи»), плюс прежний список e-mail (решение заказчика
     * 05.10 — оставлен как дополнительный байпас).
     */
    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user && (
            $user->isAdmin()
            || in_array($user->email, self::ALLOWED_EMAILS, true)
            || $user->show_scores_summary
        );
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    /** У судей разделы идут плоским списком (без групп), у админа — в «Турнире». */
    public static function getNavigationGroup(): ?string
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        return $user && $user->isJudge() ? null : parent::getNavigationGroup();
    }

    /** Позиция в наборе пунктов меню судьи: Инфопанель(1), Пульт(2), … Сводка(4). */
    public static function getNavigationSort(): ?int
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        return $user && $user->isJudge() ? 4 : parent::getNavigationSort();
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);

        // Подхват competitionId из GET
        if (request()->filled('competitionId')) {
            $this->competitionId = (int) request()->query('competitionId');
        }
    }

    /* ============== Данные ============== */

    public function getCompetitionsList(): Collection
    {
        return Competition::orderBy('start_date', 'desc')
            ->get(['id', 'name', 'start_date']);
    }

    public function getCompetition(): ?Competition
    {
        return $this->competitionId ? Competition::find($this->competitionId) : null;
    }

    /**
     * Матрица: судьи × выступления (с учётом схемы судейства simple/A/B).
     */
    public function getMatrix(): ?array
    {
        return ScoresSummaryMatrix::build($this->getCompetition());
    }

    /** Уровень отклонения: 'ok' | 'warn' | 'danger' */
    public static function deviationLevel(?float $value, ?float $avg): string
    {
        if ($value === null || $avg === null) {
            return 'ok';
        }
        $diff = abs($value - $avg);
        if ($diff >= self::DANGER_THRESHOLD) {
            return 'danger';
        }
        if ($diff >= self::WARN_THRESHOLD) {
            return 'warn';
        }

        return 'ok';
    }

    /**
     * AI-аналитика по запросу (docs/ANALYTICS.md): генерация HTML-отчёта уходит
     * в фоновый CLI-процесс (AiReportRunner). LLM думает до минут — HTTP-запрос
     * не должен ждать (nginx обрывает долгий ответ по fastcgi_read_timeout,
     * пользователь получал 504). Статус генерации показывается под кнопками
     * и обновляется по wire:poll.
     */
    public function generateAiAnalytics(): void
    {
        if (! Auth::user()?->isAdmin()) {
            Notification::make()
                ->title('Недостаточно прав')
                ->body('AI-аналитику генерирует администратор.')
                ->warning()
                ->send();

            return;
        }

        $competition = $this->getCompetition();
        if (! $competition) {
            Notification::make()
                ->title('Соревнование не выбрано')
                ->body('Выберите соревнование в списке выше.')
                ->warning()
                ->send();

            return;
        }

        try {
            $launch = app(AiReportRunner::class)->start($competition);
        } catch (\Throwable $e) {
            Notification::make()
                ->title('AI-аналитика не запущена')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        if ($launch['already_running']) {
            Notification::make()
                ->title('Генерация уже идёт')
                ->body('Дождитесь завершения текущей генерации — статус показан под кнопками.')
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Генерация AI-отчёта запущена')
            ->body('Отчёт появится в разделе «Аналитика» через 1–3 минуты.')
            ->success()
            ->send();
    }

    /** Статус фоновой генерации AI-отчёта для UI (null — генераций не было). */
    public function getAiReportState(): ?array
    {
        $competition = $this->getCompetition();

        return $competition
            ? app(AiReportRunner::class)->state($competition)
            : null;
    }
}
