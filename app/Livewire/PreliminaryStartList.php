<?php

namespace App\Livewire;

use App\Models\Competition;
use App\Support\ProtocolGroups;
use Illuminate\Support\Carbon;
use Livewire\Component;

/**
 * Решение заказчика (05.10): HTML-страница «Предварительный стартовый протокол»
 * (по поданным заявкам на соревнование) в стиле публичной страницы результатов.
 * Только авторизованным (тренер и администратор); формат — HTML с кнопкой
 * «Печать», а не PDF (конкурентная альтернатива competition.start-list).
 */
class PreliminaryStartList extends Component
{
    public Competition $competition;

    public function mount(Competition $competition): void
    {
        // Решение заказчика (05.10): страницу видят только тренер и администратор.
        $user = auth()->user();
        abort_unless($user !== null && ($user->isAdmin() || $user->isCoach()), 403);

        $this->competition = $competition;
    }

    private function formatAgeGroup($ageGroup): string
    {
        if (! $ageGroup) {
            return '';
        }

        if ($ageGroup->min_age && $ageGroup->max_age) {
            return "({$ageGroup->min_age}-{$ageGroup->max_age} лет)";
        }

        if ($ageGroup->min_age) {
            return "({$ageGroup->min_age}+ лет)";
        }

        return '';
    }

    public function render()
    {
        // Те же данные, что у PDF competition.start-list: заявки в исходном
        // порядке (по полю sort_order заявки) с группировкой по номинациям.
        $registrations = $this->competition->registrations()
            ->with(['athlete', 'athlete.club', 'style', 'ageGroup', 'partner'])
            ->join('styles', 'registrations.style_id', '=', 'styles.id')
            ->join('age_groups', 'registrations.age_group_id', '=', 'age_groups.id')
            ->join('athletes', 'registrations.athlete_id', '=', 'athletes.id')
            ->orderBy('styles.sort_order')
            ->orderBy('age_groups.sort_order')
            ->orderBy('athletes.gender')
            ->orderBy('registrations.sort_order')
            ->select('registrations.*')
            ->get();

        // Группа «O» (R-6.15): подгруппа «(O)» идёт сразу после основной той же
        // номинации; порядок номинаций не меняется.
        $grouped = ProtocolGroups::sortSubgroups($registrations)
            ->groupBy(function ($reg) {
                $ageStr = $this->formatAgeGroup($reg->ageGroup);
                $styleName = $reg->style?->name ?? 'Стиль';
                $groupName = $reg->ageGroup?->name ?? 'Группа';

                return ProtocolGroups::title($styleName, $groupName, $ageStr, (bool) $reg->is_special);
            });

        // Сводка: количество заявок по каждой возрастной группе и полу.
        $counts = $registrations
            ->groupBy(fn ($reg) => ($reg->ageGroup?->name ?? 'Без возрастной группы').'|'.($reg->athlete?->gender ?? ''))
            ->map(function ($regs, string $key) {
                [$ageGroup, $gender] = explode('|', $key, 2);

                return [
                    'age_group' => $ageGroup,
                    'gender' => $gender,
                    'count' => $regs->count(),
                ];
            })
            ->sortBy([['age_group', 'asc'], ['gender', 'asc']])
            ->values();

        return view('livewire.preliminary-start-list', [
            'grouped' => $grouped,
            'counts' => $counts,
            'generatedAt' => Carbon::now()->translatedFormat('d.m.Y H:i'),
        ])->layout('components.layouts.public', ['title' => 'Предварительный стартовый протокол']);
    }
}
