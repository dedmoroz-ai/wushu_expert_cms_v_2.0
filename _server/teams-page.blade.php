{{-- Список команд (со стандартной шапкой и футером) --}}
<div class="group-wrap">
    <div class="group-head">СПИСОК КОМАНД-УЧАСТНИКОВ</div>
    <table class="data-tbl">
        <thead>
            <tr>
                <th width="40">№</th>
                <th class="text-left">Город</th>
                <th class="text-left">Название клуба</th>
                <th width="100">Кол-во спортсменов</th>
            </tr>
        </thead>
        <tbody>
            @foreach($teams as $i => $team)
                <tr>
                    <td><b>{{ $i + 1 }}</b></td>
                    <td class="text-left">{{ $team['city'] ?: '—' }}</td>
                    <td class="text-left">{{ $team['name'] }}</td>
                    <td><b>{{ $team['count'] }}</b></td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3" class="text-left"><b>ИТОГО</b></td>
                <td><b>{{ $totalAthletes }}</b></td>
            </tr>
        </tfoot>
    </table>
</div>

<div style="page-break-after: always;"></div>