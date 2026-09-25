<div wire:poll.2000ms="tick" class="h-screen w-full overflow-hidden bg-[#0b1121] text-white font-sans relative flex flex-col selection:bg-cyan-500 selection:text-white">

    {{-- СТИЛИ --}}
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; }
        .bg-main { background: radial-gradient(circle at 50% 0%, #1e293b 0%, #020617 80%); }
        .glass-panel { background: rgba(30, 41, 59, 0.4); backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.05); box-shadow: 0 4px 30px rgba(0, 0, 0, 0.3); }
        .scale-in-center { animation: scale-in 0.6s cubic-bezier(0.250, 0.460, 0.450, 0.940) both; }
        @keyframes scale-in { 0% { transform: scale(0); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
        .blink-status { animation: blink 2s infinite; }
        @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }

        /* АНИМАЦИЯ ПАУЗЫ (БЕЛЫЙ -> КРАСНЫЙ -> ГОЛУБОЙ) */
        .neon-tricolor {
            animation: tricolor-cycle 6s linear infinite;
        }
        @keyframes tricolor-cycle {
            0%, 100% { border-color: #ffffff; box-shadow: 0 0 50px rgba(255, 255, 255, 0.4), inset 0 0 20px rgba(255, 255, 255, 0.1); }
            33% { border-color: #ef4444; box-shadow: 0 0 50px rgba(239, 68, 68, 0.4), inset 0 0 20px rgba(239, 68, 68, 0.1); }
            66% { border-color: #06b6d4; box-shadow: 0 0 50px rgba(6, 182, 212, 0.4), inset 0 0 20px rgba(6, 182, 212, 0.1); }
        }

        /* АНИМАЦИЯ ОЦЕНКИ (ЗЕЛЕНАЯ ПУЛЬСАЦИЯ) */
        .neon-green-pulse {
            animation: green-pulse-cycle 3s ease-in-out infinite;
        }
        @keyframes green-pulse-cycle {
            0%, 100% {
                border-color: rgba(52, 211, 153, 0.2); /* Тусклый зеленый */
                box-shadow: 0 0 15px rgba(16, 185, 129, 0.1), inset 0 0 5px rgba(16, 185, 129, 0.05);
            }
            50% {
                border-color: #34d399; /* Яркий Emerald-400 */
                box-shadow: 0 0 40px rgba(16, 185, 129, 0.5), inset 0 0 20px rgba(16, 185, 129, 0.2);
            }
        }
    </style>

    {{-- ФОН --}}
    <div class="absolute top-0 left-0 w-full h-full bg-main -z-20"></div>
    <div class="absolute top-[-10%] right-[-5%] w-[500px] h-[500px] bg-cyan-600/20 rounded-full blur-[120px] -z-10"></div>
    <div class="absolute bottom-[-10%] left-[-5%] w-[600px] h-[600px] bg-blue-700/10 rounded-full blur-[100px] -z-10"></div>

    {{-- ШАПКА --}}
    <header class="flex-none h-28 bg-[#111827] border-b border-white/10 flex items-center px-8 shadow-xl z-50">
        @php
            $logoUrl = null;
            $compName = 'ЗАГРУЗКА ДАННЫХ...';
            $fedName = '';

            if ($competition) {
                $path = $competition->logo_path ?? $competition->logo;
                if (!$path && $competition->federation) {
                    $path = $competition->federation->logo_path ?? $competition->federation->logo;
                }
                if ($path) {
                    $logoUrl = asset('storage/' . $path);
                }
                $compName = $competition->name;
                $fedName = $competition->federation ? $competition->federation->name : '';
            }
        @endphp

        <!-- Логотип -->
        <div class="flex-shrink-0 mr-6">
            @if($logoUrl)
                <img src="{{ $logoUrl }}" class="h-20 w-20 rounded-full object-contain">
            @else
                <div class="h-20 w-20 rounded-full bg-gradient-to-br from-blue-600 to-blue-900 flex items-center justify-center">
                    <span class="text-3xl font-black text-white">W</span>
                </div>
            @endif
        </div>

        <!-- Информация -->
        <div class="flex flex-col justify-center overflow-hidden">
            <h1 class="text-2xl md:text-3xl font-black uppercase tracking-tight leading-tight text-white mb-1 drop-shadow-md truncate pr-4">
                {{ $compName }}
            </h1>
            @if($fedName)
                <p class="text-sm md:text-base text-cyan-400 font-bold uppercase tracking-[0.15em] truncate">
                    {{ $fedName }}
                </p>
            @endif
        </div>
    </header>

    {{-- ОСНОВНОЙ КОНТЕНТ --}}
    <main class="flex-1 flex flex-col justify-center relative p-12">
        
        {{-- ПАУЗА --}}
        @if($competition && $competition->status_code == 2)
            <div class="flex flex-col items-center justify-center h-full scale-in-center">
                
                {{-- Квадратная рамка с QR-кодом --}}
                <div class="w-[500px] h-[500px] rounded-3xl bg-[#0f172a] border neon-tricolor flex items-center justify-center mb-12 relative overflow-hidden p-4">
                    {{-- Белый фон внутри рамки --}}
                    <div class="w-full h-full bg-white rounded-2xl flex items-center justify-center">
                        @if($qrCodeUrl)
                            <img 
                                src="{{ $qrCodeUrl }}" 
                                alt="QR Code" 
                                class="w-4/5 h-4/5 object-contain drop-shadow-[0_0_15px_rgba(0,0,0,0.2)]"
                                onerror="this.onerror=null; this.src='https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=' + encodeURIComponent('{{ $publicUrl ?? '' }}');"
                            >
                        @else
                            {{-- Fallback на логотип, если QR-код не доступен --}}
                            <img src="{{ asset('images/logo.png') }}" alt="Logo" class="w-3/4 h-3/4 object-contain drop-shadow-[0_0_15px_rgba(0,0,0,0.2)]">
                        @endif
                    </div>
                </div>

                             
                @if($publicUrl)
                    <p class="text-2xl text-cyan-400 font-bold mt-8 uppercase tracking-wider">
                        Сканируйте QR-код для просмотра результатов
                    </p>
                @endif
            </div>

        {{-- АКТИВНЫЙ УЧАСТНИК --}}
        @elseif($current)
            <div class="grid grid-cols-12 gap-12 h-full items-center">
                <div class="col-span-7 flex flex-col justify-center h-full pl-4">
                    <div class="flex items-center gap-4 mb-8">
                        <div class="w-6 h-6 bg-emerald-500 rounded-full shadow-[0_0_20px_rgba(16,185,129,0.8)] blink-status"></div>
                        <span class="text-emerald-400 font-black uppercase tracking-widest text-2xl">
                             {{ $showFinal ? 'РЕЗУЛЬТАТ' : 'НА КОВРЕ' }}
                        </span>
                    </div>

                    {{-- ЛОГИКА ОТОБРАЖЕНИЯ ИМЕН --}}
                    @if($current->partner)
                        {{-- ПАРНОЕ ВЫСТУПЛЕНИЕ --}}
                        @php
                            // Вычисляем размер шрифта для двух строк, чтобы влезли
                            $len1 = mb_strlen($current->athlete->name);
                            $len2 = mb_strlen($current->partner->name);
                            $maxLen = max($len1, $len2);
                            
                            // Базовый шрифт меньше, так как строки две
                            $fontClass = $maxLen <= 14 ? 'text-[5rem] leading-[0.9]' : ($maxLen <= 22 ? 'text-[3.5rem] leading-none' : 'text-[2.5rem] leading-tight');
                        @endphp

                        <div class="flex flex-col gap-2 mb-6 transition-all duration-300">
                            <h1 class="{{ $fontClass }} font-black uppercase text-transparent bg-clip-text bg-gradient-to-r from-white via-slate-100 to-slate-400 drop-shadow-2xl break-words">
                                {{ $current->athlete->name }}
                            </h1>
                            <h1 class="{{ $fontClass }} font-black uppercase text-transparent bg-clip-text bg-gradient-to-r from-white via-slate-100 to-slate-400 drop-shadow-2xl break-words">
                                {{ $current->partner->name }}
                            </h1>
                        </div>

                    @else
                        {{-- ОДИНОЧНОЕ ВЫСТУПЛЕНИЕ (как было) --}}
                        @php
                            $name = $current->athlete->name;
                            $len = mb_strlen($name);
                            $fontClass = $len <= 14 ? 'text-[6.5rem] leading-[0.9]' : ($len <= 22 ? 'text-[5rem] leading-none' : 'text-[3.5rem] leading-tight');
                        @endphp

                        <h1 class="{{ $fontClass }} font-black uppercase text-transparent bg-clip-text bg-gradient-to-r from-white via-slate-100 to-slate-400 drop-shadow-2xl mb-6 break-words transition-all duration-300">
                            {{ $name }}
                        </h1>
                    @endif

                    @php
                         $city = $current->athlete->club->city ?? ($current->athlete->city ?? 'Город не указан');
                    @endphp

                    <div class="text-4xl text-cyan-400 font-medium flex items-center gap-4">
                        <svg class="w-8 h-8 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                        <span class="truncate pr-4">{{ $city }}</span>
                    </div>
                </div>

                <div class="col-span-5 flex flex-col gap-6 h-full justify-center">
                    <div class="glass-panel p-8 rounded-2xl border-l-4 border-cyan-500 relative overflow-hidden flex flex-col justify-center min-h-[180px]">
                        @php
                            $styleName = $current->style->name;
                            $styleLen = mb_strlen($styleName);
                            $styleClass = $styleLen <= 12 ? 'text-6xl leading-[0.9]' : ($styleLen <= 24 ? 'text-5xl leading-none' : ($styleLen <= 34 ? 'text-4xl leading-tight' : 'text-2xl leading-tight'));
                        @endphp
                        <div class="{{ $styleClass }} font-black text-white uppercase break-words mb-4">{{ $styleName }}</div>
                        <div class="text-3xl uppercase font-bold text-cyan-400">
                            {{ $current->ageGroup->name ?? '' }}
                            @if($current->ageGroup)
                                <span class="ml-2 opacity-90">({{ $current->ageGroup->min_age }}–{{ $current->ageGroup->max_age }} лет)</span>
                            @endif
                        </div>
                    </div>

                    {{-- Блок Итоговой Оценки с классом neon-green-pulse --}}
                    <div class="glass-panel neon-green-pulse border p-8 rounded-2xl min-h-[280px] flex flex-col justify-center items-center relative">
                        <div class="absolute inset-0 bg-gradient-to-br from-green-900/40 to-emerald-900/10 z-0 rounded-2xl"></div>
                        <div class="relative z-10 text-center">
                            <div class="text-emerald-400 font-bold uppercase tracking-[0.3em] mb-4 text-sm">Итоговая оценка</div>
                            <div class="text-[9rem] font-black leading-none text-emerald-400 drop-shadow-[0_0_40px_rgba(16,185,129,0.5)]">
                                {{ number_format((float)($current->final_score ?? 0), 3, '.', '') }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        {{-- ОЖИДАНИЕ --}}
        @else
            <div class="flex flex-col items-center justify-center h-full opacity-40">
                <div class="text-8xl mb-6 grayscale">⌛</div>
                <div class="text-4xl font-bold uppercase tracking-widest text-slate-500">Ожидание начала</div>
            </div>
        @endif
    </main>

    {{-- ПОДВАЛ --}}
    @if($next && $competition && $competition->status_code != 2)
        <footer class="h-28 flex justify-start items-center px-16 border-t border-white/5 bg-slate-900/50 backdrop-blur-md z-50">
            <div class="flex items-center gap-6">
                <div class="flex items-center gap-4 mr-14 shrink-0">
                    <div class="w-5 h-5 bg-orange-500 rounded-full shadow-[0_0_10px_rgba(249,115,22,0.8)] blink-status"></div>
                    <span class="text-orange-400 font-black uppercase tracking-wider text-3xl">Далее</span>
                </div>
                <!-- ЛОГИКА ДЛЯ NEXT (Если пара - выводим через слэш) -->
                <span class="text-3xl font-bold text-white uppercase tracking-wider drop-shadow-lg mr-14 whitespace-nowrap">
                    {{ $next->athlete->name }}
                    @if($next->partner) / {{ $next->partner->name }} @endif
                </span>
                <span class="text-3xl font-black text-cyan-400 uppercase drop-shadow-lg truncate">{{ $next->style->name }}</span>
            </div>
        </footer>
    @endif
</div>
