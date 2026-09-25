<?php

namespace App\Filament\Resources\JudgingLogResource\Pages;

use App\Filament\Resources\JudgingLogResource;
use Filament\Resources\Pages\ListRecords;

class ListJudgingLogs extends ListRecords
{
    protected static string $resource = JudgingLogResource::class;

    // Правило 8.9: журнал только для чтения — кнопок создания нет.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
