<?php

namespace App\Filament\Pages;

use App\Models\Competition;
use App\Models\DeductionCode;
use App\Models\Score;
use App\Support\JudgingCalculator;
use App\Support\ScoreRange;
use App\Support\ScoreWriter;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class JudgePad extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-calculator';
    protected static ?string $navigationLabel = 'Пульт ввода оценок';
    protected static ?string $title = 'Пульт судьи';
    
    // 1. НАСТРОЙКА ВИДИМОСТИ В МЕНЮ
    public static function shouldRegisterNavigation(): bool
    {
        return Auth::check() && Auth::user()->role === 'judge';
    }

    protected static string $view = 'filament.pages.judge-pad';

    public function getHeading(): string { return ''; }

    // ПЕРЕМЕННЫЕ
    public $score = '';
    public $registrationId = null;
    
    public $athleteName = '';
    public $athleteStyle = '';
    public $athleteGroup = ''; 
    public $athleteNumber = '';
    
    public $statusMessage = '';
    public $canVote = false;

    // Правила 8.2, 8.3: диапазон допустимых оценок для текущего выступления.
    public $scoreMin = ScoreRange::GLOBAL_MIN;
    public $scoreMax = ScoreRange::GLOBAL_MAX;
    public $scoreRangeLabel = '';

    // Правило 8.7: возможность исправить свою оценку до утверждения протокола.
    public $savedScore = null;
    public $isEditing = false;
    public $canEditScore = false;

    // Правила R-2.11, R-4.18: сценарий турнира и функция судьи (A / B).
    public $scheme = Competition::SCHEME_SIMPLE;
    public $panel = null;

    // Правило R-3.12: 'keypad' — цифровая клавиатура, 'codes' — кнопки сбавок (судья A).
    public $inputMode = 'keypad';

    /** @var array<int, array{id: int, code: string, label: string, value: float, group: string|null}> */
    public $deductionCodes = [];

    /** @var array<int, int> id нажатых кодов в порядке нажатия (с повторами) */
    public $pressedCodes = [];

    /** @var array<int, array{code: string, label: string, value: float}> */
    public $savedDeductions = [];

    public function mount()
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        // Правило R-1.3: пульт линейного судьи — только для роли judge.
        if (!$user || $user->role !== 'judge') {
            abort(403, 'Доступ запрещен. Только для линейных судей.');
        }

        // Правило 8.5: судья должен быть допущен к судейству.
        if (!$user->is_active_judge) {
            abort(403, 'Доступ запрещен. Вы не допущены к судейству (обратитесь к администратору).');
        }

        $this->loadState();
    }

    public function loadState()
    {
        $competition = Competition::active();

        if (!$competition) {
            $this->resetPad('Турнир не запущен');
            return;
        }

        // Правило 8.5: судья должен входить в бригаду активного соревнования.
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (!$competition->hasActiveJudge($user)) {
            $this->resetPad('Вы не в бригаде этого турнира');
            return;
        }

        $currentReg = $competition->currentRegistration;

        if (!$currentReg) {
            $this->resetPad('Ожидание выхода...');
            return;
        }

        // Правила R-2.11, R-4.18: в сценарии A/B судья работает по своей функции.
        $this->scheme = $competition->judgingScheme();
        $this->panel = $competition->isAbScheme() ? $competition->panelOf($user) : null;

        if ($competition->isAbScheme() && !$this->panel) {
            $this->resetPad('Функция судьи не назначена');
            return;
        }

        $this->inputMode = $this->panel === Competition::PANEL_A ? 'codes' : 'keypad';

        if ($this->registrationId !== $currentReg->id) {
            $this->score = '';
            $this->registrationId = $currentReg->id;
            $this->isEditing = false;
            $this->pressedCodes = [];
        }

        if ($this->inputMode === 'codes') {
            $this->deductionCodes = DeductionCode::active()->ordered()->get()
                ->map(fn (DeductionCode $c) => [
                    'id' => $c->id,
                    'code' => (string) $c->code,
                    'label' => (string) $c->label,
                    'value' => (float) $c->value,
                    'group' => $c->group_label,
                ])
                ->values()
                ->all();
        } else {
            $this->deductionCodes = [];
        }

        // Правила 8.2, 8.3, R-4.19: диапазон с учётом категории и функции судьи.
        $range = ScoreRange::forJudge($currentReg, $this->panel);
        $this->scoreMin = $range->min;
        $this->scoreMax = $range->max;
        $this->scoreRangeLabel = $range->label();

        // --- ЛОГИКА ИМЕН (ОДИНАКОВЫЙ РАЗМЕР) ---
        $fullName = $currentReg->athlete->surname . ' ' . $currentReg->athlete->name;

        if ($currentReg->partner) {
            $partnerName = $currentReg->partner->surname . ' ' . $currentReg->partner->name;
            // Просто перенос строки, без уменьшения шрифта
            $fullName .= '<br>' . $partnerName;
        }

        $this->athleteName = $fullName;
        // ---------------------------------------

        $this->athleteStyle = $currentReg->style->name ?? '';
        $this->athleteNumber = $currentReg->sort_order;

        // ЛОГИКА ГРУППЫ
        $group = $currentReg->ageGroup;
        if ($group) {
            $groupStr = $group->name;
            $min = $group->min_age;
            $max = $group->max_age;

            if (!is_null($min) && !is_null($max)) {
                $groupStr .= " ({$min}-{$max} лет)";
            }
            $this->athleteGroup = $groupStr;
        } else {
            $this->athleteGroup = '';
        }

        $myScore = Score::where('registration_id', $currentReg->id)
            ->where('judge_id', Auth::id())
            ->first();

        // Правило 8.7: исправить оценку можно, пока протокол участника не утверждён.
        $this->canEditScore = $myScore && !$currentReg->is_completed;

        if ($myScore) {
            $this->savedScore = $range->format((float) $myScore->score);
            $this->savedDeductions = ScoreWriter::deductionsOf($myScore);

            if ($this->isEditing && $this->canEditScore) {
                $this->canVote = true;
                $this->statusMessage = "ИСПРАВЛЕНИЕ ОЦЕНКИ";
            } else {
                $this->isEditing = false;
                $this->canVote = false;
                $this->statusMessage = "Оценка принята";
            }
        } else {
            $this->savedScore = null;
            $this->savedDeductions = [];
            $this->isEditing = false;
            $this->canVote = true;
            $this->statusMessage = "ОЦЕНИВАНИЕ";
        }
    }

    /**
     * Правило 8.7: переход в режим исправления своей оценки.
     */
    public function startEditing()
    {
        $this->loadState();

        if (!$this->canEditScore) {
            Notification::make()
                ->title('Исправление недоступно')
                ->body('Протокол участника уже утверждён.')
                ->warning()
                ->send();

            return;
        }

        $this->isEditing = true;
        $this->canVote = true;
        $this->score = '';
        // Правило R-3.16: исправление судьи A — набор сбавок заново с 5.000.
        $this->pressedCodes = [];
        $this->statusMessage = "ИСПРАВЛЕНИЕ ОЦЕНКИ";
    }

    /**
     * Отмена режима исправления.
     */
    public function cancelEditing()
    {
        $this->isEditing = false;
        $this->score = '';
        $this->pressedCodes = [];
        $this->loadState();
    }

    // --- РЕЖИМ СБАВОК (СУДЬЯ A) ---

    /**
     * Правила R-3.12, R-3.14: нажатие кнопки кода сбавки.
     * Один код можно нажать не более JudgingCalculator::MAX_CODE_REPEATS раз.
     */
    public function pressCode($codeId)
    {
        if (!$this->canVote || $this->inputMode !== 'codes') return;

        $codeId = (int) $codeId;
        $code = collect($this->deductionCodes)->firstWhere('id', $codeId);

        if (!$code) return;

        $pressedCodeStrings = array_map(
            fn ($id) => (string) (collect($this->deductionCodes)->firstWhere('id', (int) $id)['code'] ?? ''),
            $this->pressedCodes
        );

        if (!JudgingCalculator::canPressCode($pressedCodeStrings, $code['code'])) {
            Notification::make()
                ->title('Код ' . $code['code'] . ' уже нажат ' . JudgingCalculator::MAX_CODE_REPEATS . ' раза')
                ->warning()
                ->duration(1500)
                ->send();

            return;
        }

        $this->pressedCodes[] = $codeId;
    }

    /**
     * Правило R-3.15: отмена последней нажатой сбавки.
     */
    public function undoLastCode()
    {
        if (!$this->canVote || $this->inputMode !== 'codes') return;

        array_pop($this->pressedCodes);
    }

    /**
     * Сколько раз нажат код (для подсветки/блокировки кнопок).
     *
     * @return array<int, int> id кода => число нажатий
     */
    public function getPressCountsProperty(): array
    {
        return array_count_values(array_map('intval', $this->pressedCodes));
    }

    /**
     * Правило R-3.12: текущая оценка судьи A = 5.000 − сумма сбавок.
     */
    public function getCurrentAScoreProperty(): string
    {
        $values = array_map(
            fn ($id) => (float) (collect($this->deductionCodes)->firstWhere('id', (int) $id)['value'] ?? 0),
            $this->pressedCodes
        );

        return number_format(JudgingCalculator::scoreFromDeductions($values), ScoreRange::PRECISION, '.', '');
    }

    // --- РЕЖИМ КЛАВИАТУРЫ ---

    public function addNumber($num)
    {
        if (!$this->canVote || $this->inputMode !== 'keypad') return;

        // Правило 8.1: до 3 знаков после точки (например, 10.000 или 8.125).
        if ($num === '.') {
            if ($this->score === '' || str_contains($this->score, '.')) return;
            $this->score .= $num;
            return;
        }

        if (!is_numeric($num)) return;

        $candidate = $this->score . $num;

        // Ограничение длины дробной части системной точностью.
        if (str_contains($candidate, '.')) {
            $decimals = substr($candidate, strpos($candidate, '.') + 1);
            if (strlen($decimals) > ScoreRange::PRECISION) return;
        } elseif (strlen($candidate) > $this->maxIntegerDigits()) {
            // Целая часть — максимум 2 цифры (для «10»); у судьи B (шкала 0–5) — 1 цифра.
            return;
        }

        // Правила 8.2, 8.3: проверяем, что ввод ещё может попасть в диапазон.
        if (!$this->isPrefixAcceptable($candidate)) {
            Notification::make()
                ->title('Допустимая оценка: ' . $this->scoreRangeLabel)
                ->warning()
                ->duration(1500)
                ->send();

            return;
        }

        $this->score = $candidate;
    }

    /**
     * Правила 8.2, 8.3: может ли частично введённое значение в итоге
     * оказаться в допустимом диапазоне.
     *
     * Пример для диапазона 5.000–10.000: «4» отклоняем сразу, «1» разрешаем
     * (это начало «10»), «9» разрешаем.
     */
    protected function isPrefixAcceptable(string $candidate): bool
    {
        return ScoreRange::isPrefixReachable(
            $candidate,
            new ScoreRange((float) $this->scoreMin, (float) $this->scoreMax)
        );
    }

    /**
     * Правило R-4.19: сколько цифр допускается в целой части.
     */
    protected function maxIntegerDigits(): int
    {
        return $this->panel === Competition::PANEL_B ? 1 : 2;
    }

    public function backspace()
    {
        if (!$this->canVote || $this->inputMode !== 'keypad') return;
        $this->score = substr($this->score, 0, -1);
    }

    public function clear() { /* ... */ }

    public function submitScore()
    {
        if (!$this->canVote) return;

        $competition = Competition::active();

        if (!$competition || !$competition->currentRegistration) {
            $this->loadState();
            return;
        }

        // Правило 8.5: повторная проверка допуска на момент сохранения.
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (!$user->is_active_judge || !$competition->hasActiveJudge($user)) {
            Notification::make()
                ->title('Вы не допущены к судейству этого турнира')
                ->danger()
                ->send();

            $this->loadState();
            return;
        }

        $currentReg = $competition->currentRegistration;

        // Участник мог смениться, пока судья набирал оценку.
        if ($currentReg->id !== $this->registrationId) {
            $this->loadState();
            return;
        }

        // Правила R-2.11, R-4.18: функция судьи перечитывается на момент записи.
        $panel = $competition->isAbScheme() ? $competition->panelOf($user) : null;

        if ($competition->isAbScheme() && !$panel) {
            Notification::make()->title('Функция судьи не назначена')->danger()->send();
            $this->loadState();
            return;
        }

        $range = ScoreRange::forJudge($currentReg, $panel);
        $deductions = [];

        if ($panel === Competition::PANEL_A) {
            // Правила R-3.12–R-3.14: оценка A считается на сервере из нажатых кодов.
            $deductions = ScoreWriter::snapshotDeductions($this->pressedCodes);

            $counts = array_count_values(array_column($deductions, 'code'));
            if ($counts !== [] && max($counts) > JudgingCalculator::MAX_CODE_REPEATS) {
                Notification::make()->title('Код сбавки нажат слишком много раз')->danger()->send();
                return;
            }

            $val = JudgingCalculator::scoreFromDeductions(array_column($deductions, 'value'));
        } else {
            // Правила 8.2, 8.3, R-4.19: финальная проверка диапазона перед записью.
            $val = ScoreRange::parse($this->score);
        }

        if ($val === null || !$range->contains($val)) {
            Notification::make()
                ->title('Недопустимая оценка')
                ->body('Разрешённый диапазон: ' . $range->label())
                ->danger()
                ->send();

            return;
        }

        $exists = Score::where('registration_id', $currentReg->id)
            ->where('judge_id', $user->id)
            ->exists();

        // Правило 8.7: исправление возможно только до утверждения протокола.
        if ($exists && $currentReg->is_completed) {
            Notification::make()
                ->title('Протокол уже утверждён')
                ->warning()
                ->send();

            $this->isEditing = false;
            $this->loadState();
            return;
        }

        $created = ScoreWriter::save(
            $competition,
            $currentReg,
            $user->id,
            $val,
            $panel,
            $deductions,
            'Исправление судьёй на пульте',
        );

        Notification::make()->title($created ? 'Принято!' : 'Оценка исправлена!')->success()->send();

        $this->isEditing = false;
        $this->score = '';
        $this->pressedCodes = [];
        $this->loadState();
    }

    protected function resetPad($message)
    {
        $this->canVote = false;
        $this->statusMessage = $message;
        $this->athleteName = '';
        $this->athleteStyle = '';
        $this->athleteGroup = '';
        $this->athleteNumber = '';
        $this->score = '';
        $this->registrationId = null;
        $this->savedScore = null;
        $this->isEditing = false;
        $this->canEditScore = false;
        $this->scoreRangeLabel = '';
        $this->pressedCodes = [];
        $this->savedDeductions = [];
        $this->deductionCodes = [];
    }

    public function logout()
    {
        filament()->auth()->logout();
        session()->invalidate();
        session()->regenerateToken();

        return redirect()->to(filament()->getLoginUrl());
    }
}
