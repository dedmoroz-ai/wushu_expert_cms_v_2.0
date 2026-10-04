@php
    /** @var \App\Models\Club|null $club Данные клуба из его карточки (раздел «Клубы»). */
@endphp

{{-- Замечание заказчика (04.10): виджет клуба на дашборде тренера рядом
     с плашкой «Добро пожаловать» (обе в полстроки). Логотип клуба —
     в той же стилистике, что аватар плашки (100px, круглый); данные —
     из настроек клуба: название, город, регион. Без логотипа — плитка
     с первой буквой названия клуба.
     Замечание заказчика (04.10, выравнивание высоты): заголовка «Мой клуб»
     у секции нет — как у плашки «Добро пожаловать», чтобы верхние виджеты
     совпадали по высоте. --}}
<x-filament-widgets::widget class="fi-club-info-widget">
    <x-filament::section>
        @if ($club)
            <div class="flex items-center gap-x-3">
                @if ($club->logo_path)
                    <img
                        src="{{ asset('storage/' . $club->logo_path) }}"
                        alt="Логотип клуба"
                        style="width: 100px; height: 100px; object-fit: cover; border-radius: 9999px"
                    />
                @else
                    <div
                        style="width: 100px; height: 100px; border-radius: 9999px; background: linear-gradient(135deg, #2563eb, #1e3a8a); display: flex; align-items: center; justify-content: center;"
                    >
                        <span style="font-size: 40px; font-weight: 900; color: #fff;">{{ mb_strtoupper(mb_substr($club->name ?? '', 0, 1)) }}</span>
                    </div>
                @endif

                <div class="flex-1">
                    <h2
                        class="grid flex-1 text-base font-semibold leading-6 text-gray-950 dark:text-white"
                    >
                        {{ $club->name }}
                    </h2>

                    @if ($club->city)
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ $club->city }}
                        </p>
                    @endif

                    @if ($club->region)
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ $club->region }}
                        </p>
                    @endif
                </div>
            </div>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Клуб не привязан к тренеру — добавьте клуб в разделе «Клубы».
            </p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>