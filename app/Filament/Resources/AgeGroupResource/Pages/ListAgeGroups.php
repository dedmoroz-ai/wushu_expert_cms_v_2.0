<?php

namespace App\Filament\Resources\AgeGroupResource\Pages;

use App\Filament\Resources\AgeGroupResource;
use App\Models\Competition;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ListRecords;

class ListAgeGroups extends ListRecords
{
    protected static string $resource = AgeGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),

            // Памятка для печати и раздачи судьям: лимиты оценок по возрастным группам (PDF).
            Actions\Action::make('memo_pdf')
                ->label('Памятка (PDF)')
                ->tooltip('Памятка судьям: лимиты оценок по возрастным группам (PDF)')
                ->icon('heroicon-o-printer')
                ->color('success')
                ->form([
                    Forms\Components\Select::make('competition_id')
                        ->label('Соревнование')
                        ->options(fn () => Competition::query()
                            ->orderByDesc('start_date')
                            ->orderByDesc('id')
                            ->pluck('name', 'id')
                            ->all())
                        ->default(fn () => Competition::active()?->id
                            ?? Competition::query()->orderByDesc('start_date')->value('id'))
                        ->searchable()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $competition = Competition::find($data['competition_id']);

                    if ($competition) {
                        $this->js('window.open(\"'.route('competition.age-groups-memo', $competition).'\", \"_blank\")');
                    }
                }),
        ];
    }
}
