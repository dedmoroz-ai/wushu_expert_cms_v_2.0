<x-filament-panels::page>
    <style>
        /* 1. СБРОС */
        .fi-sidebar, .fi-topbar, footer, .fi-header { display: none !important; }
        .fi-main { margin: 0 !important; padding: 0 !important; max-width: 100% !important; }
        .fi-body { padding: 0 !important; }
        
        /* 2. ФОН */
        body { background-color: #0e1422 !important; color: white !important; }

        /* 3. КНОПКИ (СЕТКА) */
        .pad-grid {
            display: grid !important;
            grid-template-columns: repeat(3, 1fr) !important;
            gap: 12px !important;
            width: 100% !important;
        }

        .pad-btn {
            background-color: #37578a; 
            color: white;
            border: 1px solid #37578a;
            border-radius: 12px;
            font-size: 2rem;
            font-weight: 700;
            height: 70px; 
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
            background-color: #f31111 !important;
            border-color: #9e0505 !important;
            box-shadow: 0 4px 0 #7f1d1d !important;
        }

        /* 4. ПОЛЕ ВВОДА */
        .score-display {
            background-color: #afafaf !important;
            color: #0c091f !important;
            border-radius: 16px;
            height: 90px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 4rem;
            font-weight: 900;
            border: 4px solid #37578a;
            margin-bottom: 20px;
        }

        /* КНОПКА ВЫХОДА */
        .logout-btn-fixed {
            position: fixed !important;
            top: 15px !important;
            right: 15px !important;
            z-index: 9999 !important;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            padding: 8px;
            cursor: pointer;
            color: #9ca3af;
            transition: all 0.2s;
        }
        .logout-btn-fixed:hover {
            color: #ef4444; 
            background: rgba(255, 255, 255, 0.2);
        }

        /* Адаптив */
        @media (max-height: 650px) {
            .logo-container { display: none !important; }
            .spacer-block { height: 10px !important; min-height: 10px !important; }
            .gray-divider { display: none !important; } 
            .score-display { height: 70px !important; font-size: 3rem !important; margin-bottom: 10px !important; }
            .pad-btn { height: 55px !important; font-size: 1.5rem !important; }
        }
    </style>

    {{-- Убрал relative у контейнера, чтобы не мешал fixed --}}
    <div wire:poll.3s="loadState" class="min-h-screen w-full flex flex-col items-center pt-4 px-4 pb-48 font-sans bg-[#0e1422] overflow-y-auto">

        {{-- === КНОПКА ВЫХОДА === --}}
        <button wire:click="logout" 
                title="Выйти"
                onclick="return confirm('Выйти из пульта?')"
                class="logout-btn-fixed">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-8 h-8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12" />
            </svg>
        </button>

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

                    <div class="deduction-grid" style="display:grid; grid-template-columns: repeat(3, 1fr); gap: 10px;">
                        @foreach($deductionCodes as $dc)
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

                    <button wire:click="undoLastCode" @disabled(count($pressedCodes) === 0)
                            class="pad-btn btn-red w-full mt-3" style="font-size:1.1rem; height:55px; {{ count($pressedCodes) === 0 ? 'opacity:0.4;' : '' }}">
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
                    <button wire:click="addNumber('.')" class="pad-btn" style="background-color: #2a436b;">.</button>
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
                                style="background-color: #d97706; border-bottom: 4px solid #92400e;">
                            Исправить оценку
                        </button>
                    @endif
                </div>
            @endif
        @endif
    </div>

    {{-- НИЖНЯЯ КНОПКА --}}
    @if($canVote)
        <div class="fixed bottom-0 left-0 w-full p-4 z-50" style="background-color: rgba(14, 20, 34, 0.95); border-top: 1px solid #1e293b;">
            <div class="max-w-md mx-auto">
                {{-- Правило 8.2: кнопка активна, когда введено корректное число --}}
                @if(is_numeric($score) || $inputMode === 'codes')
                    <button wire:click="submitScore" 
                            class="w-full text-white font-black text-xl py-4 rounded-xl shadow-lg uppercase tracking-widest active:scale-95 transition-transform"
                            style="background-color: #16a34a !important; border-bottom: 4px solid #14532d !important;">
                        {{ $isEditing ? 'СОХРАНИТЬ ИСПРАВЛЕНИЕ' : 'ПОДТВЕРДИТЬ' }}
                    </button>
                @else
                    <button disabled 
                            class="w-full text-gray-400 font-bold text-xl py-4 rounded-xl uppercase tracking-widest cursor-not-allowed"
                            style="background-color: #374151 !important; border: 1px solid #4b5563 !important; opacity: 0.5;">
                        ВВЕДИТЕ ОЦЕНКУ
                    </button>
                @endif

                {{-- Правило 8.7: выход из режима исправления без сохранения --}}
                @if($isEditing)
                    <button wire:click="cancelEditing"
                            class="w-full mt-3 text-slate-300 font-bold text-base py-3 rounded-xl uppercase tracking-widest"
                            style="background-color: #1e293b; border: 1px solid #334155;">
                        Отмена
                    </button>
                @endif
            </div>
        </div>
    @endif
</x-filament-panels::page>
