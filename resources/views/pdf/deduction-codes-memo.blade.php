<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Памятка судьям: коды сбавок — {{ $competition->name }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #000; }
        h1 { font-size: 14pt; margin: 0 0 4px 0; text-align: center; }
        .subtitle { text-align: center; font-size: 10pt; margin-bottom: 2px; color: #444; }
        .scheme { text-align: center; font-size: 9pt; margin-bottom: 12px; color: #444; }

        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #444; padding: 4px 6px; vertical-align: middle; }
        th { background: #eee; text-align: center; font-weight: bold; }
        td.code { text-align: center; font-weight: bold; width: 55px; }
        td.value { text-align: center; width: 75px; white-space: nowrap; }
        tr.group td { background: #d8d8d8; font-weight: bold; text-align: left; }

        .notes { margin-top: 12px; font-size: 9pt; }
        .notes li { margin-bottom: 3px; }
    </style>
</head>
<body>

<h1>Памятка судьям: коды сбавок</h1>
<div class="subtitle">
    {{ $competition->name }}@if($competition->city) — {{ $competition->city }}@endif@if($dateStr), {{ $dateStr }}@endif
</div>
<div class="scheme">Схема судейства: {{ $schemeLabel }}</div>

<table>
    <thead>
    <tr>
        <th style="width: 55px;">Код</th>
        <th style="width: 75px;">Сбавка</th>
        <th>Описание ошибки</th>
    </tr>
    </thead>
    <tbody>
    @if(count($groups) === 0)
        <tr><td colspan="3" style="text-align: center;">Активные коды сбавок не заданы</td></tr>
    @endif
    @foreach($groups as $label => $items)
        @if($label !== '')
            <tr class="group"><td colspan="3">{{ $label }}</td></tr>
        @endif
        @foreach($items as $code)
            <tr>
                <td class="code">{{ $code->code }}</td>
                <td class="value">−{{ number_format((float) $code->value, 3, '.', '') }}</td>
                <td>{{ $code->label }}</td>
            </tr>
        @endforeach
    @endforeach
    </tbody>
</table>

<ul class="notes">
    <li><strong>Судья A (качество исполнения):</strong> оценка = 5.000 − сумма сбавок (не ниже 0).</li>
    <li>Один код можно нажать <strong>не более {{ $maxRepeats }} раз</strong> за выступление; последнее нажатие можно отменить.</li>
    <li>Нажатые коды сохраняются вместе с оценкой (код, описание и величина сбавки).</li>
    <li>В памятке приведены только активные коды; неактивные на пульт не выводятся.</li>
</ul>

</body>
</html>