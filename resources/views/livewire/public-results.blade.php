<div wire:poll.2000ms class="min-h-screen bg-gray-50 py-4 sm:py-8">
    <div class="max-w-7xl mx-auto px-2 sm:px-4 lg:px-8">
        @if($competition)
            {{-- ШАПКА --}}
            <div class="bg-white shadow-lg rounded-lg mb-4 sm:mb-6 p-4 sm:p-6">
                <div class="flex flex-col sm:flex-row items-start sm:items-center gap-4 sm:gap-6">
                    @php
                        $logoUrl = null;
                        $path = $competition->logo_path ?? $competition->logo;
                        if (!$path && $competition->federation) {
                            $path = $competition->federation->logo_path ?? $competition->federation->logo;
                        }
                        if ($path) {
                            $logoUrl = asset('storage/' . $path);
                        }
                    @endphp
                    
                    @if($logoUrl)
                        <div class="flex-shrink-0 mx-auto sm:mx-0">
                            <img src="{{ $logoUrl }}" alt="Logo" class="h-16 w-16 sm:h-24 sm:w-24 object-contain">
                        </div>
                    @endif
                    
                    <div class="flex-1 text-center sm:text-left">
                        <h1 class="text-xl sm:text-2xl lg:text-3xl font-bold text-gray-900 mb-2 uppercase">{{ $competition->name }}</h1>
                        @if($competition->federation)
                            <p class="text-sm sm:text-base lg:text-lg text-gray-700 font-semibold">{{ $competition->federation->name }}</p>
                        @endif
                        @php
                            $dateStr = '';
                            if ($competition->start_date) {
                                $dateStr = \Carbon\Carbon::parse($competition->start_date)->translatedFormat('j F Y');
                                if ($competition->end_date && $competition->end_date != $competition->start_date) {
                                    $dateStr .= ' - ' . \Carbon\Carbon::parse($competition->end_date)->translatedFormat('j F Y');
                                }
                            }
                            $addressStr = '';
                            if ($competition->city) {
                                $addressStr = $competition->city;
                                if ($competition->address) {
                                    $addressStr .= ', ' . $competition->address;
                                }
                            } elseif ($competition->address) {
                                $addressStr = $competition->address;
                            }
                        @endphp
                        @if($dateStr || $addressStr)
                            <p class="text-xs sm:text-sm text-gray-700 mt-2">
                                @if($dateStr){{ $dateStr }}@endif
                                @if($dateStr && $addressStr) | @endif
                                @if($addressStr){{ $addressStr }}@endif
                            </p>
                        @endif
                    </div>
                </div>
            </div>
            
            {{-- ГРУППЫ РЕЗУЛЬТАТОВ --}}
            @if($grouped->count() > 0)
                @foreach($grouped as $groupName => $items)
                    <div class="bg-white shadow-lg rounded-lg overflow-hidden mb-4 sm:mb-6">
                        {{-- Заголовок группы --}}
                        <div class="px-3 sm:px-6 py-2 sm:py-3 bg-gray-800">
                            <h3 class="text-sm sm:text-base lg:text-lg font-bold text-white uppercase break-words">{!! $groupName !!}</h3>
                        </div>
                        
                        {{-- Таблица результатов группы (адаптивная) --}}
                        <div class="overflow-x-auto -mx-2 sm:mx-0">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50 hidden sm:table-header-group">
                                    <tr>
                                        <th class="px-3 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">Место</th>
                                        <th class="px-3 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">ФИО участника</th>
                                        <th class="px-3 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">Команда</th>
                                        <th class="px-3 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">Оценка</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    @php
                                        // Плотная нумерация мест (как в PDF): 1, 2, 2, 3, 4, 4, 5 ...
                                        $rank = 1;
                                        $prevScore = null;
                                    @endphp
                                    @foreach($items as $reg)
                                        @php
                                            $score = (float) ($reg->final_score ?? 0);

                                            if ($prevScore === null) {
                                                $rank = 1;
                                            } elseif ($score < $prevScore) {
                                                $rank++;
                                            }

                                            $prevScore = $score;

                                            $bgClass = '';
                                            if ($rank == 1) $bgClass = 'bg-yellow-100';
                                            elseif ($rank == 2) $bgClass = 'bg-gray-100';
                                            elseif ($rank == 3) $bgClass = 'bg-orange-100';
                                        @endphp
                                        {{-- Мобильная версия (карточки) --}}
                                        <tr class="sm:hidden {{ $bgClass }}">
                                            <td colspan="4" class="px-3 py-3">
                                                <div class="flex items-start justify-between mb-2">
                                                    <div class="flex items-center gap-2">
                                                        <span class="text-lg font-bold text-gray-900">{{ $rank }}</span>
                                                        <span class="text-xs font-medium text-gray-500">место</span>
                                                    </div>
                                                    <span class="text-base font-bold text-green-600">{{ $reg->formatted_score }}</span>
                                                </div>
                                                <div class="text-sm font-medium text-gray-900 mb-1">
                                                    <b>{{ $reg->athlete->surname }} {{ $reg->athlete->name }}</b>
                                                </div>
                                                @if($reg->partner)
                                                    <div class="text-sm font-medium text-gray-700 mb-1">
                                                        <b>{{ $reg->partner->surname }} {{ $reg->partner->name }}</b>
                                                    </div>
                                                @endif
                                                <div class="text-xs text-gray-600">
                                                    {{ $reg->athlete->club->city ?? ($reg->athlete->city ?? '') }}
                                                </div>
                                            </td>
                                        </tr>
                                        
                                        {{-- Десктопная версия (таблица) --}}
                                        <tr class="hidden sm:table-row hover:bg-gray-50 {{ $bgClass }}">
                                            <td class="px-3 sm:px-6 py-3 sm:py-4 whitespace-nowrap text-sm font-bold text-gray-900">
                                                {{ $rank }}
                                            </td>
                                            <td class="px-3 sm:px-6 py-3 sm:py-4">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <b>{{ $reg->athlete->surname }} {{ $reg->athlete->name }}</b>
                                                </div>
                                                @if($reg->partner)
                                                    <div class="text-sm font-medium text-gray-700 mt-1">
                                                        <b>{{ $reg->partner->surname }} {{ $reg->partner->name }}</b>
                                                    </div>
                                                @endif
                                            </td>
                                            <td class="px-3 sm:px-6 py-3 sm:py-4 whitespace-nowrap text-sm text-gray-900">
                                                {{ $reg->athlete->club->city ?? ($reg->athlete->city ?? '') }}
                                            </td>
                                            <td class="px-3 sm:px-6 py-3 sm:py-4 whitespace-nowrap text-sm font-bold text-green-600">
                                                {{ $reg->formatted_score }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
            @else
                <div class="bg-white shadow-lg rounded-lg p-6 text-center">
                    <p class="text-gray-700 text-base sm:text-lg">Результаты пока отсутствуют</p>
                </div>
            @endif
        @else
            <div class="bg-white shadow-lg rounded-lg p-6 text-center">
                <p class="text-gray-700 text-base sm:text-lg">Соревнование не найдено</p>
            </div>
        @endif
    </div>
</div>
