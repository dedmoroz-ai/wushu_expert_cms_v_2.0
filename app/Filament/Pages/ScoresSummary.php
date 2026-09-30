<?php

namespace App\Filament\Pages;

use App\Models\Competition;
use App\Models\Registration;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Collection;

class ScoresSummary extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-table-cells';
    protected static ?string $navigationLabel = 'Сводка оценок';
    protected static ?string $navigationGroup = 'Турнир';
    protected static ?string $title           = 'Сводная таблица оценок судей';
    protected static ?int    $navigationSort  = 50;

    protected static string $view = 'filament.pages.scores-summary';

    /** Кому разрешён доступ */
    public const ALLOWED_EMAILS = [
        'quadroraid@yandex.ru',
    ];

    /** Пороги отклонения от средней */
    public const WARN_THRESHOLD   = 0.10; // жёлтый
    public const DANGER_THRESHOLD = 0.20; // красный

    /** Выбранное соревнование (приходит из GET ?competitionId=) */
    public ?int $competitionId = null;

    /* ============== Доступ ============== */

    public static function canAccess(): bool
    {
        $user = Auth::user();
        return $user && in_array($user->email, self::ALLOWED_EMAILS, true);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
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
     * Матрица: судьи × выступления.
     */
    public function getMatrix(): ?array
    {
        $competition = $this->getCompetition();
        if (! $competition) return null;

        $registrations = Registration::query()
            ->where('competition_id', $competition->id)
            ->where('is_completed', true)
            ->with([
                'athlete', 'athlete.club', 'partner',
                'style', 'ageGroup', 'scores.judge',
            ])
            ->join('styles', 'registrations.style_id', '=', 'styles.id')
            ->join('age_groups', 'registrations.age_group_id', '=', 'age_groups.id')
            ->join('athletes', 'registrations.athlete_id', '=', 'athletes.id')
            ->orderBy('styles.sort_order')
            ->orderBy('age_groups.sort_order')
            ->orderBy('athletes.gender')
            ->orderBy('registrations.sort_order')
            ->select('registrations.*')
            ->get();

        // Только судьи, реально оценившие хоть кого-то
        $judgesById = [];
        foreach ($registrations as $reg) {
            foreach ($reg->scores as $score) {
                if ($score->judge && ! isset($judgesById[$score->judge_id])) {
                    $judgesById[$score->judge_id] = $score->judge;
                }
            }
        }
        $judges = collect($judgesById)->sortBy('name')->values();

        $rows = [];
        foreach ($registrations as $reg) {
            $cells = [];
            $values = [];
            foreach ($judges as $judge) {
                $score = $reg->scores->firstWhere('judge_id', $judge->id);
                $val = $score?->score;
                $cells[$judge->id] = $val !== null ? (float) $val : null;
                if ($val !== null) $values[] = (float) $val;
            }
            $avg = count($values) ? array_sum($values) / count($values) : null;

            $rows[] = [
                'reg'   => $reg,
                'cells' => $cells,
                'avg'   => $avg,
                'final' => $reg->final_score !== null ? (float) $reg->final_score : null,
            ];
        }

        return ['judges' => $judges, 'rows' => $rows];
    }

    /** Уровень отклонения: 'ok' | 'warn' | 'danger' */
    public static function deviationLevel(?float $value, ?float $avg): string
    {
        if ($value === null || $avg === null) return 'ok';
        $diff = abs($value - $avg);
        if ($diff >= self::DANGER_THRESHOLD) return 'danger';
        if ($diff >= self::WARN_THRESHOLD)   return 'warn';
        return 'ok';
    }
}