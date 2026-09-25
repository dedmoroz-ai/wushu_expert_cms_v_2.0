<?php

namespace App\Filament\Resources\CompetitionResource\Pages;

use App\Filament\Resources\CompetitionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Schema;

class EditCompetition extends EditRecord
{
    protected static string $resource = CompetitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // 1. Кнопка "Стартовый протокол"
            Actions\Action::make('start_protocol')
                ->label('Стартовый протокол (PDF)')
                ->icon('heroicon-o-printer')
                ->color('success') // Серый цвет
                ->url(fn ($record) => route('competition.start-list', $record))
                ->openUrlInNewTab(),

            // 2. Кнопка "Итоговый протокол"
            Actions\Action::make('final_protocol')
                ->label('Итоговый протокол (PDF)')
                ->icon('heroicon-o-trophy')
                ->color('success') // Зеленый цвет
                ->url(fn ($record) => route('competition.final-results', $record))
                ->openUrlInNewTab(),

            // 3. Кнопка "QR-код для публичной страницы"
            Actions\Action::make('qr_code')
                ->label('QR-код для публичной страницы')
                ->icon('heroicon-o-qr-code')
                ->color('info')
                ->modalHeading('QR-код для публичной страницы результатов')
                ->modalContent(function () {
                    $competition = $this->record;
                    
                    // Проверяем наличие колонки (для совместимости с версиями без миграции)
                    try {
                    $hasPublicTokenColumn = Schema::hasColumn('competitions', 'public_token');
                    
                    if ($hasPublicTokenColumn) {
                        // Генерируем токен, если его нет
                        if (!$competition->public_token) {
                            $competition->public_token = \Illuminate\Support\Str::random(32);
                            $competition->save();
                        }
                        $publicUrl = route('public.results', ['token' => $competition->public_token]);
                    } else {
                            // Если колонки нет, используем ID соревнования
                            $publicUrl = route('public.results', ['token' => 'comp_' . $competition->id]);
                        }
                    } catch (\Exception $e) {
                        // Fallback: используем ID соревнования
                        $publicUrl = route('public.results', ['token' => 'comp_' . $competition->id]);
                    }
                    
                    $qrCodeUrl = route('competition.qr-code', $competition);
                    
                    return view('filament.resources.competition-resource.pages.qr-code-modal', [
                        'publicUrl' => $publicUrl,
                        'qrCodeUrl' => $qrCodeUrl,
                    ]);
                })
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Закрыть'),

            // 4. Стандартная кнопка удаления
            Actions\DeleteAction::make(),
        ];
    }
}
