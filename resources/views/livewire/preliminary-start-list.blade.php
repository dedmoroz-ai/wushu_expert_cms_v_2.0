{{-- Решение заказчика (05.10): «Предварительный стартовый протокол» — HTML-страница
     в стиле публичной страницы результатов (PublicResults): заявки актуального
     соревнования, не финальные результаты. Кнопка «Печать» выводит страницу через
     браузер; данные обновляются автоматически (wire:poll). --}}
<div wire:poll.30s class="min-h-screen bg-gray-50 py-4 sm:py-8">
    <div class="max-w-7xl mx-auto px-2 sm:px-4 lg:px-8">

        {{-- ШАПКА --}}
        <div class="bg-white shadow-lg rounded-lg mb-4 sm:mb-6 p-4 sm:p-6">
            @php
                $logoUrl = $competition->avatarUrl();
                if (! $logoUrl && $competition->federation) {
                    $fPath = $competition->federation->logo_path ?? $competition->federation->logo ?? null;
                    if ($fPath) {
                        $logoUrl = asset('storage/' . $fPath);
                    }
                }

                $dateStr = '';
                if ($competition->start_date) {
                    $dateStr = \Carbon\Carbon::parse($competition->start_date)->translatedFormat('j F Y');
                    if ($competition->end_date && $competition->end_date != $competition->start_date) {
                        $dateStr .= ' - ' . \Carbon\Carbon::parse($competition->end_date)->translatedFormat('j F Y');
                    }
                }

                $addressStr = trim(($competition->city ?? '') . (($competition->city && $competition->address) ? ', ' : '') . ($competition->address ?? ''));
            @endphp

            <div class="flex flex-col sm:flex-row items-start sm:items-center gap-4 sm:gap-6">
                @if($logoUrl)
                    <div class="flex-shrink-0 mx-auto sm:mx-0">
                        <img src="{{ $logoUrl }}" alt="Logo" class="h-16 w-16 sm:h-24 sm:w-24 object-contain rounded-full">
                    </div>
                @endif

                <div class="flex-1 text-center sm:text-left">
                    <h1 class="text-xl sm:text-2xl lg:text-3xl font-bold text-gray-900 mb-2 uppercase">{{ $competition->name }}</h1>

                    @if($competition->federation)
                        <p class="text-sm sm:text-base lg:text-lg text-gray-700 font-semibold">{{ $competition->federation->name }}</p>
                    @endif

                    @if($dateStr || $addressStr)
                        <p class="text-xs sm:text-sm text-gray-700 mt-2">
                            @if($dateStr){{ $dateStr }}@endif
                            @if($dateStr && $addressStr) | @endif
                            @if($addressStr){{ $addressStr }}@endif
                        </p>
                    @endif
                </div>
            </div>

            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 mt-6 pt-4 border-t border-gray-200">
                <div>
                    <h2 class="text-lg sm:text-xl font-bold text-gray-900 uppercase">
                        Предварительный стартовый протокол (по поданным заявкам)
                    </h2>
                    <p class="text-xs sm:text-sm text-gray-500 mt-1">
                        Данные от {{ $generatedAt }} — не финальные результаты.
                    </p>
                </div>

                <button
                    type="button"
                    onclick="window.print()"
                    class="print:hidden px-5 py-2.5 rounded-lg bg-[#E6E9E8] text-[#272727] text-sm font-semibold uppercase tracking-wider hover:bg-[#d8dddc]"
                >
                    Печать
                </button>
            </div>
        </div>

        {{-- СВОДКА: ЗАЯВКИ ПО ВОЗРАСТНЫМ ГРУППАМ И ПОЛУ --}}
        <div class="bg-white shadow-lg rounded-lg overflow-hidden mb-4 sm:mb-6">
            <div class="px-4 sm:px-6 py-3 bg-gray-800 text-white font-bold uppercase text-sm sm:text-base">
                Заявки по возрастным группам
            </div>

            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="px-3 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">Возрастная группа</th>
                        <th class="px-3 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">Пол</th>
                        <th class="px-3 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">Заявок</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($counts as $c)
                        <tr>
                            <td class="px-3 sm:px-6 py-2 sm:py-3 text-sm text-gray-900">{{ $c['age_group'] }}</td>
                            <td class="px-3 sm:px-6 py-2 sm:py-3 text-sm text-gray-900">
                                {{ ['male' => 'Муж.', 'female' => 'Жен.'][$c['gender']] ?? ($c['gender'] ?: '—') }}
                            </td>
                            <td class="px-3 sm:px-6 py-2 sm:py-3 text-sm font-bold text-gray-900">{{ $c['count'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-3 sm:px-6 py-3 text-sm text-gray-500">Заявок пока нет.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- НОМИНАЦИИ (ЗАЯВКИ В ИСХОДНОМ ПОРЯДКЕ — ПО sort_order ЗАЯВКИ) --}}
        @if($grouped->count() > 0)
            @foreach($grouped as $groupName => $items)
                <div class="bg-white shadow-lg rounded-lg overflow-hidden mb-4 sm:mb-6">
                    <div class="px-4 sm:px-6 py-3 bg-gray-800 text-white font-bold uppercase text-sm sm:text-base">
                        {{ $groupName }}
                    </div>

                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="px-3 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-700 uppercase tracking-wider" width="60">№</th>
                                <th class="px-3 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">ФИО участника</th>
                                <th class="px-3 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">Команда</th>
                                <th class="px-3 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-700 uppercase tracking-wider" width="90">Год рожд.</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach($items as $reg)
                                <tr>
                                    <td class="px-3 sm:px-6 py-2 sm:py-3 text-sm font-bold text-gray-900">{{ $reg->sort_order }}</td>
                                    <td class="px-3 sm:px-6 py-2 sm:py-3 text-sm text-gray-900">
                                        <b>{{ $reg->athlete->surname }} {{ $reg->athlete->name }}</b>
                                        @if($reg->partner)
                                            <br><b>{{ $reg->partner->surname }} {{ $reg->partner->name }}</b>
                                        @endif
                                    </td>
                                    <td class="px-3 sm:px-6 py-2 sm:py-3 text-sm text-gray-700">{{ $reg->athlete->club?->name ?? '—' }}</td>
                                    <td class="px-3 sm:px-6 py-2 sm:py-3 text-sm text-gray-700">{{ $reg->athlete->birth_date?->format('Y') ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
        @else
            <div class="bg-white shadow-lg rounded-lg p-6 text-center text-gray-500">
                Заявок на соревнование пока нет.
            </div>
        @endif

    </div>
</div>

