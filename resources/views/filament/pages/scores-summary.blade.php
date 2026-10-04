<x-filament-panels::page>
    @php
        $competitions = $this->getCompetitionsList();
        $matrix = $this->getMatrix();
        $competition = $this->getCompetition();
        $isAb = $matrix['isAb'] ?? false;
        $groupLabels = [
            \App\Models\Competition::PANEL_A => 'Панель A — качество исполнения (сбавки)',
            \App\Models\Competition::PANEL_B => 'Панель B — общее впечатление',
            \App\Support\ScoresSummaryMatrix::GROUP_NONE => 'Без функции (в расчёте не участвуют)',
        ];
    @endphp

    <form method="GET" class="mb-8 no-print">
        <div class="mb-4">
            <label class="block text-sm font-medium mb-1">Соревнование</label>
            <select name="competitionId"
                    class="w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-700">
                <option value="">— выберите —</option>
                @foreach($competitions as $c)
                    <option value="{{ $c->id }}" @selected($competition && $competition->id === $c->id)>
                        {{ $c->name }}
                        @if($c->start_date) ({{ \Illuminate\Support\Carbon::parse($c->start_date)->format('d.m.Y') }}) @endif
                    </option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-wrap gap-3 summary-actions">
            <button type="submit"
                    style="background:#2563eb;color:#fff;padding:8px 18px;border-radius:8px;font-weight:500;border:0;cursor:pointer;"
                    onmouseover="this.style.background='#1d4ed8'"
                    onmouseout="this.style.background='#2563eb'">
                Показать
            </button>
            @if($competition && $matrix && count($matrix['rows']) > 0)
                <a href="{{ route('competition.scores-summary', $competition) }}"
                        target="_blank" rel="noopener"
                        style="background:#374151;color:#fff;padding:8px 18px;border-radius:8px;font-weight:500;border:0;cursor:pointer;display:inline-flex;align-items:center;gap:8px;text-decoration:none;"
                        onmouseover="this.style.background='#1f2937'"
                        onmouseout="this.style.background='#374151'">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                         stroke-width="2" stroke="currentColor" style="width:16px;height:16px;">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                    </svg>
                    <span>Скачать PDF</span>
                </a>
                <button type="button"
                        onclick="window.print()"
                        style="background:#374151;color:#fff;padding:8px 18px;border-radius:8px;font-weight:500;border:0;cursor:pointer;display:inline-flex;align-items:center;gap:8px;"
                        onmouseover="this.style.background='#1f2937'"
                        onmouseout="this.style.background='#374151'">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                         stroke-width="2" stroke="currentColor" style="width:16px;height:16px;">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.229 2.523a1.125 1.125 0 01-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0021 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 00-1.913-.247M6.34 18H5.25A2.25 2.25 0 013 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 011.913-.247m10.5 0a48.536 48.536 0 00-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5zm-3 0h.008v.008H15V10.5z" />
                    </svg>
                    <span>Печать</span>
                </button>
            @endif
        </div>
    </form>

    @if(! $competition)
        <div class="p-6 text-gray-600 dark:text-gray-300 bg-white dark:bg-gray-900 rounded-xl shadow no-print">
            Выберите соревнование, чтобы увидеть сводную таблицу.
        </div>
    @elseif(! $matrix || count($matrix['rows']) === 0)
        <div class="p-6 text-gray-600 dark:text-gray-300 bg-white dark:bg-gray-900 rounded-xl shadow no-print">
            По выбранному соревнованию нет завершённых выступлений.
        </div>
    @else
        <div class="print-header">
            <h1>Сводная таблица оценок</h1>
            <div class="print-subtitle">
                {{ $competition->name }}
                @if($competition->start_date)
                    — {{ \Illuminate\Support\Carbon::parse($competition->start_date)->format('d.m.Y') }}
                @endif
                — схема судейства: {{ $isAb ? 'A/B (панели A и B)' : 'простая' }}
            </div>
        </div>

        <style>
            .scores-scroll {
                max-height: calc(100vh - 240px);
                overflow: auto;
                -webkit-overflow-scrolling: touch;
                border-radius: 12px;
            }
            .scores-table {
                width: 100%;
                min-width: 720px;
                table-layout: fixed;
                border-collapse: separate;
                border-spacing: 0;
                font-size: 12px;
            }
            .scores-table th,
            .scores-table td {
                padding: 6px 4px;
                border-bottom: 1px solid #e5e7eb;
                word-wrap: break-word;
                overflow-wrap: break-word;
            }
            .dark .scores-table th,
            .dark .scores-table td {
                border-bottom-color: #374151;
            }
            .scores-table thead th {
                position: sticky;
                top: 0;
                z-index: 20;
                background: #f3f4f6;
                font-weight: 600;
                font-size: 11px;
                line-height: 1.2;
                box-shadow: inset 0 -1px 0 #d1d5db;
            }
            .dark .scores-table thead th {
                background: #1f2937;
                box-shadow: inset 0 -1px 0 #4b5563;
            }
            .col-num    { width: 36px;  text-align: center; }
            .col-name   { width: 18%; }
            .col-club   { width: 14%; }
            .col-style  { width: 14%; }
            .col-judge  { width: auto; text-align: center; }
            .col-avg    { width: 64px; text-align: center; font-weight: 600; }
            .col-final  { width: 64px; text-align: center; font-weight: 700; }

            /* «Липкие» колонки № и спортсмена — видно при горизонтальном скролле */
            .scores-table .col-num,
            .scores-table .col-name {
                position: sticky;
                background: #fff;
            }
            .scores-table .col-num  { left: 0; z-index: 12; }
            .scores-table .col-name { left: 36px; z-index: 11; }
            .scores-table thead .col-num,
            .scores-table thead .col-name {
                z-index: 25;
                background: #f3f4f6;
            }
            .scores-table tbody tr:hover .col-num,
            .scores-table tbody tr:hover .col-name { background: #f9fafb; }
            .dark .scores-table .col-num,
            .dark .scores-table .col-name { background: #111827; }
            .dark .scores-table thead .col-num,
            .dark .scores-table thead .col-name { background: #1f2937; }
            .dark .scores-table tbody tr:hover .col-num,
            .dark .scores-table tbody tr:hover .col-name { background: #111827; }

            .judge-name {
                display: block;
                white-space: normal;
                word-break: break-word;
                line-height: 1.15;
            }

            .cell-warn   { background: #fef3c7; color: #92400e; }
            .cell-danger { background: #fee2e2; color: #991b1b; font-weight: 600; }
            .dark .cell-warn   { background: rgba(120, 53, 15, 0.4); color: #fde68a; }
            .dark .cell-danger { background: rgba(127, 29, 29, 0.4); color: #fecaca; }

            .cell-min { background: #dbeafe !important; color: #1e40af !important; font-weight: 600; }
            .cell-max { background: #d1fae5 !important; color: #065f46 !important; font-weight: 600; }
            .cell-na  { background: #e5e7eb; color: #6b7280; }
            .dark .cell-min { background: rgba(30, 58, 138, 0.5) !important; color: #bfdbfe !important; }
            .dark .cell-max { background: rgba(6, 95, 70, 0.5) !important; color: #a7f3d0 !important; }
            .dark .cell-na  { background: rgba(75, 85, 99, 0.5); color: #9ca3af; }

            .col-group {
                text-align: center;
                background: #e5e7eb;
                font-weight: 700;
            }
            .dark .col-group { background: #374151; }

            .scores-table tbody tr:hover { background: #f9fafb; }
            .dark .scores-table tbody tr:hover { background: #111827; }

            /* Мобильные устройства: таблица скроллится по горизонтали,
               кнопки — во всю ширину, лишний внутренний скролл отключён */
            @media (max-width: 767px) {
                .scores-scroll {
                    max-height: none;
                }
                .scores-table {
                    font-size: 11px;
                }
                .summary-actions > * {
                    width: 100%;
                    justify-content: center;
                }
            }

            .print-header { display: none; }

            @media print {
                @page {
                    size: A4 landscape;
                    margin: 10mm 10mm 12mm 10mm;
                }

                body * { visibility: hidden; }
                .fi-sidebar,
                .fi-topbar,
                .fi-header,
                .fi-page-header,
                .fi-breadcrumbs,
                aside,
                nav,
                .no-print {
                    display: none !important;
                }

                .fi-main, .fi-page, .fi-main-ctn,
                .fi-page > *, .fi-main > * {
                    visibility: visible;
                    box-shadow: none !important;
                    background: #fff !important;
                }
                .fi-main, .fi-page, .fi-main-ctn {
                    margin: 0 !important;
                    padding: 0 !important;
                    max-width: 100% !important;
                    width: 100% !important;
                }

                * {
                    -webkit-print-color-adjust: exact !important;
                    print-color-adjust: exact !important;
                    color-adjust: exact !important;
                }

                .print-header {
                    display: block !important;
                    visibility: visible !important;
                    text-align: center;
                    margin-bottom: 8mm;
                    font-family: "Segoe UI", Arial, sans-serif;
                }
                .print-header h1 {
                    font-size: 16pt;
                    margin: 0 0 3mm 0;
                    color: #1a3a6c;
                    border-bottom: 2px solid #1a3a6c;
                    padding-bottom: 2mm;
                    font-weight: 700;
                }
                .print-header .print-subtitle {
                    font-size: 11pt;
                    color: #444;
                    font-weight: 500;
                }
                .print-header * { visibility: visible !important; }

                .scores-scroll {
                    max-height: none !important;
                    overflow: visible !important;
                    border-radius: 0 !important;
                    box-shadow: none !important;
                    background: #fff !important;
                }
                .scores-scroll * { visibility: visible; }

                .scores-table {
                    font-size: 8pt !important;
                    min-width: 0 !important;
                    page-break-inside: auto;
                }
                .scores-table th,
                .scores-table td {
                    padding: 3px 3px !important;
                    border: 0.5pt solid #999 !important;
                }
                .scores-table thead {
                    display: table-header-group;
                }
                .scores-table thead th {
                    position: static !important;
                    background: #e5e7eb !important;
                    color: #000 !important;
                    box-shadow: none !important;
                    font-size: 7.5pt !important;
                }
                .scores-table tbody tr {
                    page-break-inside: avoid;
                }
                .judge-name {
                    font-size: 7pt !important;
                }

                .scores-table .col-num,
                .scores-table .col-name {
                    position: static !important;
                    background: none !important;
                }

                .col-num   { width: 4%; }
                .col-name  { width: 16%; }
                .col-club  { width: 12%; }
                .col-style { width: 12%; }
                .col-avg, .col-final { width: 5%; }

                .cell-warn   { background: #fef3c7 !important; color: #92400e !important; }
                .cell-danger { background: #fee2e2 !important; color: #991b1b !important; font-weight: 600 !important; }
                .cell-min    { background: #dbeafe !important; color: #1e40af !important; font-weight: 600 !important; }
                .cell-max    { background: #d1fae5 !important; color: #065f46 !important; font-weight: 600 !important; }

                .legend-print {
                    visibility: visible !important;
                    margin-top: 4mm !important;
                    font-size: 8pt !important;
                    color: #333 !important;
                    page-break-inside: avoid;
                }
                .legend-print * { visibility: visible !important; }
                .legend-swatch {
                    display: inline-block;
                    width: 10px;
                    height: 10px;
                    margin-right: 3px;
                    vertical-align: middle;
                    border: 0.5pt solid #999;
                }
            }
        </style>

        <div class="scores-scroll bg-white dark:bg-gray-900 shadow">
            <table class="scores-table">
                <thead>
                    @if($isAb)
                        <tr>
                            <th class="col-num" rowspan="2">№</th>
                            <th class="col-name" rowspan="2" style="text-align:left">Спортсмен / Пара</th>
                            <th class="col-club" rowspan="2" style="text-align:left">Клуб</th>
                            <th class="col-style" rowspan="2" style="text-align:left">Стиль</th>
                            @foreach($matrix['groupsOrder'] as $g)
                                @if(count($matrix['judgesByGroup'][$g]) > 0)
                                    <th class="col-group" colspan="{{ count($matrix['judgesByGroup'][$g]) + 1 }}">{{ $groupLabels[$g] }}</th>
                                @endif
                            @endforeach
                            <th class="col-avg" rowspan="2">Расчёт (A+B)</th>
                            <th class="col-final" rowspan="2">Итог</th>
                        </tr>
                        <tr>
                            @foreach($matrix['groupsOrder'] as $g)
                                @foreach($matrix['judgesByGroup'][$g] as $judge)
                                    <th class="col-judge">
                                        <span class="judge-name">{{ $judge->name }}</span>
                                    </th>
                                @endforeach
                                @if(count($matrix['judgesByGroup'][$g]) > 0)
                                    <th class="col-avg">Ср. {{ $g }}</th>
                                @endif
                            @endforeach
                        </tr>
                    @else
                        <tr>
                            <th class="col-num">№</th>
                            <th class="col-name" style="text-align:left">Спортсмен / Пара</th>
                            <th class="col-club" style="text-align:left">Клуб</th>
                            <th class="col-style" style="text-align:left">Стиль</th>
                            @foreach($matrix['judgesByGroup'][\App\Support\ScoresSummaryMatrix::GROUP_NONE] as $judge)
                                <th class="col-judge">
                                    <span class="judge-name">{{ $judge->name }}</span>
                                </th>
                            @endforeach
                            <th class="col-avg">Среднее</th>
                            <th class="col-final">Итог</th>
                        </tr>
                    @endif
                </thead>
                <tbody>
                    @foreach($matrix['rows'] as $i => $row)
                        @php
                            $reg = $row['reg'];
                            $athleteName = $reg->athlete?->full_name ?? $reg->athlete?->name ?? '—';
                            if ($reg->partner) {
                                $partnerName = $reg->partner->full_name ?? $reg->partner->name ?? '';
                                if ($partnerName) $athleteName .= ' / ' . $partnerName;
                            }
                            $club = $reg->athlete?->club?->name ?? '—';
                        @endphp
                        <tr>
                            <td class="col-num">{{ $i + 1 }}</td>
                            <td class="col-name">{{ $athleteName }}</td>
                            <td class="col-club">{{ $club }}</td>
                            <td class="col-style">{{ $reg->style?->name ?? '—' }}</td>
                            @foreach($matrix['groupsOrder'] as $g)
                                @foreach($matrix['judgesByGroup'][$g] as $judge)
                                    @php
                                        $val = $row['cells'][$judge->id] ?? null;
                                        $isCounted = $row['counted'][$judge->id] ?? false;
                                        $gStats = $row['groups'][$g];
                                        $cls = '';
                                        if ($val !== null && ! $isCounted) {
                                            $cls = 'cell-na';
                                        } elseif ($val !== null) {
                                            $level = \App\Filament\Pages\ScoresSummary::deviationLevel($val, $gStats['avg']);
                                            $cls = match($level) {
                                                'danger' => 'cell-danger',
                                                'warn'   => 'cell-warn',
                                                default  => '',
                                            };
                                            if ($judge->id === $gStats['minJudgeId']) {
                                                $cls = 'cell-min';
                                            } elseif ($judge->id === $gStats['maxJudgeId']) {
                                                $cls = 'cell-max';
                                            }
                                        }
                                    @endphp
                                    <td class="col-judge {{ $cls }}">
                                        {{ $val !== null ? number_format($val, 2, '.', '') : '—' }}
                                    </td>
                                @endforeach
                                @if(count($matrix['judgesByGroup'][$g]) > 0)
                                    <td class="col-avg">
                                        {{ $row['groups'][$g]['avg'] !== null ? number_format($row['groups'][$g]['avg'], 3, '.', '') : '—' }}
                                    </td>
                                @endif
                            @endforeach
                            @if($isAb)
                                <td class="col-avg">
                                    {{ $row['avg'] !== null ? number_format($row['avg'], 3, '.', '') : '—' }}
                                </td>
                            @endif
                            <td class="col-final">
                                {{ $row['final'] !== null ? number_format($row['final'], 3, '.', '') : '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-3 text-xs text-gray-500 flex flex-wrap gap-4 legend-print">
            @if(! $isAb)
                <span><span class="inline-block w-3 h-3 align-middle mr-1 legend-swatch" style="background:#dbeafe"></span> минимум (отброшен)</span>
                <span><span class="inline-block w-3 h-3 align-middle mr-1 legend-swatch" style="background:#d1fae5"></span> максимум (отброшен)</span>
            @endif
            <span><span class="inline-block w-3 h-3 align-middle mr-1 legend-swatch" style="background:#fef3c7"></span> отклонение ≥ 0.10</span>
            <span><span class="inline-block w-3 h-3 align-middle mr-1 legend-swatch" style="background:#fee2e2"></span> отклонение ≥ 0.20</span>
            @if($isAb)
                <span><span class="inline-block w-3 h-3 align-middle mr-1 legend-swatch" style="background:#e5e7eb"></span> не учтена (выставлена не в своей функции)</span>
                <span>Итог A/B = «Ср. A» + «Ср. B»; каждая панель — среднее по всем оценкам панели (без отбрасывания).</span>
            @endif
        </div>
    @endif
</x-filament-panels::page>