<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Печать дипломов</title>
@php
    /*
     * Печать на ГОТОВЫЙ бланк диплома (A4, книжная).
     * На бланке уже есть шапка, иконки по бокам и «Министр спорта» внизу,
     * поэтому печатаем ТОЛЬКО: награждается / ФИО / место / дисциплина / возраст.
     *
     * Все размеры — в мм от края листа.
     * Свободная зона бланка по вертикали ≈ 128…197 мм, иконки ≈ 30 и 180 мм.
     *
     * ПОПРАВКА ПОД ПРИНТЕР: если на пробной печати текст ушёл —
     *   $offsetY > 0 — вниз, < 0 — вверх;  $offsetX > 0 — вправо, < 0 — влево.
     */
    $offsetX = 0;     // мм
    $offsetY = 0;     // мм

    $blockTop   = 136; // мм, верх первой строки («награждается»)
    $blockLeft  = 35;  // мм, левый край текстового блока
    $blockWidth = 140; // мм, ширина блока (центр листа = 105 мм)

    $top  = $blockTop + $offsetY;
    $left = $blockLeft + $offsetX;

    // Автоуменьшение шрифта для длинных строк (чтобы не переносились).
    // Пороги замерены на ширине 140 мм: ФИО 26px ≈ 29 симв., 22px ≈ 34, 19px ≈ 40;
    // строки 16px ≈ 57 симв., 14px ≈ 65, 12px ≈ 76. Если строка длиннее — она
    // перенесётся, следующие строки сдвинутся вниз (наложения не будет).
    $nameSize = function (string $s): int {
        $len = mb_strlen($s);
        if ($len > 40) return 16;
        if ($len > 33) return 19;
        if ($len > 27) return 22;
        return 26;
    };
    $lineSize = function (string $s): int {
        $len = mb_strlen($s);
        if ($len > 64) return 12;
        if ($len > 55) return 14;
        return 16;
    };
    $romanPlace = function ($place): string {
        return [1 => 'I', 2 => 'II', 3 => 'III'][(int) $place] ?? (string) $place;
    };
@endphp
    <style>
        @page { margin: 0; }
        html, body {
            margin: 0;
            padding: 0;
        }
        body {
            font-family: 'DejaVu Serif', serif;
            color: #000;
        }
        .page {
            position: relative;
            width: 210mm;
            height: 296mm;
            overflow: hidden;
            page-break-after: always;
        }
        .page-last {
            page-break-after: avoid;
        }
        /* Один контейнер: строки идут друг за другом, перенос сдвигает следующие вниз */
        .content {
            position: absolute;
            top: {{ $top }}mm;
            left: {{ $left }}mm;
            width: {{ $blockWidth }}mm;
            text-align: center;
        }
        .awarded {
            font-size: 20px;
            font-style: italic;
            line-height: 1.2;
        }
        .athlete-name {
            margin-top: 3mm;
            font-weight: bold;
            font-style: italic;
            line-height: 1.15;
        }
        .place {
            margin-top: 4mm;
            font-size: 20px;
            line-height: 1.2;
        }
        .place-number {
            font-size: 26px;
            font-weight: bold;
        }
        .discipline {
            margin-top: 3mm;
            font-style: italic;
            line-height: 1.2;
        }
        .age {
            margin-top: 2mm;
            font-style: italic;
            line-height: 1.2;
        }
    </style>
</head>
<body>

@foreach($winners as $item)
    @php
        $ageGroup   = $item['registration']->ageGroup ?? null;
        $discipline = 'в дисциплине ' . $style->name;
        $age        = 'возрастная категория ' . ($ageGroup->name ?? '')
                    . ' (' . ($ageGroup->min_age ?? 0) . '-' . ($ageGroup->max_age ?? 0) . ' лет)'
                    // Группа «O» (R-6.15): подпись подгруппы в заголовке категории.
                    . (($item['registration']->is_special ?? false) ? ' (O)' : '');
    @endphp
    <div class="page{{ $loop->last ? ' page-last' : '' }}">
        <div class="content">
            <div class="awarded">награждается</div>

            <div class="athlete-name" style="font-size: {{ $nameSize($item['name']) }}px;">{{ $item['name'] }}</div>

            <div class="place">за <span class="place-number">{{ $romanPlace($item['place']) }}</span> место</div>

            <div class="discipline" style="font-size: {{ $lineSize($discipline) }}px;">{{ $discipline }}</div>

            <div class="age" style="font-size: {{ $lineSize($age) }}px;">{{ $age }}</div>
        </div>
    </div>
@endforeach

</body>
</html>
