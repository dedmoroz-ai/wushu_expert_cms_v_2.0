<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WUSHU EXPERT CMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;900&display=swap" rel="stylesheet">
    <!-- Основная иконка -->
    <link rel="icon" href="{{ asset('favicon/favicon.ico') }}">
    
    <!-- Иконки для современных браузеров -->
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon/favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon/favicon-16x16.png') }}">
    
    <!-- Иконка для Apple -->
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('favicon/apple-touch-icon.png') }}">
    
    <!-- Манифест для Android -->
    <link rel="manifest" href="{{ asset('favicon/site.webmanifest') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Exo+2:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Exo 2', sans-serif; }
        .glass {
            background: rgba(255, 255, 255, 0.03);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.1);
        }
        .glass-card:hover {
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(255, 255, 255, 0.2);
            transform: translateX(-5px);
        }
        /* Отключаем смещение карточки на мобильных при таче */
        @media (max-width: 1024px) {
            .glass-card:hover { transform: none; }
        }
    </style>
</head>
<body class="antialiased bg-gray-950 text-white min-h-screen lg:h-screen lg:overflow-hidden flex flex-col">

    <!-- ФОН -->
    <div class="fixed inset-0 z-0">
        <img src="{{ asset('images/9878.png') }}" 
             class="w-full h-full object-cover opacity-60" 
             alt="Background">
        <div class="absolute inset-0 bg-gradient-to-r from-gray-950 via-gray-900/80 to-gray-950/90"></div>
    </div>

    <!-- HEADER -->
    <header class="relative z-10 w-full h-28 lg:h-40 shrink-0 flex items-center justify-center border-b border-white/5 transition-all">
        <div class="flex items-center">
            <img src="{{ asset('images/logo.png') }}" alt="Logo" class="h-20 lg:h-32 w-auto object-contain drop-shadow-lg transition-all">
        </div>
    </header>

    <!-- MAIN CONTENT -->
    <main class="relative z-10 flex-grow container mx-auto px-6 py-10 lg:py-0 lg:px-8 flex items-center h-full">
        
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-12 w-full items-center">
            
            <!-- ЛЕВАЯ КОЛОНКА -->
            <div class="lg:col-span-7 flex flex-col justify-center space-y-8 lg:space-y-10 text-center lg:text-left order-1">
                
                <div class="space-y-4">
                                        
                    <h1 class="text-3xl sm:text-4xl lg:text-7xl font-black leading-tight lg:leading-none tracking-tight">
                        Система
                        <span class="text-transparent bg-clip-text bg-gradient-to-r from-white to-gray-600">автоматизации турниров по Ушу</span>
                    </h1>
                    
                    <!-- ДОБАВЛЕНО: hidden lg:block (скрыто на мобильных, видно на ПК) -->
                    <p class="hidden lg:block text-base sm:text-lg text-gray-400 max-w-xl leading-relaxed lg:mx-0">
                        Полный цикл управления турниром.<br>Больше никаких ошибок в подсчетах и потерянных заявок.<br>Только честный спорт и мгновенные результаты.
                    </p>
                </div>

                <!-- КНОПКА ВХОДА -->
                <div class="max-w-md w-full mx-auto lg:mx-0">
                    @auth
                        <a href="{{ url('/admin') }}" class="group relative w-full flex items-center justify-center gap-3 bg-green-600 hover:bg-green-500 text-white font-bold text-lg py-4 lg:py-5 px-8 rounded-2xl transition-all shadow-xl shadow-green-900/20 hover:shadow-green-500/30 transform hover:-translate-y-1 active:scale-95">
                            <span class="absolute inset-0 w-full h-full bg-gradient-to-r from-white/0 via-white/10 to-white/0 transform -translate-x-full group-hover:translate-x-full transition-transform duration-700"></span>
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0zm6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            ЛИЧНЫЙ КАБИНЕТ
                        </a>
                    @else
                        <a href="{{ url('/admin/login') }}" class="group relative w-full flex items-center justify-center gap-3 bg-red-600 hover:bg-red-500 text-white font-bold text-lg py-4 lg:py-5 px-8 rounded-2xl transition-all shadow-xl shadow-red-900/20 hover:shadow-red-600/40 transform hover:-translate-y-1 active:scale-95">
                            <span class="absolute inset-0 w-full h-full bg-gradient-to-r from-white/0 via-white/10 to-white/0 transform -translate-x-full group-hover:translate-x-full transition-transform duration-700"></span>
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path></svg>
                            ВОЙТИ В СИСТЕМУ
                        </a>
                    @endauth
                </div>

            </div>

            <!-- ПРАВАЯ КОЛОНКА -->
            <div class="lg:col-span-5 flex flex-col justify-center gap-4 h-auto lg:h-full lg:max-h-[60vh] order-2 pb-8 lg:pb-0">
                
                <!-- Тренерам -->
                <div class="glass glass-card p-5 rounded-xl transition duration-300 cursor-default group">
                    <div class="flex items-start lg:items-center gap-4">
                        <div class="w-10 h-10 bg-blue-500/10 text-blue-400 rounded-lg flex items-center justify-center group-hover:bg-blue-500 group-hover:text-white transition shrink-0 mt-1 lg:mt-0">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                        </div>
                        <div>
                            <h3 class="font-bold text-white text-l">Тренерам</h3>
                            <p class="text-gray-400 text-s leading-relaxed mt-1">
                                Быстрая заявка, контроль разрядов и статистика клуба.<br class="hidden sm:block">Интеллектуальная проверка возрастных допусков и лимитов участия.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Судьям -->
                <div class="glass glass-card p-5 rounded-xl transition duration-300 cursor-default group">
                    <div class="flex items-start lg:items-center gap-4">
                        <div class="w-10 h-10 bg-red-500/10 text-red-400 rounded-lg flex items-center justify-center group-hover:bg-red-500 group-hover:text-white transition shrink-0 mt-1 lg:mt-0">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3"></path></svg>
                        </div>
                        <div>
                            <h3 class="font-bold text-white text-l">Судьям</h3>
                            <p class="text-gray-400 text-s leading-relaxed mt-1">
                                Цифровой пульт. Оценка летит на табло за секунду.<br class="hidden sm:block">
                                Автоматический подсчет итогов без калькуляторов.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Секретарям -->
                <div class="glass glass-card p-5 rounded-xl transition duration-300 cursor-default group">
                    <div class="flex items-start lg:items-center gap-4">
                        <div class="w-10 h-10 bg-green-500/10 text-green-400 rounded-lg flex items-center justify-center group-hover:bg-green-500 group-hover:text-white transition shrink-0 mt-1 lg:mt-0">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                        </div>
                        <div>
                            <h3 class="font-bold text-white text-l">Секретарям</h3>
                            <p class="text-gray-400 text-s leading-relaxed mt-1">
                                Авто-жеребьевка и готовые PDF протоколы в один клик.<br class="hidden sm:block">Точное соблюдение регламента и порядка выхода участников на ковер.
                            </p>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>
    
    <!-- Footer (50px, на всю ширину): версия слева, год + копирайт + лого разработчика справа -->
    <div class="relative z-10 w-full h-[50px] border-t border-white/5 bg-gray-950 shrink-0">
        <div class="h-full w-full px-4 md:px-6 flex items-center justify-between text-[11px] text-white">
            <span>Wushu Expert CMS {{ config('app.version') }}</span>
            <span class="inline-flex items-center gap-2">
                <span>2026 &copy;</span>
                <img src="{{ asset('images/c989.svg') }}" alt="Max Moroz" class="h-6 w-auto">
                <span>Макс Мороз</span>
            </span>
        </div>
    </div>

</body>
</html>
