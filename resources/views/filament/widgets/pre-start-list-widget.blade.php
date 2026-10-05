{{-- Решение заказчика (05.10): виджет «Предварительный стартовый протокол» —
     кнопка открывает HTML-страницу с заявками актуального соревнования в новой
     вкладке (только авторизованным: тренер и администратор). --}}
<x-filament-widgets::widget class="fi-pre-start-list-widget">
    <x-filament::section
        heading="Предварительный стартовый протокол"
        description="По поданным заявкам на актуальное соревнование (не финальные результаты)."
    >
        @if ($competition)
            <div class="flex items-center gap-x-3">
                <div class="flex-1">
                    <h2 class="grid flex-1 text-base font-semibold leading-6 text-gray-950 dark:text-white">
                        {{ $competition->name }}
                    </h2>

                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ $competition->datesLabel() }}{{ $competition->placeLabel() ? ' | '.$competition->placeLabel() : '' }}
                    </p>
                </div>

                <x-filament::button
                    color="gray"
                    icon="heroicon-o-document-text"
                    tag="a"
                    :href="route('start-list.preview', $competition)"
                    target="_blank"
                    rel="noopener"
                >
                    Открыть протокол
                </x-filament::button>
            </div>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Актуального соревнования пока нет — протокол появится после его создания.
            </p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
