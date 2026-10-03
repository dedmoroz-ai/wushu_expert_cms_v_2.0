@php
    $user = filament()->auth()->user();
    /** @var \App\Models\Club|null $club Аватар клуба — только на плашке тренера. */
    $club = $user->isCoach() ? ($user->club ?? null) : null;
@endphp

{{-- Замечание заказчика (02.10): копия вендорной вью плашки «Добро
     пожаловать» с увеличенным до 100px аватаром. У тренера аватаром
     служит аватар (логотип) клуба, а под приветствием — имя и фамилия
     авторизованного тренера (к клубу может быть привязано несколько
     тренеров). Верхний аватар в шапке (user-menu) остаётся прежним. --}}
<x-filament-widgets::widget class="fi-account-widget">
    <x-filament::section>
        <div class="flex items-center gap-x-3">
            @if ($club)
                @if ($club->logo_path)
                    <img
                        src="{{ asset('storage/' . $club->logo_path) }}"
                        alt="Аватар клуба"
                        style="width: 100px; height: 100px; object-fit: cover; border-radius: 9999px"
                    />
                @else
                    <div
                        style="width: 100px; height: 100px; border-radius: 9999px; background: linear-gradient(135deg, #2563eb, #1e3a8a); display: flex; align-items: center; justify-content: center;"
                    >
                        <span style="font-size: 40px; font-weight: 900; color: #fff;">{{ mb_strtoupper(mb_substr($club->name ?? '', 0, 1)) }}</span>
                    </div>
                @endif
            @else
                <x-filament-panels::avatar.user
                    size="lg"
                    :user="$user"
                    style="width: 100px; height: 100px"
                />
            @endif

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