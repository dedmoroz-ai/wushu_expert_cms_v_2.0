<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Титульный лист</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; }

        @page {
            margin: 30px 30px 60px 30px;
        }

        /* Номер страницы внизу — всегда "- 1 -" */
        .page-number {
            position: fixed;
            bottom: -40px;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 11px;
            color: #555;
        }
        .page-number:before {
            content: "- 1 -";
        }

        /* Подписи и печать снизу */
        footer {
            position: fixed;
            bottom: 20px;
            left: 0;
            right: 0;
            height: 140px;
        }
        .footer-tbl { width: 100%; border: none; border-collapse: collapse; }
        .footer-tbl td { border: none; vertical-align: bottom; height: 110px; position: relative; }
        .sign-box { width: 40%; text-align: center; position: relative; }
        .stamp-box { width: 20%; text-align: center; position: relative; }
        .role { font-size: 14px; font-weight: bold; margin-bottom: 35px; }
        .line { border-top: 1px solid black; width: 90%; margin: 5px auto 0; }
        .name { font-size: 14px; font-style: italic; margin-top: 3px; }
        .sign-img { position: absolute; bottom: 5px; left: 50%; transform: translateX(-50%); max-height: 60px; z-index: 1; }
        .stamp-img { position: absolute; bottom: -5px; left: 50%; transform: translateX(-50%); max-width: 190px; opacity: 0.9; z-index: 2; }

        /* Титульный блок */
        .title-page { text-align: center; padding-top: 10px; }
        .title-organizer { font-size: 18px; font-weight: bold; text-transform: uppercase; margin-bottom: 20px; line-height: 1.3; }
        .title-logo-wrap { margin: 15px 0; }
        .title-logo { max-width: 200px; max-height: 200px; }
        .title-comp-name { font-size: 20px; font-weight: 900; text-transform: uppercase; margin: 30px 0 20px 0; line-height: 1.3; }
        .title-protocol { font-size: 24px; font-weight: bold; text-transform: uppercase; text-decoration: underline; margin: 25px 0; }
        .title-date-addr { font-size: 15px; font-weight: bold; margin: 25px 0 30px 0; line-height: 1.6; }
        .title-stats { font-size: 16px; margin: 30px 0; line-height: 2; }
        .title-stat-row b { font-size: 18px; }
    </style>
</head>
<body>

    <div class="page-number"></div>

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

    <div class="title-page">
        <div class="title-organizer">{{ $organizerName }}</div>

        @if($logoBase64)
            <div class="title-logo-wrap">
                <img src="{{ $logoBase64 }}" class="title-logo">
            </div>
        @endif

        <div class="title-comp-name">{{ $competition->name }}</div>

        <div class="title-protocol">ИТОГОВЫЙ ПРОТОКОЛ</div>

        <div class="title-date-addr">
            {{ $formattedDate }}<br>
            {{ $address }}
        </div>

        <div class="title-stats">
            <div class="title-stat-row">Всего участников: <b>{{ $totalAthletes }}</b></div>
            <div class="title-stat-row">Всего команд: <b>{{ $totalClubs }}</b></div>
        </div>
    </div>

</body>
</html>