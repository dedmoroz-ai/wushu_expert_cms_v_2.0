<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Competition;
use App\Models\Registration;
use Illuminate\Support\Facades\Schema;

class PublicResults extends Component
{
    public $competition;
    
    public function mount($token = null)
    {
        // Если передан токен
        if ($token) {
            // Проверяем, существует ли колонка public_token
            $hasPublicTokenColumn = Schema::hasColumn('competitions', 'public_token');
            
            if ($hasPublicTokenColumn) {
                // Ищем по токену
                $this->competition = Competition::where('public_token', $token)
                    ->with('federation')
                    ->first();
            } else {
                // Если колонки нет, проверяем, не это ли временный токен с ID
                if (str_starts_with($token, 'comp_')) {
                    $competitionId = (int) str_replace('comp_', '', $token);
                    $this->competition = Competition::where('id', $competitionId)
                        ->with('federation')
                        ->first();
                } else {
                    $this->competition = null;
                }
            }
        } else {
            // Иначе берем активное соревнование
            $this->competition = Competition::whereIn('status_code', [0, 1, 2])
                ->with('federation')
                ->orderBy('start_date', 'desc')
                ->first();
        }
    }
    
    private function formatAgeGroup($ageGroup)
    {
        if (!$ageGroup) return '';
        if ($ageGroup->min_age && $ageGroup->max_age) {
            return "({$ageGroup->min_age}-{$ageGroup->max_age} лет)";
        } elseif ($ageGroup->min_age) {
            return "({$ageGroup->min_age}+ лет)";
        }
        return '';
    }
    
    public function render()
    {
        $grouped = collect();
        $standings = collect();
        
        if ($this->competition) {
            $registrations = Registration::where('competition_id', $this->competition->id)
                ->with(['athlete', 'athlete.club', 'style', 'ageGroup', 'partner'])
                ->where('is_completed', true)
                ->whereNotNull('final_score')
                ->join('styles', 'registrations.style_id', '=', 'styles.id')
                ->join('age_groups', 'registrations.age_group_id', '=', 'age_groups.id')
                ->join('athletes', 'registrations.athlete_id', '=', 'athletes.id')
                ->orderBy('styles.sort_order')
                ->orderBy('age_groups.sort_order')
                ->orderBy('athletes.gender')
                ->orderByDesc('registrations.final_score')
                ->select('registrations.*')
                ->get()
                ->map(function ($reg) {
                    $reg->formatted_score = number_format((float)$reg->final_score, 3, '.', '');
                    return $reg;
                });
            
            // Группируем по категориям (как в протоколе)
            $grouped = $registrations->groupBy(function ($reg) {
                $ageStr = $this->formatAgeGroup($reg->ageGroup);
                $styleName = $reg->style?->name ?? 'Стиль';
                $groupName = $reg->ageGroup?->name ?? 'Группа';
                return sprintf("%s — %s %s", $styleName, $groupName, $ageStr);
            });

            // Командный (клубный) зачёт (R-6.14)
            $standings = \App\Support\TeamStandings::forCompetition($this->competition);
        }
        
        return view('livewire.public-results', [
            'grouped' => $grouped,
            'standings' => $standings,
        ])->layout('components.layouts.public');
    }
}
