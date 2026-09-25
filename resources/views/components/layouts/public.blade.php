<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>{{ $title ?? 'Результаты соревнований' }}</title>
        {{-- Подключаем Tailwind через CDN --}}
        <script src="https://cdn.tailwindcss.com"></script>
        @livewireStyles
    </head>
    <body class="antialiased bg-gray-50">
        {{ $slot }}
        @livewireScripts
    </body>
</html>
