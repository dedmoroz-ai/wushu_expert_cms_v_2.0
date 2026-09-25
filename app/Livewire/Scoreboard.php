<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\Score;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class Scoreboard extends Component
{
    public $lastRegistrationId = null; 
    public $freezeUntil = null;        

    public function mount()
    {
        // ИЗМЕНЕНИЕ: Добавили 0 в список статусов, чтобы шапка работала сразу
        $competition = Competition::whereIn('status_code', [0, 1, 2])->first();
        if ($competition && $competition->currentRegistration) {
            $this->lastRegistrationId = $competition->currentRegistration->id;
        }
    }

    public function tick()
    {
        // ИЗМЕНЕНИЕ: Тут тоже разрешаем 0, 1, 2
        $competition = Competition::whereIn('status_code', [0, 1, 2])->first();
        
        if (!$competition) {
            $this->lastRegistrationId = null;
            return;
        }

        // Если статус 0 (Ожидание) - просто обновляем интерфейс, но логику спортсменов не трогаем
        if ($competition->status_code == 0) {
             $this->lastRegistrationId = null;
             return;
        }

        if (!$competition->currentRegistration) {
            if (!$this->freezeUntil) {
                $this->lastRegistrationId = null;
            }
            return;
        }

        $dbReg = $competition->currentRegistration;

        if ($this->freezeUntil) {
            if (Carbon::now()->lessThan(Carbon::parse($this->freezeUntil))) {
                return; 
            }
            $this->freezeUntil = null;
            $this->lastRegistrationId = $dbReg->id;
            return;
        }

        if ($this->lastRegistrationId && $this->lastRegistrationId !== $dbReg->id) {
            $prevReg = Registration::find($this->lastRegistrationId);
            if ($prevReg && $prevReg->is_completed && $prevReg->final_score > 0) {
                $this->freezeUntil = Carbon::now()->addSeconds(15)->toDateTimeString();
            } else {
                $this->lastRegistrationId = $dbReg->id;
            }
        } elseif (!$this->lastRegistrationId) {
            $this->lastRegistrationId = $dbReg->id;
        }
    }

    public function render()
    {
        // ИЗМЕНЕНИЕ: И здесь разрешаем 0
        $competition = Competition::whereIn('status_code', [0, 1, 2])->first();
        
        $activeReg = null;
        $nextReg = null;
        $judgesScores = [];
        $showFinal = false;

        if ($this->lastRegistrationId) {
            $activeReg = Registration::find($this->lastRegistrationId);
        }

        if ($activeReg) {
            $scores = Score::where('registration_id', $activeReg->id)->get();
            foreach($scores as $s) {
                $judgesScores[] = $s->score;
            }

            if ($activeReg->is_completed && $activeReg->final_score > 0) {
                $showFinal = true;
            }

            if ($competition) {
                $nextReg = Registration::where('competition_id', $competition->id)
                    ->where('is_completed', false)
                    ->where('id', '!=', $activeReg->id)
                    ->orderBy('sort_order', 'asc')
                    ->first();
            }
        }

        // Генерируем URL для публичной страницы и QR-кода (если соревнование в паузе)
        $publicUrl = null;
        $qrCodeUrl = null;
        
        if ($competition && $competition->status_code == 2) {
            // Проверяем наличие колонки (для совместимости)
            try {
                $hasPublicTokenColumn = Schema::hasColumn('competitions', 'public_token');
            
            if ($hasPublicTokenColumn) {
                // Генерируем токен, если его нет
                if (!$competition->public_token) {
                    $competition->public_token = \Illuminate\Support\Str::random(32);
                    $competition->save();
                }
                $publicUrl = route('public.results', ['token' => $competition->public_token]);
            } else {
                    // Если колонки нет, используем ID соревнования
                    $publicUrl = route('public.results', ['token' => 'comp_' . $competition->id]);
                }
            } catch (\Exception $e) {
                // Fallback: используем ID соревнования
                $publicUrl = route('public.results', ['token' => 'comp_' . $competition->id]);
            }
            
            $qrCodeUrl = route('competition.qr-code', $competition);
        }

        return view('livewire.scoreboard', [
            'competition' => $competition,
            'current' => $activeReg,
            'next' => $nextReg,
            'scores' => $judgesScores,
            'showFinal' => $showFinal,
            'publicUrl' => $publicUrl,
            'qrCodeUrl' => $qrCodeUrl,
        ])->layout('components.layouts.base');
    }
}
