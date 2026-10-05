{{-- Решение заказчика (05.10): виджет «QR-код страницы результатов» — Alpine-модалка
     с QR-кодом, ссылкой и кнопкой «Копировать» (по образцу qr-code-modal.blade.php).
     Виден только тренеру и администратору. --}}
<x-filament-widgets::widget class="fi-public-results-qr-widget">
    <x-filament::section
        heading="QR-код страницы результатов"
        description="Ссылка и QR-код публичной страницы с результатами — поделитесь со зрителями."
    >
        @if ($competition)
            <div x-data="{ open: false }">
                <div class="flex items-center gap-x-3">
                    <div class="flex-1">
                        <h2 class="grid flex-1 text-base font-semibold leading-6 text-gray-950 dark:text-white">
                            {{ $competition->name }}
                        </h2>

                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ $publicUrl }}
                        </p>
                    </div>

                    <x-filament::button color="gray" icon="heroicon-o-qr-code" x-on:click="open = true">
                        Показать QR-код
                    </x-filament::button>
                </div>

                {{-- Alpine-модалка с QR-кодом --}}
                <div
                    x-cloak
                    x-show="open"
                    x-on:keydown.escape.window="open = false"
                    class="fixed inset-0 z-[9999] flex items-center justify-center p-4"
                    style="background: rgba(0, 0, 0, 0.5);"
                >
                    <div
                        class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-gray-900"
                        @click.outside="open = false"
                    >
                        <div class="mb-3 text-sm text-gray-600 dark:text-gray-300">
                            Отсканируйте QR-код или перейдите по ссылке — откроется публичная страница результатов соревнования.
                        </div>

                        <div class="mb-4 flex justify-center rounded-lg border-2 border-gray-200 bg-white p-4">
                            <img
                                src="{{ $qrCodeUrl }}"
                                alt="QR-код страницы результатов"
                                width="256"
                                height="256"
                                style="width: 256px; height: 256px;"
                            />
                        </div>

                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
                            Ссылка на публичную страницу:
                        </label>

                        <div class="mb-4 flex gap-2">
                            <input
                                type="text"
                                readonly
                                value="{{ $publicUrl }}"
                                class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                            />

                            <x-filament::button
                                color="primary"
                                x-on:click="navigator.clipboard.writeText('{{ $publicUrl }}').then(() => { $el.querySelector('span') ? $el.querySelector('span').textContent = 'Скопировано' : $el.textContent = 'Скопировано' })"
                            >
                                Копировать
                            </x-filament::button>
                        </div>

                        <x-filament::button color="gray" x-on:click="open = false" class="w-full">
                            Закрыть
                        </x-filament::button>
                    </div>
                </div>
            </div>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Актуального соревнования пока нет — QR-код появится после его создания.
            </p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
