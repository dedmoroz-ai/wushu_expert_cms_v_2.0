{{-- Правило R-6.13: подробности записи журнала судейства --}}
<div class="space-y-1 text-sm">
    @if($record->reason)
        <div class="font-semibold mb-2">{{ $record->reason }}</div>
    @endif

    @foreach($lines as $line)
        <div style="white-space: pre-wrap; font-family: ui-monospace, monospace;">{{ $line }}</div>
    @endforeach
</div>
