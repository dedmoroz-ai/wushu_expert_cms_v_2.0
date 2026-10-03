<?php

namespace App\Filament\Pages;

use App\Models\Competition;
use App\Support\ScoresSummaryMatrix;
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
     * Доступ: админы (как на сервере) + прежний список e-mail + судьи,
     * которым админ включил раздел в настройках судей (замечание 01.10).
     */
    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user && (
            $user->isAdmin()
            || in_array($user->email, self::ALLOWED_EMAILS, true)
            || ($user->isJudge() && $user->show_scores_summary)
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
}
