<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Печать дипломов</title>
    <style>
        body {
            font-family: 'DejaVu Sans', sans-serif;
            margin: 0;
            padding: 0;
            color: #000;
        }
        .page {
            width: 100%;
            height: 100%;
            position: relative; 
            page-break-after: always;
        }
        .page:last-child {
            page-break-after: avoid;
        }
        .absolute-block {
            position: absolute;
            width: calc(80% - 50px); /* 80% ширины минус 50px */
            left: 50%;
            transform: translateX(-50%); /* Центрируем блок по горизонтали */
            text-align: center; 
        }

        /* --- Стили блоков (ОПУСТИЛИ НА 30px то, что в красной рамке) --- */

        .comp-name {
            top: 320px; /* Было 290 */
            font-size: 16px;
            font-weight: bold;
            text-transform: uppercase;
            line-height: 1.25;
        }

        .awarded-text {
            top: 460px; /* Было 430 */
            font-size: 20px;
            font-style: italic;
            font-family: 'DejaVu Serif', serif;
        }

        .athlete-name {
            top: 495px; /* Было 500 - чуть ближе кверху, чтобы увеличить отступ до строки места */
            font-size: 28px;
            font-weight: bold;
            font-style: italic; 
            font-family: 'DejaVu Serif', serif; 
        }
        
        .place-block {
            top: 565px; /* Было 540 - увеличили отступ после имени спортсмена */
            font-size: 20px; /* Было 18 - слегка увеличили размер текста "за ... место" */
        }
        .place-number {
            font-size: 26px; /* Было 22 - сделали номер места более крупным */
            font-weight: bold;
        }

        .discipline-block {
            top: 610px; /* Было 580 */
            font-size: 18px;
            font-style: italic;
            font-family: 'DejaVu Serif', serif;
        }

        .age-block {
            top: 650px; /* Было 620 */
            font-size: 18px;
            font-style: italic;
            font-family: 'DejaVu Serif', serif;
        }

        /* --- Подвал (оставили по вертикали, но вписали в общую ширину контента) --- */
        .footer-stamps {
            top: 730px; 
        }
        .stamp-table { 
            width: 100%; 
            border-collapse: collapse; 
        }
        .td-left, .td-right {
            width: 35%;
            vertical-align: bottom;
            text-align: center;
        }
        .td-center {
            width: 30%;
            vertical-align: middle;
            text-align: center;
        }
        
        .role-title {
            font-weight: bold;
            font-size: 14px;
            margin-bottom: 5px;
        }

        .sign-container {
            height: 60px; 
            display: flex;
            align-items: flex-end; 
            justify-content: center;
            margin-bottom: -15px; 
        }
        .sign-img {
            max-height: 60px;
            max-width: 150px;
        }

        .name-line {
            border-top: 1px solid #000;
            padding-top: 5px;
            font-size: 12px;
            font-style: italic;
        }

        .seal-img {
            width: 40mm;
            height: 40mm;
            opacity: 0.9;
        }

        .city-date {
            top: 890px; 
            font-size: 14px;
            font-style: italic;
        }
    </style>
</head>
<body>

@php
    // === ПОДГОТОВКА ПУТЕЙ К КАРТИНКАМ ===
    
    $judgeSignPath = null;
    if(!empty($competition->chief_judge_signature) && file_exists(public_path('storage/' . $competition->chief_judge_signature))) {
        $judgeSignPath = public_path('storage/' . $competition->chief_judge_signature);
    }

    $secSignPath = null;
    if(!empty($competition->chief_secretary_signature) && file_exists(public_path('storage/' . $competition->chief_secretary_signature))) {
        $secSignPath = public_path('storage/' . $competition->chief_secretary_signature);
    }

    $stampPath = \App\Support\StampImage::toBase64($competition->organization_stamp);
@endphp

@foreach($winners as $item)
    <div class="page">
        
        <div class="absolute-block comp-name">
            {{ $competition->name }}
        </div>

        <div class="absolute-block awarded-text">
            награждается
        </div>

        <div class="absolute-block athlete-name">
            {{ $item['name'] }}
        </div>

        <div class="absolute-block place-block">
            за <span class="place-number">
                @if($item['place'] == 1) I
                @elseif($item['place'] == 2) II
                @elseif($item['place'] == 3) III
                @else {{ $item['place'] }}
                @endif
            </span> место
        </div>

        <div class="absolute-block discipline-block">
            в дисциплине {{ $style->name }}
        </div>

        <div class="absolute-block age-block">
            возрастная категория {{ $item['registration']->ageGroup->name ?? '' }} ({{ $item['registration']->ageGroup->min_age ?? 0 }}-{{ $item['registration']->ageGroup->max_age ?? 0 }} лет)
        </div>

        <!-- === ПОДВАЛ === -->
        <div class="absolute-block footer-stamps">
            <table class="stamp-table">
                <tr>
                    <!-- СУДЬЯ -->
                    <td class="td-left">
                        <div class="role-title">Главный судья</div>
                        
                        <div class="sign-container">
                            @if($judgeSignPath)
                                <img src="{{ $judgeSignPath }}" class="sign-img">
                            @else
                                <div style="height: 40px;"></div> 
                            @endif
                        </div>

                        <div class="name-line">
                            {{ $competition->chief_judge_name ?? '_____________' }}
                        </div>
                    </td>

                    <!-- ПЕЧАТЬ -->
                    <td class="td-center">
                        @if($stampPath)
                            <img src="{{ $stampPath }}" class="seal-img">
                        @endif
                    </td>

                    <!-- СЕКРЕТАРЬ -->
                    <td class="td-right">
                        <div class="role-title">Главный секретарь</div>
                        
                        <div class="sign-container">
                            @if($secSignPath)
                                <img src="{{ $secSignPath }}" class="sign-img">
                            @else
                                <div style="height: 40px;"></div>
                            @endif
                        </div>

                        <div class="name-line">
                            {{ $competition->chief_secretary_name ?? '_____________' }}
                        </div>
                    </td>
                </tr>
            </table>
        </div>
        <!-- ============== -->

        <div class="absolute-block city-date">
            Город {{ $competition->city }} <br>
            {{ $date }} г.
        </div>

    </div>
@endforeach

</body>
</html>
