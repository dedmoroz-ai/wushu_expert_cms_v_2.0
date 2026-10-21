{{-- Титульный лист --}}
<div class="title-page">
    <div class="title-organizer">{{ $organizerName }}</div>

    @if($logoBase64)
        <div class="title-logo-wrap">
            <img src="{{ $logoBase64 }}" class="title-logo">
        </div>
    @endif

    @php
        // Замечание заказчика (21.10): название соревнования на титуле —
        // строго в одну строку; при длинном названии кегль масштабируется.
        $compNameLen = max(1, mb_strlen(trim((string) $competition->name)));
        $compNameFont = 20;
        if ($compNameLen > 45) {
            $compNameFont = min(20, max(6, floor((1000 / $compNameLen) * 2) / 2));
        }
    @endphp
    <div class="title-comp-name" style="font-size: {{ $compNameFont }}px; white-space: nowrap;">{{ $competition->name }}</div>

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

<div style="page-break-after: always;"></div>