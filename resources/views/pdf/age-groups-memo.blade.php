<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Памятка судьям: лимиты оценок — {{ $competition->name }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #000; }
        h1 { font-size: 14pt; margin: 0 0 4px 0; text-align: center; }
        h2 { font-size: 11pt; margin: 14px 0 6px 0; }
        .subtitle { text-align: center; font-size: 10pt; margin-bottom: 2px; color: #444; }
        .scheme { text-align: center; font-size: 9pt; margin-bottom: 12px; color: #444; }

        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #444; padding: 4px 6px; vertical-align: middle; }
        th { background: #eee; text-align: center; font-weight: bold; }
        td.group { font-weight: bold; }
        td.num { text-align: center; white-space: nowrap; }

        .notes { margin-top: 12px; font-size: 9pt; }
        .notes li { margin-bottom: 3px; }
    </style>
</head>
<body>

<h1>Памятка судьям: лимиты оценок</h1>
<div class="subtitle">
    {{ $competition->name }}@if($competition->city) — {{ $competition->city }}@endif@if($dateStr), {{ $dateStr }}@endif
</div>
<div class="scheme">Схема судейства: {{ $schemeLabel }}</div>

<h2>Лимиты оценок для этой категории</h2>
<table>
    <thead>
    <tr>
        <th>Возрастная группа</th>
        <th style="width: 60px;">Пол</th>
        <th style="width: 85px;">Возраст, лет</th>
        <th style="width: 90px;">Мин. балл</th>
        <th style="width: 90px;">Макс. балл</th>
    </tr>
    </thead>
    <tbody>
    @if(count($rows) === 0)
        <tr><td colspan="5" style="text-align: center;">Возрастные группы не заданы</td></tr>
    @endif
    @foreach($rows as $row)
        <tr>
            <td class="group">{{ $row['name'] }}</td>
            <td class="num">{{ $row['gender'] }}</td>
            <td class="num">{{ $row['age'] }}</td>
            <td class="num">{{ $row['categoryMin'] }}</td>
            <td class="num">{{ $row['categoryMax'] }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<h2>Сценарий A/B: лимиты оценок судей B</h2>
<table>
    <thead>
    <tr>
        <th>Возрастная группа</th>
        <th style="width: 60px;">Пол</th>
        <th style="width: 85px;">Возраст, лет</th>
        <th style="width: 90px;">Мин. балл (B)</th>
        <th style="width: 90px;">Макс. балл (B)</th>
    </tr>
    </thead>
    <tbody>
    @if(count($rows) === 0)
        <tr><td colspan="5" style="text-align: center;">Возрастные группы не заданы</td></tr>
    @endif
    @foreach($rows as $row)
        <tr>
            <td class="group">{{ $row['name'] }}</td>
            <td class="num">{{ $row['gender'] }}</td>
            <td class="num">{{ $row['age'] }}</td>
            <td class="num">{{ $row['bMin'] }}</td>
            <td class="num">{{ $row['bMax'] }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<ul class="notes">
    <li><strong>Судья A (качество исполнения):</strong> всегда от 5.000 вниз по кодам сбавок (0.000–5.000), лимиты категории не применяются.</li>
    <li><strong>Судья B (впечатление):</strong> оценка — в пределах «Мин. балл (B)» … «Макс. балл (B)».</li>
    <li>Итоговый балл (A+B) проверяется в диапазоне категории; точность оценок — три знака после точки.</li>
</ul>

</body>
</html>