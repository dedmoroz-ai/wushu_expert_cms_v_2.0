<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Сводка оценок — {{ $competition->name }}</title>
    <style>
        @page { margin: 10mm 10mm 12mm 10mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #000; }
        h1 { font-size: 13pt; margin: 0 0 4px 0; text-align: center; }
        .subtitle { text-align: center; font-size: 10pt; margin-bottom: 12px; color: #444; }

        /* ВАЖНО (dompdf): при auto-раскладке таблица расширяется до минимальной
           ширины контента и правые колонки уходят за край листа. table-layout: fixed
           (включается только вместе с явной шириной таблицы) + процентные ширины
           ячеек первой строки удерживают таблицу в ширине страницы, а длинные
           ФИО/клубы переносятся (overflow-wrap), не раздувая колонки. */
        table { border-collapse: collapse; width: 100%; table-layout: fixed; }
        th, td { border: 1px solid #444; padding: 2px 3px; vertical-align: middle; word-wrap: break-word; overflow-wrap: anywhere; }
        th { background: #eee; text-align: center; font-weight: bold; }
        td.left { text-align: left; }
        td.center { text-align: center; font-size: 8pt; }

        .warn   { background: #fff3a3; }
        .danger { background: #f5b5b5; font-weight: bold; }
        .min    { background: #dbeafe; }
        .max    { background: #d1fae5; }
        .na     { background: #e5e5e5; color: #777; }
        th.group { background: #d8d8d8; }

        .legend { font-size: 8pt; margin-top: 8px; color: #555; }
        .legend .box { display: inline-block; width: 10px; height: 10px; border: 1px solid #444; margin-right: 4px; vertical-align: middle; }
    </style>
</head>
<body>

@php
    $isAb = $matrix['isAb'];
    $groupLabels = [
        \App\Models\Competition::PANEL_A => 'Панель A — качество исполнения (сбавки)',
        \App\Models\Competition::PANEL_B => 'Панель B — общее впечатление',
        \App\Support\ScoresSummaryMatrix::GROUP_NONE => 'Без функции (в расчёте не участвуют)',
    ];
@endphp

<h1>Сводная таблица оценок судей</h1>
<div class="subtitle">
    {{ $competition->name }}@if ($dateStr) — {{ $dateStr }}@endif<br>
    Схема судейства: {{ $isAb ? 'A/B (панели A и B)' : 'простая' }}
</div>

@if (count($matrix['rows']) === 0)
    <p style="text-align:center; color:#666;">Нет закрытых выступлений.</p>
@else
    <table>
        <thead>
            @if ($isAb)
                <tr>
                    <th rowspan="2" style="width:3%">#</th>
                    <th rowspan="2" style="width:22%">Спортсмен / Клуб</th>
                    <th rowspan="2" style="width:7%">Стиль</th>
                    <th rowspan="2" style="width:7%">Группа</th>
                    @foreach ($matrix['groupsOrder'] as $g)
                        @if (count($matrix['judgesByGroup'][$g]) > 0)
                            <th class="group" colspan="{{ count($matrix['judgesByGroup'][$g]) + 1 }}">{{ $groupLabels[$g] }}</th>
                        @endif
                    @endforeach
                    <th rowspan="2" style="width:7%">Расчёт (A+B)</th>
                    <th rowspan="2" style="width:6%">Итог</th>
                </tr>
                <tr>
                    @foreach ($matrix['groupsOrder'] as $g)
                        @foreach ($matrix['judgesByGroup'][$g] as $judge)
                            <th>{{ $judge->name }}</th>
                        @endforeach
                        @if (count($matrix['judgesByGroup'][$g]) > 0)
                            <th>Ср. {{ $g }}</th>
                        @endif
                    @endforeach
                </tr>
            @else
                <tr>
                    <th style="width:3%">#</th>
                    <th style="width:22%">Спортсмен / Клуб</th>
                    <th style="width:7%">Стиль</th>
                    <th style="width:7%">Группа</th>
                    @foreach ($matrix['judgesByGroup'][\App\Support\ScoresSummaryMatrix::GROUP_NONE] as $judge)
                        <th>{{ $judge->name }}</th>
                    @endforeach
                    <th style="width:6%">Сред.</th>
                    <th style="width:6%">Итог</th>
                </tr>
            @endif
        </thead>
        <tbody>
            @foreach ($matrix['rows'] as $i => $row)
                @php
                    $reg = $row['reg'];
                    $athlete = $reg->athlete;
                    // ФИО хранится одним полем athletes.name (как на странице сводки):
                    // полей last_name/first_name в модели Athlete нет.
                    $name = trim((string) ($athlete?->full_name ?? $athlete?->name ?? ''));
                    if ($reg->partner) {
                        $partnerName = trim((string) ($reg->partner->full_name ?? $reg->partner->name ?? ''));
                        if ($partnerName !== '') {
                            $name = ($name !== '' ? $name . ' / ' : '') . $partnerName;
                        }
                    }
                    if ($name === '') {
                        $name = '—';
                    }
                @endphp
                <tr>
                    <td class="center">{{ $i + 1 }}</td>
                    <td class="left">
                        <b>{{ $name }}</b>
                        @if ($athlete?->club)
                            <br><span style="font-size:8pt; color:#555;">{{ $athlete->club->name }}</span>
                        @endif
                    </td>
                    <td class="left">{{ $reg->style?->name }}</td>
                    <td class="left">{{ $reg->ageGroup?->name }}</td>

                    @foreach ($matrix['groupsOrder'] as $g)
                        @foreach ($matrix['judgesByGroup'][$g] as $judge)
                            @php
                                $val = $row['cells'][$judge->id] ?? null;
                                $isCounted = $row['counted'][$judge->id] ?? false;
                                $gAvg = $row['groups'][$g]['avg'];
                                $cls = '';
                                if ($val !== null && ! $isCounted) {
                                    $cls = 'na';
                                } elseif ($val !== null) {
                                    $level = \App\Filament\Pages\ScoresSummary::deviationLevel($val, $gAvg);
                                    $cls = $level === 'danger' ? 'danger' : ($level === 'warn' ? 'warn' : '');
                                    if ($judge->id === $row['groups'][$g]['minJudgeId']) {
                                        $cls = 'min';
                                    } elseif ($judge->id === $row['groups'][$g]['maxJudgeId']) {
                                        $cls = 'max';
                                    }
                                }
                            @endphp
                            <td class="center {{ $cls }}">
                                {{ $val !== null ? number_format($val, 3, '.', '') : '—' }}
                            </td>
                        @endforeach
                        @if (count($matrix['judgesByGroup'][$g]) > 0)
                            <td class="center" style="background:#f3f3f3;">
                                {{ $row['groups'][$g]['avg'] !== null ? number_format($row['groups'][$g]['avg'], 3, '.', '') : '—' }}
                            </td>
                        @endif
                    @endforeach

                    @if ($isAb)
                        <td class="center" style="background:#f3f3f3;">
                            {{ $row['avg'] !== null ? number_format($row['avg'], 3, '.', '') : '—' }}
                        </td>
                    @endif

                    <td class="center"><b>{{ $row['final'] !== null ? number_format($row['final'], 3, '.', '') : '—' }}</b></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="legend">
        <span class="box warn"></span> отклонение от средней{{ $isAb ? ' панели' : '' }} ≥ {{ $warn }} &nbsp;&nbsp;
        <span class="box danger"></span> отклонение ≥ {{ $danger }} &nbsp;&nbsp;
        <span class="box" style="background:#dbeafe"></span> минимум (отброшен) &nbsp;&nbsp;
        <span class="box" style="background:#d1fae5"></span> максимум (отброшен)
        @if ($isAb)
            &nbsp;&nbsp;<span class="box" style="background:#e5e5e5"></span> не учтена (выставлена не в своей функции)
            <br>Итог A/B = «Ср. A» + «Ср. B»; в каждой панели при 3+ оценках отбрасываются одна минимальная и одна максимальная.
        @endif
    </div>
@endif

</body>
</html>