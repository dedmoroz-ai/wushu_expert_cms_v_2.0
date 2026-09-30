{{-- Список администрации соревнования --}}
<div class="group-wrap">
    <div class="group-head">СОСТАВ СУДЕЙСКОЙ КОЛЛЕГИИ</div>
    <table class="data-tbl">
        <thead>
            <tr>
                <th width="40">№</th>
                <th class="text-left">Должность</th>
                <th class="text-left">ФИО</th>
                <th class="text-left">Город</th>
                <th width="80">Категория</th>
            </tr>
        </thead>
        <tbody>
            @php $n = 1; @endphp

            {{-- Главный судья --}}
            @if($chiefJudgeName)
                <tr>
                    <td><b>{{ $n++ }}</b></td>
                    <td class="text-left"><b>Главный судья</b></td>
                    <td class="text-left">{{ $chiefJudgeName }}</td>
                    <td class="text-left">—</td>
                    <td>—</td>
                </tr>
            @endif

            {{-- Главный секретарь --}}
            @if($chiefSecretaryName)
                <tr>
                    <td><b>{{ $n++ }}</b></td>
                    <td class="text-left"><b>Главный секретарь</b></td>
                    <td class="text-left">{{ $chiefSecretaryName }}</td>
                    <td class="text-left">—</td>
                    <td>—</td>
                </tr>
            @endif

            {{-- Старшие судьи --}}
            @foreach($headJudges as $judge)
                <tr>
                    <td><b>{{ $n++ }}</b></td>
                    <td class="text-left">Старший судья</td>
                    <td class="text-left">{{ $judge->name }}</td>
                    <td class="text-left">{{ $judge->club->city ?? '—' }}</td>
                    <td>{{ $judge->judge_category ?? '—' }}</td>
                </tr>
            @endforeach

            {{-- Линейные судьи --}}
            @foreach($lineJudges as $judge)
                <tr>
                    <td><b>{{ $n++ }}</b></td>
                    <td class="text-left">Судья на ковре</td>
                    <td class="text-left">{{ $judge->name }}</td>
                    <td class="text-left">{{ $judge->club->city ?? '—' }}</td>
                    <td>{{ $judge->judge_category ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<div style="page-break-after: always;"></div>