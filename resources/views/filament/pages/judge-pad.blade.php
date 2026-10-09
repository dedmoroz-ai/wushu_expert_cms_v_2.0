<x-filament-panels::page>
    <style>
        /* 1. СБРОС */
        .fi-sidebar, .fi-topbar, footer, .fi-header { display: none !important; }
        .fi-main { margin: 0 !important; padding: 0 !important; max-width: 100% !important; }
        .fi-body { padding: 0 !important; }
        .fi-page, .fi-page > section { padding: 0 !important; margin: 0 !important; gap: 0 !important; }
        html, body { height: 100%; overflow: hidden; }
        
        /* 2. ФОН */
        body { background-color: #0e1422 !important; color: white !important; }

        /* 3. ОБОЛОЧКА ПУЛЬТА (адаптив под телефоны):
              flex-колонка на 100dvh: прокручивается только средняя часть,
              нижняя кнопка — обычный блок и всегда в кадре. Не fixed:
              на iOS fixed-bottom прячется за панелью браузера. */
        .pad-shell {
            display: flex !important;
            flex-direction: column;
            width: 100%;
            height: 100vh;   /* старые браузеры */
            height: 100dvh;  /* динамическая высота: панель адреса не срезает кнопку */
            position: relative;
        }
        .pad-scroll {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            overscroll-behavior: contain;
            -webkit-overflow-scrolling: touch;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 16px 16px 24px;
        }
        .pad-bottom {
            flex: 0 0 auto;
            padding: 16px 16px max(16px, env(safe-area-inset-bottom, 0px));
            background-color: rgba(14, 20, 34, 0.95);
            border-top: 1px solid #1e293b;
        }

        /* 4. КНОПКИ (СЕТКА) */
        .pad-grid {
            display: grid !important;
            grid-template-columns: repeat(3, 1fr) !important;
            gap: 12px !important;
            width: 100% !important;
        }

        .pad-btn {
            background-color: #0A92BA; 
            color: white;
            border: 1px solid #0A92BA;
            border-radius: 12px;
            font-size: clamp(1.4rem, 6.5vw, 2rem);
            font-weight: 700;
            min-height: 70px; 
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.1s;
            box-shadow: 0 4px 0 #020617;
        }
        .pad-btn:active {
            transform: translateY(4px);
            box-shadow: none;
        }

        .btn-red {
            background-color: #DC3532 !important;
            border-color: #b32b29 !important;
            box-shadow: 0 4px 0 #9c2724 !important;
        }

        /* 4. ПОЛЕ ВВОДА */
        .score-display {
            background-color: #afafaf !important;
            color: #0c091f !important;
            border-radius: 16px;
            min-height: 90px;
            padding: 4px 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: clamp(2.5rem, 12vw, 4rem);
            font-weight: 900;
            border: 4px solid #0A92BA;
            margin-bottom: 20px;
        }

        /* Угловые кнопки: «во весь экран» и «выход из пульта» (сессия сохраняется) */
        .pad-corner {
            position: absolute;
            top: max(12px, env(safe-area-inset-top, 0px));
            right: 12px;
            z-index: 9999;
            display: flex;
            gap: 8px;
        }
        .exit-btn-fixed {
            position: static !important;
            background: rgba(255, 255, 255, 0.1);
            border: none;
            touch-action: manipulation;
            -webkit-tap-highlight-color: transparent;
            border-radius: 50%;
            padding: 8px;
            cursor: pointer;
            color: #9ca3af;
            transition: all 0.2s;
        }
        .exit-btn-fixed:hover {
            color: #ef4444; 
            background: rgba(255, 255, 255, 0.2);
        }

        /* Адаптив */
        @media (max-height: 650px) {
            .logo-container { display: none !important; }
            .spacer-block { height: 10px !important; min-height: 10px !important; }
            .gray-divider { display: none !important; } 
            .score-display { min-height: 64px !important; font-size: clamp(2rem, 10vw, 3rem) !important; margin-bottom: 10px !important; }
            .pad-btn { min-height: 52px !important; font-size: 1.35rem !important; }
        }
        @media (max-height: 480px) {
            .pad-btn { min-height: 44px !important; font-size: 1.15rem !important; }
            .score-display { min-height: 52px !important; }
        }
        @media (max-width: 380px) {
            .pad-grid, .deduction-grid { gap: 8px !important; }
            .pad-scroll { padding: 12px 12px 16px !important; }
        }
    </style>

    {{-- Оболочка пульта (адаптив): колонка на 100dvh, прокручивается только
         средняя часть .pad-scroll, нижняя кнопка — обычный блок .pad-bottom --}}
    <div wire:poll.3s="loadState" class="pad-shell font-sans text-white bg-[#0e1422]">
        <div class="pad-corner">
            <div wire:ignore>
                <button type="button" id="pad-fullscreen-btn" class="exit-btn-fixed" title="Во весь экран"
                        onclick="(function () { var d = document.documentElement; if (document.fullscreenElement) { document.exitFullscreen(); } else if (d.requestFullscreen) { d.requestFullscreen().catch(function () {}); } })()">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-8 h-8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15" />
                    </svg>
                </button>
                <script>
                    (function () {
                        var btn = document.getElementById('pad-fullscreen-btn');
                        if (btn && !document.documentElement.requestFullscreen) { btn.style.display = 'none'; }
                    })();
                </script>
            </div>

        {{-- === КНОПКА ВЫХОДА ИЗ ПУЛЬТА (не из аккаунта) === --}}
        <button wire:click="exitPad" 
                title="Выйти из пульта"
                onclick="return confirm('Выйти из пульта?')"
                class="exit-btn-fixed">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-8 h-8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12" />
            </svg>
        </button>
        </div>

        <div class="pad-scroll">
        {{-- ЛОГОТИП --}}
        <div class="logo-container flex-none">
            <img src="/images/logo.png" alt="Logo" class="h-16 w-auto object-contain mx-auto" 
                 onerror="this.style.display='none'">
        </div>

        {{-- СЕРАЯ ПОЛОСА --}}
        <div class="gray-divider w-full max-w-md flex-none my-4" 
             style="height: 2px; background-color: #1f2937; border-radius: 2px;">
        </div>

        {{-- ИНФОРМАЦИЯ --}}
        <div class="w-full max-w-md text-center flex-none">
            @if($canVote && $athleteName)
                {{-- 
                    ИЗМЕНЕНИЕ: {!! !!} вместо {{ }} 
                    Добавлен leading-tight для нормального межстрочного интервала 
                --}}
                <h1 class="text-3xl sm:text-4xl font-black uppercase leading-tight mb-2 tracking-wide" 
                    style="color: #38bdf8;"> 
                    {!! $athleteName !!}
                </h1>
                
                <div class="text-xl sm:text-2xl font-bold text-white mb-1 leading-tight">
                    {{ $athleteStyle }}
                </div>
                <div class="text-lg text-slate-400 font-medium">
                    {{ $athleteGroup }}
                </div>
            @else
                <div class="py-10 flex flex-col items-center animate-pulse opacity-50">
                    <div class="text-2xl font-bold uppercase tracking-widest text-white">
                        {{ $statusMessage }}
                    </div>
                </div>
            @endif
        </div>

        {{-- РАСПОРКА --}}
        <div class="spacer-block w-full" style="height: 25px; min-height: 25px;"></div>

        {{-- КЛАВИАТУРА --}}
        @if($canVote)
            <div class="w-full max-w-md flex-grow">
                {{-- Правила 8.2, 8.3: допустимый диапазон оценки; для судьи A (сбавки) не показываем --}}
                @if($scoreRangeLabel && $panel !== 'A')
                    <div class="text-center text-sm font-bold uppercase tracking-widest mb-2" style="color: #64748b;">
                        Диапазон: {{ $scoreRangeLabel }}
                    </div>
                @endif

                {{-- Правило 8.7: режим исправления ранее выставленной оценки --}}
                @if($isEditing)
                    <div class="text-center text-sm font-bold uppercase tracking-widest mb-2" style="color: #fbbf24;">
                        Исправление (было: {{ $savedScore }})
                    </div>
                @endif

                @if($panel)
                    <div class="text-center text-sm font-bold uppercase tracking-widest mb-2" style="color: #a78bfa;">
                        Функция: судья {{ $panel }}
                    </div>
                @endif

                @if($inputMode === 'codes')
                    {{-- Правила R-3.12–R-3.15: судья A — сбавки от 5.000 кнопками кодов --}}
                    <div class="score-display">
                        {{ $this->currentAScore }}
                    </div>

                    @if(count($pressedCodes))
                        <div class="text-center text-sm mb-3" style="color: #cbd5e1;">
                            @foreach($pressedCodes as $pid)
                                @php $pc = collect($deductionCodes)->firstWhere('id', (int) $pid); @endphp
                                @if($pc)
                                    <span class="inline-block px-2 py-1 m-1 rounded" style="background:#1e293b;">
                                        {{ $pc['code'] }} −{{ number_format($pc['value'], 3, '.', '') }}
                                    </span>
                                @endif
                            @endforeach
                        </div>
                    @endif

                    @if(count($deductionCodes) === 0)
                        <div class="text-center text-lg font-bold py-6" style="color: #fca5a5;">
                            Справочник кодов сбавок пуст — обратитесь к администратору.
                        </div>
                    @endif

                    {{-- Пункт 4 (05.10): кнопки сгруппированы по полю group (пустая
                         группа — «Прочее»); порядок групп — по первому появлению
                         кода (sort_order справочника), внутри группы — как в
                         справочнике. Логика по id кода не менялась. --}}
                    @php
                        $deductionGroups = collect($deductionCodes)
                            ->groupBy(fn ($dc) => trim((string) ($dc['group'] ?? '')))
                            ->map(fn ($codes, $key) => ['title' => $key === '' ? 'Прочее' : $key, 'codes' => $codes]);
                    @endphp
                    @foreach($deductionGroups as $group)
                        <div style="width:100%; margin: 12px 0 6px; padding-bottom: 4px; border-bottom: 1px solid #1e293b; font-size: 0.95rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: #38bdf8;">
                            {{ $group['title'] }}
                        </div>
                        <div class="deduction-grid" style="width:100%; display:grid; grid-template-columns: repeat(3, 1fr); gap: 10px;">
                            @foreach($group['codes'] as $dc)
                                @php $cnt = $this->pressCounts[$dc['id']] ?? 0; $locked = $cnt >= \App\Support\JudgingCalculator::MAX_CODE_REPEATS; @endphp
                                <button wire:click="pressCode({{ $dc['id'] }})"
                                        @disabled($locked)
                                        title="{{ $dc['label'] }}{{ $locked ? ' — нажат максимальное число раз' : '' }}"
                                        class="pad-btn"
                                        style="height:auto; min-height:70px; flex-direction:column; font-size:1.3rem; padding:6px; {{ $locked ? 'opacity:0.35; cursor:not-allowed;' : '' }} {{ $cnt > 0 ? 'border-color:#fbbf24;' : '' }}">
                                    <span>{{ $dc['code'] }}</span>
                                    <span style="font-size:0.85rem; font-weight:500;">−{{ number_format($dc['value'], 3, '.', '') }}{{ $cnt > 0 ? ' ×' . $cnt : '' }}</span>
                                    @if($locked)
                                        <span style="font-size:0.6rem; font-weight:400;">макс. {{ \App\Support\JudgingCalculator::MAX_CODE_REPEATS }}</span>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    @endforeach

                    <button wire:click="undoLastCode" @disabled(count($pressedCodes) === 0)
                            class="pad-btn btn-red w-full mt-3" style="font-size:1.1rem; min-height:55px; {{ count($pressedCodes) === 0 ? 'opacity:0.4;' : '' }}">
                        ОТМЕНИТЬ ПОСЛЕДНЮЮ
                    </button>
                @else
                <div class="score-display">
                    {{ $score }}<span class="text-blue-600 animate-pulse">|</span>
                </div>

                <div class="pad-grid">
                    @foreach([1, 2, 3, 4, 5, 6, 7, 8, 9] as $n)
                        <button wire:click="addNumber({{ $n }})" class="pad-btn">
                            {{ $n }}
                        </button>
                    @endforeach
                    <button wire:click="addNumber('.')" class="pad-btn btn-soft-gray">.</button>
                    <button wire:click="addNumber(0)" class="pad-btn">0</button>
                    <button wire:click="backspace" class="pad-btn btn-red">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-8 h-8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9.75L14.25 12m0 0l2.25 2.25M14.25 12l2.25-2.25M14.25 12L12 14.25m-2.58 4.92l-6.375-6.375a1.125 1.125 0 010-1.59L9.42 4.83c.211-.211.498-.33.796-.33H19.5a2.25 2.25 0 012.25 2.25v10.5a2.25 2.25 0 01-2.25 2.25h-9.284c-.298 0-.585-.119-.796-.33z" />
                        </svg>
                    </button>
                </div>
                @endif
            </div>
        @else
            @if($statusMessage == 'Оценка принята')
                <div class="mt-8 text-center">
                    <div class="inline-flex items-center justify-center w-24 h-24 rounded-full bg-green-500 text-white mb-4 animate-bounce">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor" class="w-12 h-12">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                        </svg>
                    </div>
                    <div class="text-3xl font-black text-white">ПРИНЯТО</div>

                    {{-- Показываем свою оценку --}}
                    @if($savedScore !== null)
                        <div class="text-5xl font-black mt-3" style="color: #38bdf8;">{{ $savedScore }}</div>
                    @endif

                    {{-- Правило R-3.12: состав оценки судьи A --}}
                    @if(count($savedDeductions))
                        <div class="text-sm mt-2" style="color: #94a3b8;">
                            @foreach($savedDeductions as $sd)
                                <span class="inline-block px-2 py-1 m-1 rounded" style="background:#1e293b;">{{ $sd['code'] }} −{{ number_format($sd['value'], 3, '.', '') }}</span>
                            @endforeach
                        </div>
                    @endif

                    {{-- Правило 8.7: исправление оценки до утверждения протокола --}}
                    @if($canEditScore)
                        <button wire:click="startEditing"
                                onclick="return confirm('Исправить свою оценку?')"
                                class="mt-6 px-6 py-3 rounded-xl font-bold uppercase tracking-widest text-white active:scale-95 transition-transform"
                                style="background-color: #E67E22; border-bottom: 4px solid #b3611b;">
                            Исправить оценку
                        </button>
                    @endif
                </div>
            @endif
        @endif
        </div>{{-- /pad-scroll --}}

        {{-- НИЖНЯЯ КНОПКА: обычный блок оболочки (не fixed) — всегда в кадре --}}
        @if($canVote)
            <div class="pad-bottom">
                <div class="max-w-md mx-auto">
                {{-- Правило 8.2: кнопка активна, когда введено корректное число --}}
                @if(is_numeric($score) || $inputMode === 'codes')
                    <button wire:click="submitScore" 
                            class="w-full text-white font-black text-xl py-4 rounded-xl shadow-lg uppercase tracking-widest active:scale-95 transition-transform"
                            style="background-color: #229954 !important; border-bottom: 4px solid #18693c !important;">
                        {{ $isEditing ? 'СОХРАНИТЬ ИСПРАВЛЕНИЕ' : 'ПОДТВЕРДИТЬ' }}
                    </button>
                @else
                    <button disabled 
                            class="w-full btn-soft-gray font-bold text-xl py-4 rounded-xl uppercase tracking-widest">
                        ВВЕДИТЕ ОЦЕНКУ
                    </button>
                @endif

                {{-- Правило 8.7: выход из режима исправления без сохранения --}}
                @if($isEditing)
                    <button wire:click="cancelEditing"
                            class="w-full mt-3 btn-soft-gray font-bold text-base py-3 rounded-xl uppercase tracking-widest">
                        Отмена
                    </button>
                @endif
                </div>
            </div>
        @endif
    </div>{{-- /pad-shell --}}
</x-filament-panels::page>
