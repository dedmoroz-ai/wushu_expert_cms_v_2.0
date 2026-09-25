<?php

namespace App\Filament\Resources\DeductionCodeResource\Pages;

use App\Filament\Resources\DeductionCodeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDeductionCode extends EditRecord
{
    protected static string $resource = DeductionCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
