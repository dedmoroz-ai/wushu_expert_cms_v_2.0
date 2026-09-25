<?php

namespace App\Filament\Pages;

use App\Models\Competition;
use App\Models\Score;
use App\Models\User;
use App\Models\Registration;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class SuperJudgePad extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-scale';
    protected static ?string $navigationLabel = 'Пульт Старшего судьи';
    protected static ?string $title = 'Старший судья';

    public static function shouldRegisterNavigation(): bool
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        return $user && ($user->isAdmin() || $user->isHeadJudge());
    }

    protected static string $view = 'filament.pages.super-judge-pad';

    public function getHeading(): string { return ''; }

    // --- ПЕРЕМЕННЫЕ ДАННЫХ ---
    public $athleteName = '';
    public $athleteStyle = '';
    public $athleteGroup = '';
    
    public $registrationId = null;
    public $statusMessage = '';
    
    // --- СУДЕЙСТВО ---
    public $judgesScores = []; 
    public $myScore = '';      
    public $myScoreSaved = false; 

    // --- ИТОГИ ---
    public $calculatedAvg = null; 
    public $finalScoreInput = ''; 
    public $canFinalize = false;  

    public function mount()
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (!$user || !($user->isAdmin() || $user->isHeadJudge())) {
            abort(403, 'Доступ запрещен');
        }

        $this->loadState();
    }

    // --- ГЛАВНЫЙ ЦИКЛ ОБНОВЛЕНИЯ (POLLING) ---
    public function loadState()
    {
        $competition = Competition::where('status_code', 1)->first();

        if (!$competition || !$competition->currentRegistration) {
            $this->resetData('Турнир не активен');
            return;
        }

        $currentReg = $competition->currentRegistration;

        // Если спортсмен сменился
        if ($this->registrationId !== $currentReg->id) {
            $this->resetData('Ожидание...');
            $this->registrationId = $currentReg->id;
            $this->myScore = '';
            $this->myScoreSaved = false;
            $this->finalScoreInput = '';
        }

        // 1. ЗАПОЛНЕНИЕ ДАННЫХ
        $mainName = $currentReg->athlete->surname . ' ' . $currentReg->athlete->name;

        if ($currentReg->partner) {
            $partnerName = $currentReg->partner->surname . ' ' . $currentReg->partner->name;
            $fullName = "<div style='font-size: 0.7em; line-height: 1.1;'>{$mainName}<br>{$partnerName}</div>";
        } else {
            $fullName = $mainName;
        }

        $this->athleteName = $fullName;
        
        $this->athleteStyle = $currentReg->style->name ?? '';
        
        $group = $currentReg->ageGroup;
        if ($group) {
            $groupStr = $group->name;
            if (!is_null($group->min_age) && !is_null($group->max_age)) {
                $groupStr .= " ({$group->min_age}-{$group->max_age} лет)";
            }
            $this->athleteGroup = $groupStr;
        } else {
            $this->athleteGroup = '';
        }

        // 2. ПОЛУЧЕНИЕ ОЦЕНОК
        $allScoresDb = Score::where('registration_id', $currentReg->id)->with('judge')->get();

        $allJudgesUsers = User::where('id', '!=', Auth::id())
            ->where('role', 'judge')          
            ->where('is_active_judge', true)  
            ->get(); 
        
        $this->judgesScores = [];
        $scoresForCalc = []; 

        foreach ($allJudgesUsers as $judgeUser) {
            $s = $allScoresDb->where('judge_id', $judgeUser->id)->first();
            $val = $s ? $s->score : null;
            
            $this->judgesScores[] = [
                'name' => $judgeUser->name, 
                'score' => $val
            ];

            if (!is_null($val)) {
                $scoresForCalc[] = $val;
            }
        }

        // 3. ПРОВЕРКА СВОЕЙ ОЦЕНКИ
        $myDbScore = $allScoresDb->where('judge_id', Auth::id())->first();
        if ($myDbScore) {
            $this->myScoreSaved = true;
            $this->myScore = (string)$myDbScore->score;
            $scoresForCalc[] = $myDbScore->score; 
        } else {
            $this->myScoreSaved = false;
        }

        // 4. МАТЕМАТИКА
        $expectedCount = $allJudgesUsers->count() + 1;

        if ($expectedCount > 0 && count($scoresForCalc) >= $expectedCount) {
            sort($scoresForCalc);

            if (count($scoresForCalc) >= 3) {
                array_shift($scoresForCalc); 
                array_pop($scoresForCalc);   
            }

            if (count($scoresForCalc) > 0) {
                $sum = array_sum($scoresForCalc);
                $count = count($scoresForCalc);
                // ОКРУГЛЕНИЕ ДО 3 ЗНАКОВ (Как вы просили)
                $avg = round($sum / $count, 3); 

                $this->calculatedAvg = $avg;
                
                if ($this->finalScoreInput === '') {
                    $this->finalScoreInput = (string)$avg;
                }
                
                $this->canFinalize = true;
            } else {
                $this->calculatedAvg = isset($scoresForCalc[0]) ? $scoresForCalc[0] : 0;
            }
        } else {
            $this->calculatedAvg = null;
            $this->canFinalize = false;
        }
    }

    // --- КЛАВИАТУРА ---
    public function addNumber($num) {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        if ($user->isAdmin() && !$user->isHeadJudge()) return;

        // Режим 1: Моя оценка (КАК СУДЬИ)
        if (!$this->myScoreSaved) {
            // ИСПРАВИЛ ЗДЕСЬ: Было 4, стало 6.
            // Теперь можно ввести "8.125" (5 символов). Раньше на "8.12" блокировало.
            if (strlen($this->myScore) >= 6) return;
            
            if ($this->myScore === '') {
                if ($num === '.') return;
                // Разрешаем начинать с любой цифры (если вдруг оценка низкая)
                if (is_numeric($num) && $num < 0) return; 
            }

            if ($num === '.' && str_contains($this->myScore, '.')) return;
            $this->myScore .= $num;
            return;
        }

        // Режим 2: Финал (РЕДАКТИРОВАНИЕ ИТОГА)
        if ($this->canFinalize) {
            // ИСПРАВИЛ ЗДЕСЬ: Было 6, стало 8.
            // Чтобы можно было комфортно редактировать длинные дроби.
            if (strlen($this->finalScoreInput) >= 8) return;
            
            if ($num === '.' && str_contains($this->finalScoreInput, '.')) return;
            $this->finalScoreInput .= $num;
        }
    }
    
    public function backspace() {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        if ($user->isAdmin() && !$user->isHeadJudge()) return;

        if (!$this->myScoreSaved) {
            $this->myScore = substr($this->myScore, 0, -1);
            return;
        }
        if ($this->canFinalize) {
            $this->finalScoreInput = substr($this->finalScoreInput, 0, -1);
        }
    }

    public function submitMyScore() {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        
        if ($user->isAdmin() && !$user->isHeadJudge()) {
            Notification::make()->title('Режим просмотра: Админ не может ставить оценки')->warning()->send();
            return;
        }

        if ($this->myScoreSaved) return;
        // Разрешаем сохранять, даже если введено мало символов (например, ровно "9")
        if (strlen($this->myScore) < 1) return;
        
        Score::create([
            'registration_id' => $this->registrationId,
            'judge_id' => Auth::id(),
            // Сохраняем как float (благодаря Model и DB оно сохранится точно)
            'score' => floatval($this->myScore),
        ]);
        
        $this->loadState(); 
    }

    public function finalizeProtocol()
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if ($user->isAdmin() && !$user->isHeadJudge()) {
            Notification::make()->title('Режим просмотра: Админ не может утверждать протокол')->warning()->send();
            return;
        }

        if (!$this->canFinalize) return;

        $finalVal = floatval(str_replace(',', '.', $this->finalScoreInput));

        if ($finalVal <= 0) return;

        $competition = Competition::where('status_code', 1)->first();
        
        if ($competition && $competition->currentRegistration) {
            $reg = $competition->currentRegistration;
            
            // Сохраняем (БД и Модель уже настроены на double/decimal:4)
            $reg->final_score = $finalVal;
            $reg->is_completed = true;
            $reg->save();
            
            Notification::make()->title('Протокол сохранен!')->success()->send();

            $nextReg = Registration::where('competition_id', $competition->id)
                ->where('is_completed', false)
                ->where('id', '!=', $reg->id)
                ->orderBy('sort_order', 'asc')
                ->first();

            if ($nextReg) {
                $competition->current_registration_id = $nextReg->id;
                $competition->save();
                
                Notification::make()
                    ->title('Следующий: ' . $nextReg->athlete->name)
                    ->body('Автоматический переход')
                    ->info()
                    ->send();
                
                $this->loadState(); 
            } else {
                $competition->current_registration_id = null;
                $competition->save();
                
                $this->resetData('Соревнования завершены!');
                Notification::make()->title('Турнир завершен!')->warning()->send();
            }
        }
    }

    protected function resetData($msg) {
        $this->statusMessage = $msg;
        $this->athleteName = '';
        $this->athleteStyle = ''; 
        $this->athleteGroup = ''; 
        $this->judgesScores = [];
        $this->myScore = '';
        $this->myScoreSaved = false;
        $this->calculatedAvg = null;
        $this->finalScoreInput = '';
        $this->canFinalize = false;
    }

    public function logout()
    {
        filament()->auth()->logout();
        session()->invalidate();
        session()->regenerateToken();

        return redirect()->to(filament()->getLoginUrl());
    }
}
