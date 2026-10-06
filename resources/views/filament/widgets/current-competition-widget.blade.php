{{-- Замечание заказчика (02.10): плашка «Актуальное соревнование» на дашборде
     администратора: название, календарные даты проведения, адрес и статус
     сессии регистрации заявок от тренеров («Ожидает открытия» /
     «Идёт регистрация» / «Регистрация завершена» / «Не задано»).
     Замечание заказчика (05.10): логотип федерации с плашки убран —
     остаётся только аватар турнира. --}}
<x-filament-widgets::widget class="fi-current-competition-widget">
    <x-filament::section heading="Актуальное соревнование">
        @if ($competition)
            <div class="flex items-center gap-x-3">
                {{-- Замечание заказчика (05.10): аватар самого турнира — тот же
                     размер 80×80; форма — круглая (как на табло и странице
                     результатов). Логотип федерации на плашке не показывается.
                     Без загруженного аватара элемент не рисуется вовсе.
                     В документы (протоколы, дипломы) не входит. --}}
                @if ($competition->avatarUrl())
                    <img
                        src="{{ $competition->avatarUrl() }}"
                        alt="Аватар турнира"
                        style="width: 80px; height: 80px; object-fit: contain; border-radius: 50%"
                    />
                @endif

                <div class="flex-1">
                    {{-- Замечание заказчика (05.10): на дашборде администратора
                         название кликабельно — переход в карточку соревнования,
                         как через пункт меню «Соревнования» в сайдбаре.
                         Остальным ролям — обычный текст. --}}
                    <h2
                        class="grid flex-1 text-base font-semibold leading-6 text-gray-950 dark:text-white"
                    >
                        @if ($competitionEditUrl)
                            <a href="{{ $competitionEditUrl }}" class="underline">
                                {{ $competition->name }}
                            </a>
                        @else
                            {{ $competition->name }}
                        @endif
                    </h2>

                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ $competition->datesLabel() }}
                    </p>

                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ $competition->placeLabel() }}
                    </p>
                </div>

                <div class="my-auto" style="text-align: right;">
                    @if ($showCompetitionStatus)
                        {{-- Замечание заказчика (02.10): судьям (линейному и
                             старшему) — статус самого соревнования: «Скоро» /
                             «Запущено» / «На паузе» / «Завершено». --}}
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Статус соревнования
                        </p>

                        <x-filament::badge :color="$competition->statusColor()">
                            {{ $competition->statusLabel() }}
                        </x-filament::badge>
                    @else
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Заявки от тренеров
                        </p>

                        <x-filament::badge :color="$competition->registrationSessionStatusColor()">
                            {{ $competition->registrationSessionStatusLabel() }}
                        </x-filament::badge>
                    @endif
                </div>
            </div>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Соревнований пока нет — создайте первое в разделе «Турнир».
            </p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>