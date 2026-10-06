<x-filament::page>
    {{-- СТИЛИ --}}
    <style>
        /* --- АНИМАЦИИ --- */

        /* 1. Зеленая пульсация */
        @keyframes green-pulse-anim {
            0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4); border-color: #10b981; }
            70% { box-shadow: 0 0 0 15px rgba(16, 185, 129, 0); border-color: #34d399; }
            100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); border-color: #10b981; }
        }

        /* 2. Оранжевая пульсация */
        @keyframes orange-pulse-anim {
            0% { box-shadow: 0 0 0 0 rgba(230, 126, 34, 0.4); border-color: #E67E22; }
            70% { box-shadow: 0 0 0 15px rgba(230, 126, 34, 0); border-color: #eda35f; }
            100% { box-shadow: 0 0 0 0 rgba(230, 126, 34, 0); border-color: #E67E22; }
        }

        /* --- КЛАССЫ ДЛЯ БЛОКОВ --- */

        .pulse-box {
            transition: all 0.3s ease;
            border-width: 2px;
            border-style: solid;
        }

        .pulse-green {
            animation: green-pulse-anim 2s infinite;
            border-color: #10b981;
        }

        .pulse-orange {
            animation: orange-pulse-anim 2s infinite;
            border-color: #E67E22;
        }

        .pulse-gray {
            border-color: #374151; /* Gray-700 */
            box-shadow: none;
        }


        /* --- ОСТАЛЬНЫЕ СТИЛИ --- */
        .top-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 16px; margin-bottom: 16px; height: 110px; }
        
        .btn-custom { display: flex; align-items: center; justify-content: center; width: 100%; border-radius: 10px; font-weight: 800; text-transform: uppercase; cursor: pointer; border: none; color: white; transition: all 0.2s; font-family: sans-serif; letter-spacing: 0.05em; line-height: 1.1; }
        .btn-custom:hover { opacity: 0.9; transform: scale(0.99); }
        .btn-custom:active { transform: scale(0.97); }

        .btn-yellow { background-color: #E67E22; color: #FFFFFF; }
        .btn-red { background-color: #DC3532; }
        .btn-green { background-color: #229954; }
        .btn-blue { background-color: #0A92BA; }
        .btn-gray { background-color: #E6E9E8; color: #272727; border: 1px solid rgba(0, 0, 0, 0.08); }

        .main-card { background-color: #0f172a; border-radius: 15px; min-height: 480px; position: relative; display: flex; flex-direction: column; justify-content: space-between; overflow: hidden; }
        .card-content { padding-top: 45px; flex-grow: 1; display: flex; flex-direction: column; align-items: center; justify-content: flex-start; }
        
        /* ИМЯ СПОРТСМЕНА (Базовый стиль) */
        .athlete-name { 
            line-height: 1.1; 
            font-weight: 900; 
            text-transform: uppercase; 
            color: #38bdf8; /* Bright Sky Blue */
            margin-bottom: 10px; 
            text-align: center; 
            text-shadow: 0 0 25px rgba(56, 189, 248, 0.4); 
        }

        /* Стиль для одиночного (крупно) */
        .name-single { font-size: 3rem; }
        
        /* Стиль для пары (чуть меньше, чтобы влезли оба) */
        .name-pair { 
            font-size: 2.3rem; 
            display: flex; 
            flex-direction: column; 
            gap: 15px; /* Отступ между именами */
        }
        
        .info-block { margin-bottom: 12px; text-align: center; }
        .footer-buttons { padding: 20px; display: grid; grid-template-columns: 1fr 2fr; gap: 15px; background: rgba(0,0,0,0.2); }
    </style>

    {{-- 1. ВЕРХНЯЯ ПАНЕЛЬ --}}
    <div class="top-grid">
        
        @php
            $statusClass = 'pulse-gray';
            if ($record->status_code == 1) {
                $statusClass = 'pulse-green';
            } elseif ($record->status_code == 2) {
                $statusClass = 'pulse-orange';
            }
        @endphp

        <!-- Блок статуса -->
        <div class="pulse-box {{ $statusClass }}" style="background-color: #1f2937; padding: 15px 20px; border-radius: 12px; display: flex; flex-direction: column; justify-content: center;">
            <div style="color: #9ca3af; font-size: 0.9rem; font-weight: bold; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 5px;">
                Текущий статус
            </div>
            <div style="font-size: 1.8rem; font-weight: 900; line-height: 1;">
                @if($record->status_code == 0) <span style="color: #6b7280;">ОЖИДАНИЕ</span>
                @elseif($record->status_code == 1) <span style="color: #ffffff;">ИДЕТ СОРЕВНОВАНИЕ</span>
                @elseif($record->status_code == 2) <span style="color: #eab308;">ПАУЗА</span>
                @elseif($record->status_code == 3) <span style="color: #ef4444;">ЗАВЕРШЕНО</span>
                @endif
            </div>
        </div>

        <!-- Кнопки управления -->
        <div style="display: flex; flex-direction: column; gap: 10px; height: 100%;">
            @if($record->status_code != 1)
                <button wire:click="start" class="btn-custom btn-green" style="height: 100%; font-size: 1.1rem;">
                    НАЧАТЬ ТУРНИР
                </button>
            @endif

            @if($record->status_code == 1)
                <button wire:click="pause" class="btn-custom btn-yellow" style="flex: 1; font-size: 1rem;">
                    ПАУЗА
                </button>
                <button wire:click="stop" onclick="confirm('Точно завершить?') || event.stopImmediatePropagation()" class="btn-custom btn-red" style="flex: 1; font-size: 1rem;">
                    ЗАВЕРШИТЬ ТУРНИР
                </button>
            @endif
        </div>
    </div>

    {{-- 2. ОСНОВНАЯ КАРТОЧКА --}}
    <div class="main-card pulse-box pulse-green">
        
        <!-- Лейбл "На ковре" -->
        <div style="position: absolute; top: 20px; left: 20px; display: flex; align-items: center; gap: 8px; padding: 6px 14px; background: rgba(255,255,255,0.1); border-radius: 6px; border: 1px solid rgba(255,255,255,0.2);">
            <div style="width: 10px; height: 10px; background: #10b981; border-radius: 50%; box-shadow: 0 0 10px #10b981;"></div>
            <span style="color: #34d399; font-size: 0.8rem; font-weight: 800; letter-spacing: 0.1em;">НА КОВРЕ</span>
        </div>

        @if($record->currentRegistration)
            <div class="card-content">
                
                {{-- --- ЛОГИКА ОТОБРАЖЕНИЯ ИМЕН --- --}}
                
                @if($record->currentRegistration->partner)
                    {{-- ЕСЛИ ЭТО ДУЙЛЯНЬ (ПАРА) --}}
                    {{-- Выводим друг под другом, одинаково --}}
                    <div class="athlete-name name-pair">
                        <div>
                            {{ $record->currentRegistration->athlete->surname }} {{ $record->currentRegistration->athlete->name }}
                        </div>
                        <div>
                            {{ $record->currentRegistration->partner->surname }} {{ $record->currentRegistration->partner->name }}
                        </div>
                    </div>
                @else
                    {{-- ОБЫЧНОЕ ОДИНОЧНОЕ ВЫСТУПЛЕНИЕ --}}
                    <div class="athlete-name name-single">
                        {{ $record->currentRegistration->athlete->surname }}<br>
                        {{ $record->currentRegistration->athlete->name }}
                    </div>
                @endif
                
                {{-- --------------------------------- --}}

                <!-- СТИЛЬ -->
                <div class="info-block">
                    <div style="color: white; font-size: 1.8rem; font-weight: 800; text-transform: uppercase; text-shadow: 0 4px 10px rgba(0,0,0,0.5);">
                        {{ $record->currentRegistration->style->name ?? 'Стиль' }}
                    </div>
                </div>

                <!-- ГРУППА -->
                @if($record->currentRegistration->ageGroup)
                    <div class="info-block">
                        <div style="color: #d1d5db; font-size: 1.2rem; font-weight: 700; text-transform: uppercase;">
                            {{ $record->currentRegistration->ageGroup->name }}
                            <span style="opacity: 0.6; font-size: 0.9em; margin-left: 8px;">
                                ({{ $record->currentRegistration->ageGroup->min_age }}–{{ $record->currentRegistration->ageGroup->max_age }} лет)
                            </span>
                        </div>
                    </div>
                @endif

                <!-- ГОРОД -->
                <div class="info-block" style="display: flex; align-items: center; gap: 8px; color: #9ca3af; font-size: 1.2rem; font-weight: 600;">
                    <svg style="width: 20px; height: 20px; color: #ef4444;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    {{ $record->currentRegistration->athlete->city ?? ($record->currentRegistration->athlete->club->city ?? 'Город не указан') }}
                </div>

                <!-- НОМЕР -->
                <div class="info-block" style="color: #6b7280; font-family: monospace; font-size: 1rem;">
                    Порядок выступление по протоколу # <span style="color: white; font-weight: bold;">{{ $record->currentRegistration->sort_order }}</span>
                </div>
            </div>

            <!-- ФУТЕР КНОПКИ -->
            <div class="footer-buttons">
                <button wire:click="prevAthlete" class="btn-custom btn-gray" style="height: 55px; font-size: 1rem;">
                    <svg style="width: 20px; height: 20px; margin-right: 6px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                    Назад
                </button>

                <button wire:click="nextAthlete" class="btn-custom btn-blue" style="height: 55px; font-size: 1.2rem; box-shadow: 0 0 15px rgba(10,146,186,0.3);">
                    СЛЕДУЮЩИЙ УЧАСТНИК
                    <svg style="width: 24px; height: 24px; margin-left: 10px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                </button>
            </div>

        @else
            <div style="flex-grow: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; opacity: 0.3;">
                <svg style="width: 80px; height: 80px; color: gray;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                <div style="font-size: 1.5rem; font-weight: 900; text-transform: uppercase; margin-top: 15px;">Нет участника</div>
            </div>
             <div class="footer-buttons">
                <button wire:click="prevAthlete" class="btn-custom btn-gray" style="height: 55px;">Назад</button>
                <button wire:click="nextAthlete" class="btn-custom btn-gray" style="height: 55px;">Вперед</button>
            </div>
        @endif
    </div>
</x-filament::page>
