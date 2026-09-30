{{-- Титульный лист --}}
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

<div style="page-break-after: always;"></div>