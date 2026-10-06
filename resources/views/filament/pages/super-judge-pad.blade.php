<x-filament-panels::page>
    <style>
        /* --- 1. ГЛОБАЛЬНЫЙ СБРОС --- */
        .fi-sidebar, .fi-topbar, footer, .fi-header { display: none !important; }
        .fi-main { margin: 0 !important; padding: 0 !important; max-width: 100% !important; }
        .fi-body { padding: 0 !important; height: 100vh; overflow: hidden; }
        
        body { 
            background-color: #0b1121 !important; 
            color: white !important; 
            font-family: 'Roboto', sans-serif;
            overflow: hidden; 
        }

        /* --- 2. ШАПКА --- */
        .header-fixed {
            height: 140px;
            width: 100%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 40px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            position: fixed;
            top: 0;
            left: 0;
            z-index: 100;
            background-color: #0b1121;
        }
        .header-logo { height: 100px; width: auto; }
        .header-status { text-align: right; line-height: 1.2; }

        /* --- 3. КОНТЕЙНЕР --- */
        .main-content {
            margin-top: 160px;
            height: calc(100vh - 160px);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            padding-top: 25px;
            overflow-y: auto;
        }

        /* --- 4. ТИПОГРАФИКА --- */
        
        /* ИМЯ */
        .athlete-name-big {
            font-size: 2.5rem; 
            font-weight: 900;
            color: #0992B8;
            text-transform: uppercase;
            line-height: 1.1; /* Чуть увеличил интервал для двух строк */
            margin-bottom: 15px;
            text-align: center;
            text-shadow: 0 5px 15px rgba(34, 211, 238, 0.2);
        }

        /* ПЛАШКА СТИЛЯ */
        .athlete-info-pill {
            display: inline-block;
            padding: 15px 50px;
            font-size: 1.6rem;
            font-weight: 400;
            color: white;
            text-transform: uppercase;
            margin-bottom: 25px; 
        }

        /* --- 5. СУДЬИ --- */
        .judges-row {
            display: flex;
            gap: 20px;
            margin-bottom: 25px;
        }
        .judge-card {
            background: #0f3d3e;
            border: 2px solid #0992B8;
            border-radius: 12px;
            width: 220px;
            height: 120px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .judge-card.me { background: #062c46; border-color: #0992B8; }
        .judge-label { font-size: 0.8rem; color: #ccc; text-transform: uppercase; font-weight: 700; margin-bottom: 5px; }
        .judge-val { font-size: 3.5rem; font-weight: 900; line-height: 1; }

        /* --- 6. БЛОК РАСЧЕТА --- */
        .calc-wrapper {
            border: 2px solid #0992B8;
            border-radius: 20px;
            background: rgba(30, 41, 59, 0.5);
            padding: 15px;
            display: grid;
            grid-template-columns: 1fr 1.5fr 1fr;
            gap: 20px;
            width: 1000px;
            max-width: 95%;
            margin-bottom: 20px;
        }
        .calc-box {
            min-height: 150px;
            border-radius: 12px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
        }
        .cb-green { border: 3px solid #22c55e; background: rgba(20, 83, 45, 0.2); }
        .cb-pink { border: 3px solid #c2c2c2; background: rgba(88, 28, 135, 0.2); }
        
        .cb-title { font-size: 1rem; font-weight: 700; text-transform: uppercase; color: #e5e7eb; margin-bottom: 10px; }
        .cb-val { font-size: 5rem; font-weight: 900; color: white; }

        /* --- 7. КНОПКА --- */
        .btn-green-huge {
            background-color: #229954;
            color: white;
            font-size: 1.3rem;
            font-weight: 500;
            text-transform: uppercase;
            padding: 10px 20px;
            border-radius: 10px;
            border: none;
            border-bottom: 2px solid #18693c;
            cursor: pointer;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            transition: transform 0.1s;
        }
        .btn-green-huge:active { transform: translateY(4px); border-bottom-width: 4px; box-shadow: none; }
        .btn-green-huge:disabled { background: #E6E9E8; border-bottom-color: rgba(0, 0, 0, 0.15); color: #272727; cursor: not-allowed; }
        /* Тёмная тема (замечание заказчика 05.10): серые кнопки — фон #18181B, белый текст */
        :root.dark .btn-green-huge:disabled { background: #18181B; border-bottom-color: rgba(255, 255, 255, 0.15); color: #FFFFFF; }

        /* Курсор */
        .blink { animation: blinking 1s infinite; border-right: 4px solid white; margin-left: 5px; }
        @keyframes blinking { 50% { border-color: transparent; } }

    </style>

    {{-- СКРИПТ --}}
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('judgePad', () => ({
                init() {
                    {{-- Режим просмотра (админ): ввод с клавиатуры отключён --}}
                    @if(!$isViewOnly)
                    window.addEventListener('keydown', (e) => {
                        const key = e.key;
                        if ((key >= '0' && key <= '9') || key === '.') { @this.addNumber(key); }
                        if (key === 'Backspace') { @this.backspace(); }
                        if (key === 'Enter') {
                            if (@json($canFinalize)) {
                                if(confirm('В ПРОТОКОЛ?')) { @this.finalizeProtocol(); }
                            } else {
                                @this.submitMyScore();
                            }
                        }
                    });
                    @endif
                }
            }))
        })
    </script>

    <div x-data="judgePad" wire:poll.2s="loadState">

        {{-- 1. FIXED HEADER --}}
        <div class="header-fixed">
            <img src="/images/logo.png" class="header-logo" onerror="this.style.display='none'">
            
            <div class="flex items-center gap-8">
                <div class="header-status">
                    <div class="text-xs text-gray-400 uppercase font-bold">Статус системы</div>
                    @if($canFinalize)
                        <div class="text-2xl font-black text-green-400 uppercase">Готово к утверждению</div>
                    @elseif($athleteName)
                        <div class="text-2xl font-black text-blue-400 uppercase">Судейство</div>
                    @else
                        <div class="text-2xl font-black text-gray-500 uppercase">Ожидание</div>
                    @endif
                </div>

                <button wire:click="exitPad" 
                        title="Выйти из пульта"
                        onclick="return confirm('Выйти из пульта Старшего судьи?')"
                        class="text-gray-500 hover:text-red-500 hover:bg-white/5 rounded-full p-2 transition-all">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-10 h-10">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12" />
                    </svg>
                </button>
            </div>
        </div>

        {{-- 2. MAIN CONTENT --}}
        <div class="main-content">
            
            @if($athleteName)
                
                {{-- ИМЯ (КРУПНО, ГОЛУБОЕ) --}}
                {{-- ИЗМЕНЕНИЕ: {!! !!} вместо {{ }} --}}
                <div class="athlete-name-big">
                    {!! $athleteName !!}
                </div>

                {{-- ГРУППА --}}
                <div>
                    <div class="athlete-info-pill">
                        {{ $athleteStyle }} <span style="color: #3b82f6; margin: 0 10px;">/</span> {{ $athleteGroup }}
                    </div>
                </div>

                {{-- СУДЬИ --}}
                <div class="judges-row">
                    {{-- Правило 8.4: карточки только судей бригады этого турнира --}}
                    @foreach($judgesScores as $j)
                        <div class="judge-card">
                            <div class="judge-label">
                                {{-- Правило R-4.18: функция судьи в сценарии A/B --}}
                                @if($scheme === 'ab')
                                    <span style="color: {{ $j['panel'] ? '#a78bfa' : '#fca5a5' }};">[{{ $j['panel'] ?? '?' }}]</span>
                                @endif
                                {{ $j['name'] }}
                            </div>
                            <div class="judge-val text-white">{{ $j['score'] ?? '...' }}</div>

                            {{-- Правило 8.7: снять оценку судьи для перевыставления --}}
                            @if(!$isViewOnly && !is_null($j['score']))
                                <button wire:click="resetJudgeScore({{ $j['id'] }})"
                                        onclick="return confirm('Снять оценку судьи {{ $j['name'] }}? Он выставит её заново.')"
                                        style="margin-top: 6px; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1px; color: #FFFFFF; background: #DC3532; border: 1px solid #DC3532; border-radius: 6px; padding: 2px 8px; cursor: pointer;">
                                    Снять
                                </button>
                            @endif
                        </div>
                    @endforeach
                    
                    {{-- Я (плашка «ст. судья»); админ не судья — в режиме просмотра карточки нет --}}
                    @if(!$isViewOnly)
                    <div class="judge-card me">
                        <div class="judge-label" style="color: #60a5fa;">
                            {{ $mySurname }} (ст. судья)@if($scheme === 'ab') [{{ $myPanel ?? 'без функции' }}]@endif
                        </div>
                        <div class="judge-val">
                            @if(!$myScoreSaved)
                                {{ $myScore }}<span class="blink"></span>
                            @else
                                {{ $myScore }}
                            @endif
                        </div>

                        {{-- Правило 8.7: исправить свою оценку --}}
                        @if($myScoreSaved && $canScoreSelf)
                            <button wire:click="editMyScore"
                                    onclick="return confirm('Исправить свою оценку?')"
                                    style="margin-top: 6px; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1px; color: #FFFFFF; background: #E67E22; border: 1px solid #E67E22; border-radius: 6px; padding: 2px 8px; cursor: pointer;">
                                Исправить
                            </button>
                        @endif
                    </div>
                    @endif
                </div>

                {{-- Правила 8.2, 8.3, 8.4: диапазон и готовность бригады --}}
                <div style="margin-top: -20px; margin-bottom: 30px; text-align: center; font-size: 0.9rem; color: #94a3b8; letter-spacing: 1px;">
                    {{-- Для судьи A (сбавки) диапазон не показываем; для B указываем, чей он --}}
                    @if($scoreRangeLabel && $myPanel !== 'A')
                        <span>@if($myPanel === 'B')ДЛЯ СУДЬИ "B" - @endifДИАПАЗОН: <b style="color:#e2e8f0;">{{ $scoreRangeLabel }}</b></span>
                    @endif
                    @if($scheme === 'ab')
                        {{-- Правила R-4.18, R-4.19: счётчики по панелям --}}
                        <span style="margin-left: 20px;">
                            A: <b style="color:#e2e8f0;">{{ $receivedA }} / {{ $expectedA }}</b>
                        </span>
                        <span style="margin-left: 20px;">
                            B: <b style="color:#e2e8f0;">{{ $receivedB }} / {{ $expectedB }}</b>
                        </span>
                        @if($totalMaxLabel)
                            <span style="margin-left: 20px;">МАКСИМУМ: <b style="color:#e2e8f0;">{{ $totalMaxLabel }}</b></span>
                        @endif
                        @if($unassignedJudges > 0)
                            <span style="margin-left: 20px; color: #fca5a5;">
                                БЕЗ ФУНКЦИИ: {{ $unassignedJudges }} — назначьте A/B в бригаде
                            </span>
                        @endif
                        @if($expectedA === 0 || $expectedB === 0)
                            <span style="margin-left: 20px; color: #fca5a5;">
                                НУЖНЫ СУДЬИ В ОБЕИХ ПАНЕЛЯХ (A и B)
                            </span>
                        @endif
                    @else
                    <span style="margin-left: 20px;">
                        ОЦЕНОК: <b style="color:#e2e8f0;">{{ $receivedScoresCount }} / {{ $expectedScoresCount }}</b>
                    </span>
                    @endif
                    @if($expectedScoresCount === 0)
                        <span style="margin-left: 20px; color: #fca5a5;">
                            БРИГАДА НЕ НАЗНАЧЕНА — привяжите судей к соревнованию
                        </span>
                    @endif
                </div>

                {{-- Правила R-3.12–R-3.15: старший судья в функции A ставит оценку сбавками --}}
                @if(!$isViewOnly && $myPanel === 'A' && !$myScoreSaved)
                    <div style="width: 1000px; max-width: 95%; margin-bottom: 20px;">
                        @if(count($deductionCodes) === 0)
                            <div style="color:#fca5a5; text-align:center;">Справочник кодов сбавок пуст.</div>
                        @endif
                        {{-- Пункт 4 (05.10): кнопки сгруппированы по полю group (пустая
                             группа — «Прочее»); порядок групп — по первому появлению
                             кода (sort_order справочника). Логика по id кода не менялась. --}}
                        @php
                            $deductionGroups = collect($deductionCodes)
                                ->groupBy(fn ($dc) => trim((string) ($dc['group'] ?? '')))
                                ->map(fn ($codes, $key) => ['title' => $key === '' ? 'Прочее' : $key, 'codes' => $codes]);
                        @endphp
                        @foreach($deductionGroups as $group)
                            <div style="margin: 10px 0 6px; padding-bottom: 4px; border-bottom: 1px solid rgba(148,163,184,0.25); font-size: 0.95rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: #38bdf8; text-align:center;">
                                {{ $group['title'] }}
                            </div>
                            <div style="display:flex; flex-wrap:wrap; gap:8px; justify-content:center;">
                                @foreach($group['codes'] as $dc)
                                    @php $cnt = $this->pressCounts[$dc['id']] ?? 0; $locked = $cnt >= \App\Support\JudgingCalculator::MAX_CODE_REPEATS; @endphp
                                    <button wire:click="pressCode({{ $dc['id'] }})" @disabled($locked)
                                            title="{{ $dc['label'] }}{{ $locked ? ' — нажат максимальное число раз' : '' }}"
                                            style="min-width:90px; padding:8px 10px; border-radius:8px; background:#0A92BA; color:white; border:2px solid {{ $cnt > 0 ? '#E67E22' : '#0A92BA' }}; {{ $locked ? 'opacity:0.35; cursor:not-allowed;' : 'cursor:pointer;' }}">
                                        <div style="font-weight:900; font-size:1.1rem;">{{ $dc['code'] }}</div>
                                        <div style="font-size:0.8rem;">−{{ number_format($dc['value'], 3, '.', '') }}{{ $cnt > 0 ? ' ×' . $cnt : '' }}</div>
                                    </button>
                                @endforeach
                            </div>
                        @endforeach
                        <div style="display:flex; justify-content:center; margin-top:10px;">
                            <button wire:click="undoLastCode" @disabled(count($pressedCodes) === 0)
                                    style="padding:8px 14px; border-radius:8px; background:#DC3532; color:white; border:none; {{ count($pressedCodes) === 0 ? 'opacity:0.4;' : 'cursor:pointer;' }}">
                                ОТМЕНИТЬ ПОСЛЕДНЮЮ
                            </button>
                        </div>
                    </div>
                @endif

                {{-- РАСЧЕТ --}}
                <div class="calc-wrapper">
                    <div class="calc-box cb-green">
                        <div class="cb-title">Итоговый балл</div>
                        <div class="cb-val">
                            @if($canFinalize)
                                {{ $finalScoreInput }}@if(!$isViewOnly)<span class="blink" style="height: 60px; display:inline-block;"></span>@endif
                            @else
                                -
                            @endif
                        </div>
                    </div>
                    <div class="calc-box cb-pink">
                        <div class="cb-title" style="color: #f0abfc;">Формула</div>
                        {{-- Правила R-4.16, R-4.20: формула зависит от сценария --}}
                        <div style="font-size: 1.2rem; color: #ccc;">{{ $formulaText }}</div>
                        <div style="margin-top: 15px; font-size: 0.9rem; color: #94a3b8;">
                            {{ $canFinalize ? 'Авто-расчет завершен' : 'Ожидание...' }}
                        </div>
                    </div>
                    <div class="calc-box cb-green">
                        <div class="cb-title">Автоматически</div>
                        <div class="cb-val">{{ $calculatedAvg ?? '-' }}</div>
                    </div>
                </div>

                {{-- Правило 8.9: обоснование ручной правки итогового балла --}}
                @if(!$isViewOnly && $canFinalize && $calculatedAvg !== null && $finalScoreInput !== $calculatedAvg)
                    <div style="width: 1000px; max-width: 95%; margin-bottom: 20px;">
                        <input type="text" wire:model.live.debounce.500ms="finalScoreReason"
                               placeholder="Причина отклонения от авто-расчёта (будет записана в журнал)"
                               style="width: 100%; padding: 12px 16px; border-radius: 10px; background: #0f172a; border: 1px solid #E67E22; color: white; font-size: 1rem;">
                    </div>
                @endif

                {{-- КНОПКА --}}
                <div style="padding-bottom: 35px;">
                    @if($isViewOnly)
                        <button disabled class="btn-green-huge">
                            РЕЖИМ ПРОСМОТРА
                        </button>
                    @elseif($canFinalize)
                        <button wire:click="finalizeProtocol" 
                                onclick="confirm('В ПРОТОКОЛ?') || event.stopImmediatePropagation()"
                                class="btn-green-huge">
                            В ПРОТОКОЛ ↵
                        </button>
                    @elseif(!$myScoreSaved && $canScoreSelf)
                        <button wire:click="submitMyScore" class="btn-green-huge">
                            {{ $isEditingMyScore ? 'СОХРАНИТЬ ИСПРАВЛЕНИЕ ↵' : 'ПОДТВЕРДИТЬ ОЦЕНКУ ↵' }}
                        </button>
                    @else
                        <button disabled class="btn-green-huge">
                            ЖДЕМ СУДЕЙ... ({{ $receivedScoresCount }}/{{ $expectedScoresCount }})
                        </button>
                    @endif
                </div>

            @else
                {{-- ОЖИДАНИЕ --}}
                <div style="margin-top: 100px; text-align: center; opacity: 0.3;">
                    <img src="/images/logo.png" style="height: 150px; filter: grayscale(100%); margin-bottom: 20px;">
                    <h1 style="font-size: 3rem; font-weight: 900; text-transform: uppercase;">Система готова</h1>
                </div>
            @endif

        </div>

    </div>
</x-filament-panels::page>
