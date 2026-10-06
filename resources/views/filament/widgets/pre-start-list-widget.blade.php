{{-- Решение заказчика (05.10): виджет «Предварительный стартовый протокол» —
     кнопка открывает HTML-страницу с заявками актуального соревнования в новой
     вкладке (только авторизованным: тренер и администратор). --}}
<x-filament-widgets::widget class="fi-pre-start-list-widget">
    {{-- Замечание заказчика (05.10, правка): заголовок секции и описание убраны. --}}
    <x-filament::section>
        @if ($competition)
            <div class="flex items-center gap-x-3">
                <div class="flex-1">
                    {{-- Замечание заказчика (05.10, правка 2): заголовок виджета —
                         статический «Стартовый протокол» (название соревнования
                         не показывается), подпись — «Предварительный (по поданным
                         заявкам)». --}}
                    <h2 class="grid flex-1 text-base font-semibold leading-6 text-gray-950 dark:text-white">
                        Стартовый протокол
                    </h2>

                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Предварительный (по поданным заявкам)
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
