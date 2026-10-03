<?php

namespace App\Filament\Pages;

use App\Models\Competition;
use App\Models\DeductionCode;
use App\Models\JudgingLog;
use App\Models\Registration;
use App\Models\Score;
use App\Support\JudgingCalculator;
use App\Support\ScoreRange;
use App\Support\ScoreWriter;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
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

    /**
     * Замечание заказчика (01.10): в меню судьи (старшего) пункт называется
     * «Судейский пульт»; для админа остаётся прежнее название.
     */
    public static function getNavigationLabel(): string
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        return $user && $user->isJudge()
            ? 'Судейский пульт'
            : parent::getNavigationLabel();
    }

    /** Позиция в наборе пунктов меню судьи: Инфопанель(1), Пульт(2), … */
    public static function getNavigationSort(): ?int
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        return $user && $user->isJudge() ? 2 : parent::getNavigationSort();
    }

    protected static string $view = 'filament.pages.super-judge-pad';

    public function getHeading(): string
    {
        return '';
    }

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

    // Правила 8.2, 8.3: диапазон допустимых оценок текущего выступления.
    public $scoreMin = ScoreRange::GLOBAL_MIN;

    public $scoreMax = ScoreRange::GLOBAL_MAX;

    public $scoreRangeLabel = '';

    // Правило 8.4: сколько оценок ожидается от бригады турнира.
    public $expectedScoresCount = 0;

    public $receivedScoresCount = 0;

    // Правило 8.7: возможность исправить свою оценку / снять оценку судьи.
    public $isEditingMyScore = false;

    // Правило 8.9: обоснование ручной правки итогового балла.
    public $finalScoreReason = '';

    // Правила R-2.11, R-4.18–R-4.20: сценарий A/B.
    public $scheme = Competition::SCHEME_SIMPLE;

    public $myPanel = null;

    // Плашка «я» на экране: фамилия текущего судьи («Иванов (ст. судья)»).
    public $mySurname = '';

    public $iAmInBrigade = false;

    public $canScoreSelf = false;

    // Замечание заказчика (30.09): админ не судья — пульт ему доступен только
    // для просмотра (без плашки «Я» и без действий).
    public $isViewOnly = false;

    // Диапазон итогового балла (в simple совпадает с диапазоном оценки судьи).
    public $totalMin = ScoreRange::GLOBAL_MIN;

    public $totalMax = ScoreRange::GLOBAL_MAX;

    public $totalRangeLabel = '';

    // Правила R-4.11, R-4.20: максимум итога для возрастной категории (плашка «МАКСИМУМ»).
    public $totalMaxLabel = '';

    // Счётчики и средние по панелям.
    public $expectedA = 0;

    public $receivedA = 0;

    public $expectedB = 0;

    public $receivedB = 0;

    public $avgA = null;

    public $avgB = null;

    public $unassignedJudges = 0;

    public $formulaText = '(Сумма - Мин. - Макс.) = Средний';

    // Правило R-3.12: ввод сбавок, если старший судья — судья A.
    /** @var array<int, array{id: int, code: string, label: string, value: float, group: string|null}> */
    public $deductionCodes = [];

    /** @var array<int, int> */
    public $pressedCodes = [];

    public function mount()
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (! $user || ! ($user->isAdmin() || $user->isHeadJudge())) {
            abort(403, 'Доступ запрещен');
        }

        $this->loadState();
    }

    // --- ГЛАВНЫЙ ЦИКЛ ОБНОВЛЕНИЯ (POLLING) ---
    public function loadState()
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $this->isViewOnly = $user->isAdmin() && ! $user->isHeadJudge();

        $competition = Competition::active();

        if (! $competition || ! $competition->currentRegistration) {
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
            $this->isEditingMyScore = false;
            $this->finalScoreReason = '';
            $this->pressedCodes = [];
        }

        // Правила R-2.11, R-4.18: сценарий и функция старшего судьи.
        $this->scheme = $competition->judgingScheme();
        $isAb = $competition->isAbScheme();
        $this->myPanel = $isAb ? $competition->panelOf(Auth::user()) : null;
        $this->mySurname = $this->surnameOf((string) (Auth::user()->name ?? ''));

        // Правила 8.2, 8.3, R-4.19: диапазон своей оценки (по функции) и итога.
        $range = ScoreRange::forJudge($currentReg, $this->myPanel);
        $this->scoreMin = $range->min;
        $this->scoreMax = $range->max;
        $this->scoreRangeLabel = $range->label();

        $totalRange = ScoreRange::totalRange($currentReg);
        $this->totalMin = $totalRange->min;
        $this->totalMax = $totalRange->max;
        $this->totalRangeLabel = $totalRange->label();

        // Правила R-4.11, R-4.20: максимум итога для возрастной категории.
        $this->totalMaxLabel = $this->buildTotalMaxLabel($currentReg, $isAb);

        if ($this->myPanel === Competition::PANEL_A) {
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

        // 1. ЗАПОЛНЕНИЕ ДАННЫХ
        $mainName = $currentReg->athlete->surname.' '.$currentReg->athlete->name;

        if ($currentReg->partner) {
            $partnerName = $currentReg->partner->surname.' '.$currentReg->partner->name;
            $fullName = "<div style='font-size: 0.7em; line-height: 1.1;'>{$mainName}<br>{$partnerName}</div>";
        } else {
            $fullName = $mainName;
        }

        $this->athleteName = $fullName;

        $this->athleteStyle = $currentReg->style->name ?? '';

        $group = $currentReg->ageGroup;
        if ($group) {
            $groupStr = $group->name;
            if (! is_null($group->min_age) && ! is_null($group->max_age)) {
                $groupStr .= " ({$group->min_age}-{$group->max_age} лет)";
            }
            $this->athleteGroup = $groupStr;
        } else {
            $this->athleteGroup = '';
        }

        // 2. ПОЛУЧЕНИЕ ОЦЕНОК
        $allScoresDb = Score::where('registration_id', $currentReg->id)->with('judge')->get();

        // Правило 8.4: ожидаем оценки ТОЛЬКО от бригады, привязанной
        // к этому соревнованию (competition_user), а не от всех судей системы.
        $brigade = $competition->activeJudges()->orderBy('name')->get();

        // Старший судья (текущий пользователь) отображается отдельной карточкой.
        $lineJudges = $brigade->where('id', '!=', Auth::id());

        // Участвует ли сам старший судья в выставлении оценок:
        // да — если он входит в бригаду турнира. Админ в режиме просмотра — не судья,
        // его оценка не требуется (даже если он привязан к бригаде).
        $iAmInBrigade = ! $this->isViewOnly && $brigade->contains('id', Auth::id());
        $this->iAmInBrigade = $iAmInBrigade;

        $this->judgesScores = [];
        $scoresForCalc = [];

        // Правило R-4.18: в сценарии A/B оценки группируются по функции судьи.
        // Оценка засчитывается, только если она выставлена в текущей функции
        // (scores.panel совпадает с назначением в бригаде).
        $panelScores = [Competition::PANEL_A => [], Competition::PANEL_B => []];
        $panelExpected = [Competition::PANEL_A => 0, Competition::PANEL_B => 0];
        $this->unassignedJudges = 0;

        foreach ($lineJudges as $judgeUser) {
            $judgePanel = $isAb ? ($judgeUser->pivot->panel ?? null) : null;
            $judgePanel = in_array($judgePanel, [Competition::PANEL_A, Competition::PANEL_B], true) ? $judgePanel : null;

            $s = $allScoresDb->where('judge_id', $judgeUser->id)->first();

            if ($isAb && $s && $s->panel !== $judgePanel) {
                $s = null;
            }

            $val = $s ? (float) $s->score : null;

            $this->judgesScores[] = [
                'id' => $judgeUser->id,
                'name' => $judgeUser->name,
                'panel' => $judgePanel,
                'score' => is_null($val) ? null : $range->format($val),
            ];

            if ($isAb) {
                if (! $judgePanel) {
                    $this->unassignedJudges++;

                    continue;
                }

                $panelExpected[$judgePanel]++;

                if (! is_null($val)) {
                    $panelScores[$judgePanel][] = $val;
                }

                continue;
            }

            if (! is_null($val)) {
                $scoresForCalc[] = $val;
            }
        }

        // 3. ПРОВЕРКА СВОЕЙ ОЦЕНКИ
        $myDbScore = $allScoresDb->where('judge_id', Auth::id())->first();

        // В A/B своя оценка засчитывается только в текущей функции.
        if ($isAb && $myDbScore && $myDbScore->panel !== $this->myPanel) {
            $myDbScore = null;
        }

        // Старший судья в бригаде, но без функции в A/B — не оценивает.
        $iScore = $iAmInBrigade && (! $isAb || $this->myPanel !== null);

        if ($isAb && $iAmInBrigade && ! $this->myPanel) {
            $this->unassignedJudges++;
        }

        if ($myDbScore) {
            $this->myScoreSaved = ! $this->isEditingMyScore;

            if (! $this->isEditingMyScore) {
                $this->myScore = $range->format((float) $myDbScore->score);
            }

            if ($isAb) {
                $panelScores[$this->myPanel][] = (float) $myDbScore->score;
            } else {
                $scoresForCalc[] = (float) $myDbScore->score;
            }
        } else {
            // В A/B, если старший судья не оценивает (нет функции / не в бригаде),
            // пульт сразу переходит к контролю протокола. В simple — как раньше.
            $this->myScoreSaved = $isAb ? ! $iScore : false;
            $this->isEditingMyScore = false;
        }

        $this->canScoreSelf = $iScore;

        // Правило R-3.12: судья A стартует с 5.000 и видит результат сбавок.
        if ($this->myPanel === Competition::PANEL_A && ! $this->myScoreSaved) {
            $this->myScore = $this->currentAScore();
        }

        if ($isAb && $iScore) {
            $panelExpected[$this->myPanel]++;
        }

        // 4. МАТЕМАТИКА
        if ($isAb) {
            $this->calculateAb($panelScores, $panelExpected, $range);

            return;
        }

        // Правило 8.4: ожидаемое число оценок = размер бригады турнира.
        // Если старший судья не входит в бригаду (например, админ в режиме
        // просмотра), его оценка не требуется.
        $expectedCount = $lineJudges->count() + ($iAmInBrigade ? 1 : 0);

        $this->expectedScoresCount = $expectedCount;
        $this->receivedScoresCount = count($scoresForCalc);
        $this->formulaText = '(Сумма - Мин. - Макс.) = Средний';

        if ($expectedCount > 0 && count($scoresForCalc) >= $expectedCount) {
            // Правила R-4.6, R-4.7: одна мин. и одна макс. отбрасываются при 3+ оценках.
            $avg = JudgingCalculator::trimmedMean($scoresForCalc)['avg'];

            if ($avg !== null) {
                $this->calculatedAvg = $range->format($avg);

                if ($this->finalScoreInput === '') {
                    $this->finalScoreInput = $range->format($avg);
                }

                $this->canFinalize = true;
            } else {
                $this->calculatedAvg = null;
            }
        } else {
            $this->calculatedAvg = null;
            $this->canFinalize = false;
        }
    }

    /**
     * Правила R-4.18–R-4.20: расчёт итога в сценарии A/B.
     * Итог = среднее панели A + среднее панели B (каждое — с отбрасыванием
     * крайних при 3+ оценках). Расчёт стартует, только когда обе панели собраны.
     */
    protected function calculateAb(array $panelScores, array $panelExpected, ScoreRange $range): void
    {
        $a = Competition::PANEL_A;
        $b = Competition::PANEL_B;

        $this->expectedA = $panelExpected[$a];
        $this->expectedB = $panelExpected[$b];
        $this->receivedA = count($panelScores[$a]);
        $this->receivedB = count($panelScores[$b]);

        $this->expectedScoresCount = $this->expectedA + $this->expectedB;
        $this->receivedScoresCount = $this->receivedA + $this->receivedB;

        $completeA = $this->expectedA > 0 && $this->receivedA >= $this->expectedA;
        $completeB = $this->expectedB > 0 && $this->receivedB >= $this->expectedB;

        $resA = JudgingCalculator::trimmedMean($panelScores[$a]);
        $resB = JudgingCalculator::trimmedMean($panelScores[$b]);

        $this->avgA = $completeA && $resA['avg'] !== null ? $range->format($resA['avg']) : null;
        $this->avgB = $completeB && $resB['avg'] !== null ? $range->format($resB['avg']) : null;

        $this->formulaText = 'A: '.($this->avgA ?? '…').' + B: '.($this->avgB ?? '…')
            .' (в каждой панели при 3+ оценках без мин. и макс.)';

        if ($this->avgA === null || $this->avgB === null) {
            $this->calculatedAvg = null;
            $this->canFinalize = false;

            return;
        }

        $total = JudgingCalculator::abTotal((float) $this->avgA, (float) $this->avgB);

        $this->formulaText = 'A '.$this->avgA.' + B '.$this->avgB.' = '.$range->format($total);
        $this->calculatedAvg = $range->format($total);

        if ($this->finalScoreInput === '') {
            $this->finalScoreInput = $range->format($total);
        }

        $this->canFinalize = true;
    }

    // --- КЛАВИАТУРА ---
    public function addNumber($num)
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        if ($user->isAdmin() && ! $user->isHeadJudge()) {
            return;
        }

        // Режим 1: Моя оценка (КАК СУДЬИ)
        if (! $this->myScoreSaved) {
            // Правило R-3.12: судья A ставит оценку кнопками сбавок, не цифрами.
            if ($this->myPanel === Competition::PANEL_A) {
                return;
            }

            $candidate = $this->appendDigit(
                $this->myScore,
                $num,
                $this->myPanel === Competition::PANEL_B ? 1 : 2
            );

            if ($candidate === null) {
                return;
            }

            // Правила 8.2, 8.3: не даём набрать значение вне диапазона.
            if (! $this->isPrefixAcceptable($candidate)) {
                Notification::make()
                    ->title('Допустимая оценка: '.$this->scoreRangeLabel)
                    ->warning()
                    ->duration(1500)
                    ->send();

                return;
            }

            $this->myScore = $candidate;

            return;
        }

        // Режим 2: Финал (РЕДАКТИРОВАНИЕ ИТОГА)
        if ($this->canFinalize) {
            $candidate = $this->appendDigit($this->finalScoreInput, $num);

            if ($candidate === null) {
                return;
            }

            // Правило 8.2 / R-4.11, R-4.20: итоговый балл проверяется по диапазону итога.
            if (! ScoreRange::isPrefixReachable(
                $candidate,
                new ScoreRange((float) $this->totalMin, (float) $this->totalMax)
            )) {
                Notification::make()
                    ->title('Допустимый итоговый балл: '.$this->totalRangeLabel)
                    ->warning()
                    ->duration(1500)
                    ->send();

                return;
            }

            $this->finalScoreInput = $candidate;
        }
    }

    /**
     * Правило 8.1: добавление символа с контролем точности (3 знака после точки,
     * не более 2 цифр в целой части).
     *
     * Возвращает null, если символ добавлять нельзя.
     */
    protected function appendDigit(string $current, $num, int $maxIntegerDigits = 2): ?string
    {
        if ($num === '.') {
            if ($current === '' || str_contains($current, '.')) {
                return null;
            }

            return $current.'.';
        }

        if (! is_numeric($num)) {
            return null;
        }

        $candidate = $current.$num;

        if (str_contains($candidate, '.')) {
            $decimals = substr($candidate, strpos($candidate, '.') + 1);
            if (strlen($decimals) > ScoreRange::PRECISION) {
                return null;
            }
        } elseif (strlen($candidate) > $maxIntegerDigits) {
            return null;
        }

        return $candidate;
    }

    // --- СБАВКИ (старший судья в функции A) ---

    /**
     * Правила R-3.12, R-3.14: нажатие кода сбавки старшим судьёй-A.
     */
    public function pressCode($codeId)
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        if ($user->isAdmin() && ! $user->isHeadJudge()) {
            return;
        }

        if ($this->myScoreSaved || $this->myPanel !== Competition::PANEL_A) {
            return;
        }

        $codeId = (int) $codeId;
        $codes = collect($this->deductionCodes);
        $code = $codes->firstWhere('id', $codeId);

        if (! $code) {
            return;
        }

        $pressed = array_map(fn ($id) => (string) ($codes->firstWhere('id', (int) $id)['code'] ?? ''), $this->pressedCodes);

        if (! JudgingCalculator::canPressCode($pressed, $code['code'])) {
            Notification::make()
                ->title('Код '.$code['code'].' уже нажат '.JudgingCalculator::MAX_CODE_REPEATS.' раза')
                ->warning()
                ->duration(1500)
                ->send();

            return;
        }

        $this->pressedCodes[] = $codeId;
        $this->myScore = $this->currentAScore();
    }

    /**
     * Правило R-3.15: отмена последней сбавки.
     */
    public function undoLastCode()
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        if ($user->isAdmin() && ! $user->isHeadJudge()) {
            return;
        }

        if ($this->myScoreSaved || $this->myPanel !== Competition::PANEL_A) {
            return;
        }

        array_pop($this->pressedCodes);
        $this->myScore = $this->currentAScore();
    }

    protected function currentAScore(): string
    {
        $codes = collect($this->deductionCodes);
        $values = array_map(fn ($id) => (float) ($codes->firstWhere('id', (int) $id)['value'] ?? 0), $this->pressedCodes);

        return number_format(JudgingCalculator::scoreFromDeductions($values), ScoreRange::PRECISION, '.', '');
    }

    /**
     * @return array<int, int> id кода => число нажатий
     */
    public function getPressCountsProperty(): array
    {
        return array_count_values(array_map('intval', $this->pressedCodes));
    }

    /**
     * Правила 8.2, 8.3: может ли частично введённое значение в итоге
     * оказаться в допустимом диапазоне.
     */
    protected function isPrefixAcceptable(string $candidate): bool
    {
        return ScoreRange::isPrefixReachable(
            $candidate,
            new ScoreRange((float) $this->scoreMin, (float) $this->scoreMax)
        );
    }

    public function backspace()
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        if ($user->isAdmin() && ! $user->isHeadJudge()) {
            return;
        }

        if (! $this->myScoreSaved) {
            if ($this->myPanel === Competition::PANEL_A) {
                $this->undoLastCode();

                return;
            }

            $this->myScore = substr($this->myScore, 0, -1);

            return;
        }
        if ($this->canFinalize) {
            $this->finalScoreInput = substr($this->finalScoreInput, 0, -1);
        }
    }

    public function submitMyScore()
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if ($user->isAdmin() && ! $user->isHeadJudge()) {
            Notification::make()->title('Режим просмотра: Админ не может ставить оценки')->warning()->send();

            return;
        }

        if ($this->myScoreSaved) {
            return;
        }

        $competition = Competition::active();

        if (! $competition || ! $competition->currentRegistration) {
            $this->loadState();

            return;
        }

        $currentReg = $competition->currentRegistration;

        if ($currentReg->id !== $this->registrationId) {
            $this->loadState();

            return;
        }

        // Правило 8.4: старший судья ставит оценку только если он в бригаде турнира.
        if (! $competition->hasActiveJudge($user)) {
            Notification::make()
                ->title('Вы не в бригаде этого турнира')
                ->body('Оценку выставить нельзя, доступен только контроль протокола.')
                ->warning()
                ->send();

            return;
        }

        // Правила R-4.18, R-9.3: в A/B старший судья оценивает по назначенной функции.
        $panel = $competition->isAbScheme() ? $competition->panelOf($user) : null;

        if ($competition->isAbScheme() && ! $panel) {
            Notification::make()
                ->title('Функция судьи не назначена')
                ->body('Назначьте себе A или B в бригаде турнира, либо работайте только с протоколом.')
                ->warning()
                ->send();

            return;
        }

        // Правила 8.2, 8.3, R-4.19: единая проверка диапазона (как и у линейного судьи).
        $range = ScoreRange::forJudge($currentReg, $panel);
        $deductions = [];

        if ($panel === Competition::PANEL_A) {
            $deductions = ScoreWriter::snapshotDeductions($this->pressedCodes);
            $counts = array_count_values(array_column($deductions, 'code'));

            if ($counts !== [] && max($counts) > JudgingCalculator::MAX_CODE_REPEATS) {
                Notification::make()->title('Код сбавки нажат слишком много раз')->danger()->send();

                return;
            }

            $val = JudgingCalculator::scoreFromDeductions(array_column($deductions, 'value'));
        } else {
            $val = ScoreRange::parse($this->myScore);
        }

        if ($val === null || ! $range->contains($val)) {
            Notification::make()
                ->title('Недопустимая оценка')
                ->body('Разрешённый диапазон: '.$range->label())
                ->danger()
                ->send();

            return;
        }

        $exists = Score::where('registration_id', $currentReg->id)
            ->where('judge_id', $user->id)
            ->exists();

        // Правило 8.7: исправление своей оценки — только до утверждения протокола.
        if ($exists && $currentReg->is_completed) {
            Notification::make()->title('Протокол уже утверждён')->warning()->send();
            $this->isEditingMyScore = false;
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
            'Исправление старшим судьёй (своя оценка)',
        );

        if (! $created) {
            Notification::make()->title('Оценка исправлена')->success()->send();
        }

        $this->isEditingMyScore = false;
        $this->pressedCodes = [];
        $this->loadState();
    }

    /**
     * Правило 8.7: старший судья переходит в режим исправления своей оценки.
     */
    public function editMyScore()
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if ($user->isAdmin() && ! $user->isHeadJudge()) {
            return;
        }

        $competition = Competition::active();

        if (! $competition || ! $competition->currentRegistration) {
            return;
        }

        if ($competition->currentRegistration->is_completed) {
            Notification::make()->title('Протокол уже утверждён')->warning()->send();

            return;
        }

        $this->isEditingMyScore = true;
        $this->myScoreSaved = false;
        $this->pressedCodes = [];
        // Правило R-3.16: судья A начинает исправление заново с 5.000.
        $this->myScore = $this->myPanel === Competition::PANEL_A ? $this->currentAScore() : '';
    }

    /**
     * Правило 8.7: старший судья снимает оценку линейного судьи,
     * чтобы тот выставил её заново (техническая ошибка, ошибочное нажатие).
     */
    public function resetJudgeScore($judgeId)
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if ($user->isAdmin() && ! $user->isHeadJudge()) {
            Notification::make()->title('Режим просмотра: недостаточно прав')->warning()->send();

            return;
        }

        $competition = Competition::active();

        if (! $competition || ! $competition->currentRegistration) {
            return;
        }

        $currentReg = $competition->currentRegistration;

        if ($currentReg->is_completed) {
            Notification::make()->title('Протокол уже утверждён')->warning()->send();

            return;
        }

        $score = Score::where('registration_id', $currentReg->id)
            ->where('judge_id', $judgeId)
            ->first();

        if (! $score) {
            Notification::make()->title('Оценка ещё не выставлена')->warning()->send();

            return;
        }

        $oldValue = (float) $score->score;
        $oldPanel = $score->panel;
        $oldDeductions = ScoreWriter::deductionsOf($score);

        // Сбавки удаляются каскадно (score_deductions.score_id → cascade).
        $score->delete();

        // Правила 8.9, R-6.13: аудит снятия оценки с полным составом.
        JudgingLog::record(JudgingLog::ACTION_SCORE_DELETED, [
            'competition_id' => $competition->id,
            'registration_id' => $currentReg->id,
            'judge_id' => $judgeId,
            'old_value' => $oldValue,
            'reason' => 'Снято старшим судьёй для перевыставления'
                .($oldPanel ? ' (панель '.$oldPanel.')' : ''),
            'details' => [
                'scheme' => $competition->judgingScheme(),
                'panel' => $oldPanel,
                'old_deductions' => $oldDeductions,
            ],
        ]);

        // Сбрасываем предложенный итог, чтобы он пересчитался заново.
        $this->finalScoreInput = '';

        Notification::make()
            ->title('Оценка снята')
            ->body('Судья должен выставить её заново.')
            ->success()
            ->send();

        $this->loadState();
    }

    public function finalizeProtocol()
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if ($user->isAdmin() && ! $user->isHeadJudge()) {
            Notification::make()->title('Режим просмотра: Админ не может утверждать протокол')->warning()->send();

            return;
        }

        if (! $this->canFinalize) {
            return;
        }

        $competition = Competition::active();

        if (! $competition || ! $competition->currentRegistration) {
            $this->loadState();

            return;
        }

        $reg = $competition->currentRegistration;

        // Правила 8.2, 8.3 / R-4.11, R-4.20: итоговый балл обязан быть в диапазоне итога.
        $range = ScoreRange::totalRange($reg);
        $finalVal = ScoreRange::parse($this->finalScoreInput);

        if ($finalVal === null || ! $range->contains($finalVal)) {
            Notification::make()
                ->title('Недопустимый итоговый балл')
                ->body('Разрешённый диапазон: '.$range->label())
                ->danger()
                ->send();

            return;
        }

        $finalVal = $range->round($finalVal);

        $autoValue = $this->calculatedAvg === null ? null : (float) $this->calculatedAvg;
        $oldFinal = is_null($reg->final_score) ? null : (float) $reg->final_score;

        // Сохраняем (БД и Модель уже настроены на double/decimal:3)
        $reg->final_score = $finalVal;
        $reg->is_completed = true;

        // Правило R-4.20: в A/B сохраняем составляющие итога.
        if ($competition->isAbScheme()) {
            $reg->score_a = $this->avgA === null ? null : (float) $this->avgA;
            $reg->score_b = $this->avgB === null ? null : (float) $this->avgB;
        }

        $reg->save();

        // Правила 8.9, R-6.13: аудит утверждения протокола с составом расчёта.
        JudgingLog::record(JudgingLog::ACTION_PROTOCOL_FINALIZED, [
            'competition_id' => $competition->id,
            'registration_id' => $reg->id,
            'old_value' => $oldFinal,
            'new_value' => $finalVal,
            'reason' => $this->finalScoreReason !== '' ? $this->finalScoreReason : null,
            'details' => $this->finalizationDetails($reg, $competition, $autoValue),
        ]);

        // Правило 8.9: отдельно фиксируем ручное отклонение от авто-расчёта.
        if (! is_null($autoValue) && abs($autoValue - $finalVal) > 0.0005) {
            JudgingLog::record(JudgingLog::ACTION_FINAL_SCORE_CHANGED, [
                'competition_id' => $competition->id,
                'registration_id' => $reg->id,
                'old_value' => $autoValue,
                'new_value' => $finalVal,
                'reason' => $this->finalScoreReason !== ''
                    ? $this->finalScoreReason
                    : 'Ручная корректировка итогового балла на пульте',
            ]);
        }

        $this->finalScoreReason = '';

        Notification::make()->title('Протокол сохранен!')->success()->send();

        // Правило 8.8: автопереход ТОЛЬКО ВПЕРЁД — ищем следующего участника
        // строго с большим sort_order, чтобы система не возвращалась назад.
        $nextReg = Registration::where('competition_id', $competition->id)
            ->where('is_completed', false)
            ->where('id', '!=', $reg->id)
            ->where('sort_order', '>', $reg->sort_order ?? 0)
            ->orderBy('sort_order', 'asc')
            ->first();

        if ($nextReg) {
            $competition->current_registration_id = $nextReg->id;
            $competition->save();

            Notification::make()
                ->title('Следующий: '.$nextReg->athlete->name)
                ->body('Автоматический переход')
                ->info()
                ->send();

            $this->loadState();

            return;
        }

        // Вперёд никого нет. Проверяем, остались ли пропущенные участники позади:
        // на них НЕ переходим автоматически (правило 8.8), только предупреждаем.
        $skippedCount = Registration::where('competition_id', $competition->id)
            ->where('is_completed', false)
            ->where('id', '!=', $reg->id)
            ->count();

        $competition->current_registration_id = null;
        $competition->save();

        if ($skippedCount > 0) {
            $this->resetData('Список пройден. Не оценено: '.$skippedCount);

            Notification::make()
                ->title('Список пройден до конца')
                ->body("Остались неоценённые участники: {$skippedCount}. Выберите их вручную в пульте управления.")
                ->warning()
                ->persistent()
                ->send();

            return;
        }

        $this->resetData('Соревнования завершены!');
        Notification::make()->title('Турнир завершен!')->warning()->send();
    }

    /**
     * Правило R-6.13: полный состав итога для журнала — все оценки с панелями
     * и сбавками, средние панелей, авто-расчёт.
     */
    protected function finalizationDetails(Registration $reg, Competition $competition, ?float $autoValue): array
    {
        $scores = Score::where('registration_id', $reg->id)
            ->with(['judge', 'deductions'])
            ->get()
            ->map(fn (Score $s) => [
                'judge_id' => $s->judge_id,
                'judge' => $s->judge?->name,
                'panel' => $s->panel,
                'score' => (float) $s->score,
                'deductions' => $s->deductions->map(fn ($d) => [
                    'code' => (string) $d->code,
                    'value' => (float) $d->value,
                ])->values()->all(),
            ])
            ->values()
            ->all();

        return [
            'scheme' => $competition->judgingScheme(),
            'scores' => $scores,
            'avg_a' => $this->avgA === null ? null : (float) $this->avgA,
            'avg_b' => $this->avgB === null ? null : (float) $this->avgB,
            'auto' => $autoValue,
            'formula' => $this->formulaText,
        ];
    }

    protected function resetData($msg)
    {
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
        $this->scoreRangeLabel = '';
        $this->expectedScoresCount = 0;
        $this->receivedScoresCount = 0;
        $this->isEditingMyScore = false;
        $this->finalScoreReason = '';
        $this->pressedCodes = [];
        $this->deductionCodes = [];
        $this->expectedA = 0;
        $this->receivedA = 0;
        $this->expectedB = 0;
        $this->receivedB = 0;
        $this->avgA = null;
        $this->avgB = null;
        $this->unassignedJudges = 0;
        $this->totalRangeLabel = '';
        $this->totalMaxLabel = '';
    }

    /**
     * Плашка «я»: фамилия судьи (первое слово ФИО).
     */
    protected function surnameOf(string $fullName): string
    {
        $fullName = trim($fullName);

        if ($fullName === '') {
            return 'Ст. судья';
        }

        $spacePos = strpos($fullName, ' ');

        return $spacePos === false ? $fullName : substr($fullName, 0, $spacePos);
    }

    /**
     * Правила R-4.11, R-4.20: максимум итогового балла для возрастной категории.
     * A/B: максимум панели A + максимум панели B, например «8.5 (5.0+3.5)».
     */
    protected function buildTotalMaxLabel(Registration $currentReg, bool $isAb): string
    {
        if (! $isAb) {
            return $this->formatShort(ScoreRange::totalRange($currentReg)->max);
        }

        $maxA = (float) ScoreRange::PANEL_MAX;
        $maxB = ScoreRange::forPanel($currentReg->ageGroup, Competition::PANEL_B)->max;

        return sprintf(
            '%s (%s+%s)',
            $this->formatShort($maxA + $maxB),
            $this->formatShort($maxA),
            $this->formatShort($maxB)
        );
    }

    /**
     * Короткий формат оценки: «5.000» → «5.0», «8.500» → «8.5», «3.250» → «3.25».
     */
    protected function formatShort(float $value): string
    {
        $formatted = rtrim(number_format($value, ScoreRange::PRECISION, '.', ''), '0');

        return str_ends_with($formatted, '.') ? $formatted.'0' : $formatted;
    }

    public function logout()
    {
        filament()->auth()->logout();
        session()->invalidate();
        session()->regenerateToken();

        return redirect()->to(filament()->getLoginUrl());
    }
}
