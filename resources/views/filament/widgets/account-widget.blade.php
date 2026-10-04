@php
    $user = filament()->auth()->user();
@endphp

{{-- Замечание заказчика (04.10): у всех ролей — личный аватар авторизованного
     пользователя из настроек пользователя (у тренера раньше был логотип клуба —
     теперь логотип и данные клуба в соседнем виджете «Мой клуб»). У тренера
     плашка занимает половину строки, у администратора и судей — всю.
     Верхний аватар в шапке (user-menu) остаётся прежним. --}}
<x-filament-widgets::widget class="fi-account-widget">
    <x-filament::section>
        <div class="flex items-center gap-x-3">
            <x-filament-panels::avatar.user
                size="lg"
                :user="$user"
                style="width: 100px; height: 100px"
            />

            <div class="flex-1">
                <h2
                    class="grid flex-1 text-base font-semibold leading-6 text-gray-950 dark:text-white"
                >
                    {{ __('filament-panels::widgets/account-widget.welcome', ['app' => config('app.name')]) }}
                </h2>

                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ filament()->getUserName($user) }}
                </p>

                {{-- Замечание заказчика (02.10): судьям (линейному и старшему) —
                     судейская категория из настроек судейской коллегии
                     (поле «Судейская категория» в карточке судьи). --}}
                @if ($user->isJudge())
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ $user->judgeCategoryLabel() ?? 'Судейская категория не задана' }}
                    </p>
                @endif
            </div>

            <form
                action="{{ filament()->getLogoutUrl() }}"
                method="post"
                class="my-auto"
            >
                @csrf

                <x-filament::button
                    color="gray"
                    icon="heroicon-m-arrow-left-on-rectangle"
                    icon-alias="panels::widgets.account.logout-button"
                    labeled-from="sm"
                    tag="button"
                    type="submit"
                >
                    {{ __('filament-panels::widgets/account-widget.actions.logout.label') }}
                </x-filament::button>
            </form>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>