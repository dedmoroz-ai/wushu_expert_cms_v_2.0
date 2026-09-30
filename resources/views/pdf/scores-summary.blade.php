<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Сводка оценок — {{ $competition->name }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #000; }
        h1 { font-size: 13pt; margin: 0 0 4px 0; text-align: center; }
        .subtitle { text-align: center; font-size: 10pt; margin-bottom: 12px; color: #444; }

        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #444; padding: 3px 4px; vertical-align: middle; }
        th { background: #eee; text-align: center; font-weight: bold; }
        td.left { text-align: left; }
        td.center { text-align: center; }

        .warn   { background: #fff3a3; }
        .danger { background: #f5b5b5; font-weight: bold; }

        .legend { font-size: 8pt; margin-top: 8px; color: #555; }
        .legend .box { display: inline-block; width: 10px; height: 10px; border: 1px solid #444; margin-right: 4px; vertical-align: middle; }
    </style>
</head>
<body>

<h1>Сводная таблица оценок судей</h1>
<div class="subtitle">
    {{ $competition->name }}@if ($dateStr) — {{ $dateStr }}@endif
</div>

@if (count($rows) === 0)
    <p style="text-align:center; color:#666;">Нет закрытых выступлений.</p>
@else
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Спортсмен / Клуб</th>
                <th>Стиль</th>
                <th>Группа</th>
                @foreach ($judges as $judge)
                    <th>{{ $judge->name }}</th>
                @endforeach
                <th>Сред.</th>
                <th>Итог</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $i => $row)
                @php
                    $reg = $row['reg'];
                    $athlete = $reg->athlete;
                    $name = trim(($athlete?->last_name ?? '') . ' ' . ($athlete?->first_name ?? ''));
                    if ($reg->partner) {
                        $name .= ' / ' . $reg->partner->last_name . ' ' . $reg->partner->first_name;
                    }
                @endphp
                <tr>
                    <td class="center">{{ $i + 1 }}</td>
                    <td class="left">
                        <b>{{ $name !== '' ? $name : '—' }}</b>
                        @if ($athlete?->club)
                            <br><span style="font-size:8pt; color:#555;">{{ $athlete->club->name }}</span>
                        @endif
                    </td>
                    <td class="left">{{ $reg->style?->name }}</td>
                    <td class="left">{{ $reg->ageGroup?->name }}</td>

                    @foreach ($judges as $judge)
                        @php
                            $val = $row['cells'][$judge->id] ?? null;
                            $level = \App\Filament\Pages\ScoresSummary::deviationLevel($val, $row['avg']);
                            $cls = $level === 'danger' ? 'danger' : ($level === 'warn' ? 'warn' : '');
                        @endphp
                        <td class="center {{ $cls }}">
                            {{ $val !== null ? number_format($val, 3, '.', '') : '—' }}
                        </td>
                    @endforeach

                    <td class="center" style="background:#f3f3f3;">
                        {{ $row['avg'] !== null ? number_format($row['avg'], 3, '.', '') : '—' }}
                    </td>
                    <td class="center"><b>{{ $row['final'] !== null ? number_format($row['final'], 3, '.', '') : '—' }}</b></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="legend">
        <span class="box warn"></span> отклонение от средней ≥ {{ $warn }} &nbsp;&nbsp;
        <span class="box danger"></span> отклонение ≥ {{ $danger }}
    </div>
@endif

</body>
</html>