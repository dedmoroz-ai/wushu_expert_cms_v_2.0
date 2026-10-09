{{-- Правило R-6.13: подробности записи журнала судейства.
     Части строк с ignored — коды сбавок, нажатые только одним судьёй панели A
     (R-3.13–R-3.14): они не засчитаны в вычет и выделяются цветом. --}}
<div class="space-y-1 text-sm">
    @if($record->reason)
        <div class="font-semibold mb-2">{{ $record->reason }}</div>
    @endif

    @foreach($lines as $line)
        <div style="white-space: pre-wrap; font-family: ui-monospace, monospace;">@foreach($line as $part)<span @if($part['ignored']) style="color: var(--danger-600); background: rgba(240, 68, 56, 0.10); border-radius: 0.25rem; padding: 0 0.125rem; text-decoration: line-through;" title="Код нажат только одним судьёй — не учтён в вычете" @endif>{{ $part['text'] }}</span>@endforeach</div>
    @endforeach

    @if(collect($lines)->flatten(1)->contains(fn ($part) => $part['ignored']))
        <div class="mt-3 text-xs text-gray-600 dark:text-gray-400">
            <span style="color: var(--danger-600); background: rgba(240, 68, 56, 0.10); border-radius: 0.25rem; padding: 0 0.25rem; text-decoration: line-through;">код</span>
            — сбавка/код, замеченные только одним судьёй: не учтены в вычете (R-3.13–R-3.14).
        </div>
    @endif
</div>
