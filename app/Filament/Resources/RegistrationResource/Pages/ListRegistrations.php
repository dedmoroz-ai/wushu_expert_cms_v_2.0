<?php

namespace App\Filament\Resources\RegistrationResource\Pages;

use App\Filament\Resources\RegistrationResource;
use App\Models\Competition;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\HtmlString;

class ListRegistrations extends ListRecords
{
    protected static string $resource = RegistrationResource::class;

    /**
     * Замечание заказчика (13.10): после завершения сессии подачи заявок
     * тренер не может создавать и редактировать заявки — клик по «Создать»
     * открывает модальное предупреждение вместо формы. Администратор не
     * ограничен. Сессия берётся от «актуального» соревнования (та же, что
     * на плашке «Актуальное соревнование» дашборда).
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->visible(fn (): bool => ! RegistrationResource::registrationSessionClosedFor(Competition::actual())),

            Actions\Action::make('createSessionClosed')
                ->label('Создать')
                ->icon('heroicon-m-plus')
                ->color('primary')
                ->visible(fn (): bool => RegistrationResource::registrationSessionClosedFor(Competition::actual()))
                ->requiresConfirmation()
                ->modalHeading('Приём заявок завершён')
                ->modalIcon('heroicon-m-exclamation-triangle')
                ->modalIconColor('warning')
                ->modalDescription(
                    fn () => new HtmlString(nl2br(e(Competition::actual()?->registrationClosedMessage() ?? '')))
                )
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Понятно')
                ->action(fn () => null),
        ];
    }
}
