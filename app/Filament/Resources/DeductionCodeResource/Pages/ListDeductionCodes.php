<?php

namespace App\Filament\Resources\DeductionCodeResource\Pages;

use App\Filament\Resources\DeductionCodeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDeductionCodes extends ListRecords
{
    protected static string $resource = DeductionCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
