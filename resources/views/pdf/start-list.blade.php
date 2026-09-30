<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Стартовый протокол</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; }

        /* === НАСТРОЙКИ СТРАНИЦЫ === */
        @page {
            /* 
               Верх: 180px (под шапку)
               Низ: 200px (для подвала с печатями)
            */
            margin: 180px 30px 200px 30px; 
        }

        /* === ШАПКА === */
        header {
            position: fixed;
            top: -160px; 
            left: 0;
            right: 0;
            height: 145px; 
        }

        /* === ФУТЕР === */
        footer {
            position: fixed; 
            bottom: -195px; 
            left: 0px; 
            right: 0px;
            height: 140px; 
        }

        /* === НОМЕР СТРАНИЦЫ === */
        .page-number {
            position: fixed;
            bottom: -180px;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 10px;
            color: #555;
        }
        .page-number:before {
            content: "-- " counter(page) " --";
        }

        /* === ТВОИ СТИЛИ === */
        .header-table { width: 100%; border-collapse: collapse; border: none; }
        .header-logo-cell { width: 130px; vertical-align: top; text-align: left; }
        .header-logo-cell img { max-width: 130px; max-height: 130px; }
        .header-info-cell { vertical-align: top; text-align: center; }

        .organizer-name {
            font-size: 16px;
            text-transform: uppercase;
            font-weight: bold;
            color: #000000;
            margin-bottom: 10px;
        }
        .comp-name {
            font-size: 16px;
            font-weight: 900;
            text-transform: uppercase;
            margin-bottom: 5px;
            line-height: 1.2;
            color: #000;
        }
        .protocol-title {
            font-size: 18px;
            font-weight: bold;
            text-transform: uppercase;
            text-decoration: underline;
            margin-bottom: 10px;
            margin-top: 5px;
        }
        .date-addr {
            font-size: 12px;
            padding: 5px;
            display: inline-block;
            width: 90%;
            font-weight: bold;
        }

        /* === ТАБЛИЦА === */
        .group-wrap {
            page-break-inside: avoid;
            margin-bottom: 15px;
        }
        .data-tbl { width: 100%; border-collapse: collapse; margin-bottom: 0; }
        .data-tbl th, .data-tbl td { border: 1px solid black; padding: 4px; text-align: center; vertical-align: middle; }
        .data-tbl th { background-color: #f0f0f0; }
        .text-left { text-align: left; }
        
        .group-head { 
            background: #444444; 
            color: white; padding: 6px; 
            font-weight: bold; font-size: 12px; 
            text-align: center; border: 1px solid black; border-bottom: none; 
        }

        /* === ПОДВАЛ (Подписи) === */
        .footer-tbl { width: 100%; border: none; }
        .footer-tbl td { border: none; vertical-align: bottom; height: 90px; position: relative; }
        .sign-box { width: 40%; text-align: center; position: relative; }
        .stamp-box { width: 20%; text-align: center; position: relative; }
        .role { font-size: 14px; font-weight: bold; margin-bottom: 35px; }
        .line { border-top: 1px solid black; width: 90%; margin: 5px auto 0; }
        .name { font-size: 14px; font-style: italic; margin-top: 3px; }
        .sign-img { position: absolute; bottom: 5px; left: 50%; transform: translateX(-50%); max-height: 60px; z-index: 1; }
        .stamp-img { position: absolute; bottom: -5px; left: 50%; transform: translateX(-50%); max-width: 190px; opacity: 0.9; z-index: 2; }
    </style>
</head>
<body>

    {{-- 1. ШАПКА --}}
    <header>
        <table class="header-table">
            <tr>
                <td class="header-logo-cell">
                    @if($logoBase64)
                        <img src="{{ $logoBase64 }}">
                    @endif
                </td>
                <td class="header-info-cell">
                    <div class="organizer-name">{{ $organizerName }}</div>
                    <div class="comp-name">{{ $competition->name }}</div>
                    <div class="protocol-title">СТАРТОВЫЙ ПРОТОКОЛ</div>
                    <div class="date-addr">{{ $formattedDate }} | {{ $address }}</div>
                </td>
            </tr>
        </table>
    </header>

    {{-- 2. ПОДВАЛ (Подписи) --}}
    <footer>
        <table class="footer-tbl">
            <tr>
                <td class="sign-box">
                    <div class="role">Главный судья</div>
                    @if($judgeSignBase64)
                        <img class="sign-img" src="{{ $judgeSignBase64 }}">
                    @endif
                    <div class="line"></div>
                    <div class="name">{{ $judgeName }}</div>
                </td>
                <td class="stamp-box">
                    @if($stampBase64)
                        <img class="stamp-img" src="{{ $stampBase64 }}">
                    @endif
                </td>
                <td class="sign-box">
                    <div class="role">Главный секретарь</div>
                    @if($secSignBase64)
                        <img class="sign-img" src="{{ $secSignBase64 }}">
                    @endif
                    <div class="line"></div>
                    <div class="name">{{ $secName }}</div>
                </td>
            </tr>
        </table>
    </footer>

    {{-- 3. НОМЕР СТРАНИЦЫ --}}
    <div class="page-number"></div>

    {{-- 4. КОНТЕНТ (Таблицы) --}}
    @foreach($grouped as $groupName => $items)
        <div class="group-wrap">
            <div class="group-head">{{ $groupName }}</div>
            <table class="data-tbl">
                <thead>
                    <tr>
                        <th width="30">№</th>
                        <th class="text-left">ФИО Участника</th>
                        <th class="text-left">Команда</th>
                        <th width="60">Оценка</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($items as $reg)
                    <tr>
                        <td>{{ $reg->sort_order }}</td>
                        <td class="text-left">
                            <b>{{ $reg->athlete->surname }} {{ $reg->athlete->name }}</b>
                            @if($reg->partner)
                                <br>
                                <b>{{ $reg->partner->surname }} {{ $reg->partner->name }}</b>
                            @endif
                        </td>
                        <td class="text-left">{{ $reg->athlete->club->name ?? '' }}</td>
                        <td></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach

</body>
</html>
