<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>{{ $title ?? 'Табло соревнований' }}</title>

        {{-- Подключаем Tailwind (через CDN для быстрого старта на этой странице) --}}
        <script src="https://cdn.tailwindcss.com"></script>
        
        {{-- Стили Livewire (автоматически) --}}
        @livewireStyles
        <style>
            /* Тонкие ползунки скролла: бегунок — синий #0A92BA,
               дорожка — тёмная #18181B (табло всегда тёмное) */
            * {
                scrollbar-width: thin;
                scrollbar-color: #0A92BA #18181B;
            }

            ::-webkit-scrollbar {
                width: 6px;
                height: 6px;
            }

            ::-webkit-scrollbar-track {
                background: #18181B;
            }

            ::-webkit-scrollbar-thumb {
                background-color: #0A92BA;
                border-radius: 3px;
            }
        </style>
    </head>
    <body class="antialiased bg-slate-900 text-white">
        
        {{-- Сюда Livewire вставит наш компонент scoreboard --}}
        {{ $slot }}

        @livewireScripts
    </body>
</html>
