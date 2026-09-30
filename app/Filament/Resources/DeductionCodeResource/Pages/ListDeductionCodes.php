<?php

namespace App\Filament\Resources\DeductionCodeResource\Pages;

use App\Filament\Resources\DeductionCodeResource;
use App\Models\Competition;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ListRecords;

class ListDeductionCodes extends ListRecords
{
    protected static string $resource = DeductionCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),

            // Памятка для печати и раздачи судьям: активные коды с пояснениями (PDF).
            Actions\Action::make('memo_pdf')
                ->label('Памятка (PDF)')
                ->tooltip('Памятка судьям: активные коды сбавок с пояснениями (PDF)')
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
                        $this->js('window.open("'.route('competition.deduction-codes-memo', $competition).'", "_blank")');
                    }
                }),
        ];
    }
}
