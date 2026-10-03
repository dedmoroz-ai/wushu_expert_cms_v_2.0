<?php

namespace App\Filament\Resources\RegistrationResource\Pages;

use App\Filament\Resources\RegistrationResource;
use App\Models\Athlete;
use App\Models\Competition;
use App\Models\Style;
use App\Support\AgeGroupResolver;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateRegistration extends CreateRecord
{
    protected static string $resource = RegistrationResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        // 1. Сборка массива стилей
        $taolu = $data['events_taolu_virtual'] ?? [];
        $trad = $data['events_trad_virtual'] ?? [];
        $yongchun = $data['events_yongchun_virtual'] ?? [];

        $selectedStyleIds = array_unique(array_merge($taolu, $trad, $yongchun));

        // --- ЛОГИКА ПАРТНЕРОВ (Берем из разных полей) ---
        $partnerForDuilian = $data['partner_duilian_virtual'] ?? null;
        $partnerForDuida = $data['partner_duida_virtual'] ?? null;

        // Удаляем лишние поля, чтобы не мешали при create()
        unset($data['events_taolu_virtual']);
        unset($data['events_trad_virtual']);
        unset($data['events_yongchun_virtual']);
        unset($data['events']);
        unset($data['partner_duilian_virtual']); // Чистим виртуалки
        unset($data['partner_duida_virtual']);   // Чистим виртуалки
        // partner_id тоже чистим, так как мы его будем назначать вручную ниже
        unset($data['partner_id']);

        // 2. ОПРЕДЕЛЕНИЕ ВОЗРАСТНОЙ ГРУППЫ (единый AgeGroupResolver — как при редактировании)
        $resolved = AgeGroupResolver::resolve(
            Athlete::find($data['athlete_id']),
            Competition::find($data['competition_id']),
        );

        $data['age_group_id'] = $resolved['age_group_id'];
        $data['age_group_label'] = $resolved['age_group_label'];

        $record = null;
        $countNew = 0;
        $countExists = 0;

        // 3. СОХРАНЕНИЕ (Цикл по стилям)
        foreach ($selectedStyleIds as $styleId) {
            $singleRowData = $data;
            $singleRowData['style_id'] = $styleId;
            $singleRowData['status'] = 0;

            // --- УМНЫЙ ВЫБОР ПАРТНЕРА ---
            $style = Style::find($styleId);
            $targetPartnerId = null;

            if ($style) {
                $styleName = mb_strtolower($style->name);

                // Если это Дуйлянь — берем партнера из поля для Дуйлянь
                if (str_contains($styleName, 'дуйлянь')) {
                    $targetPartnerId = $partnerForDuilian;
                }
                // Если это Дуйда — берем партнера из поля для Дуйда
                elseif (str_contains($styleName, 'дуйда')) {
                    $targetPartnerId = $partnerForDuida;
                }
            }

            // Присваиваем правильного партнера (или NULL, если вид одиночный)
            $singleRowData['partner_id'] = $targetPartnerId;
            // ---------------------------

            $record = static::getModel()::firstOrCreate(
                [
                    'competition_id' => $singleRowData['competition_id'],
                    'athlete_id' => $singleRowData['athlete_id'],
                    'style_id' => $styleId,
                ],
                $singleRowData
            );

            // Если запись уже была, но у нее не тот партнер (или его не было),
            // либо иная финальная отметка группы «O» (R-6.15) — обновляем
            if ($record->partner_id != $targetPartnerId) {
                $record->update(['partner_id' => $targetPartnerId]);
            }

            if ((bool) $record->is_special !== (bool) ($singleRowData['is_special'] ?? false)) {
                $record->update(['is_special' => (bool) ($singleRowData['is_special'] ?? false)]);
            }

            if ($record->wasRecentlyCreated) {
                $countNew++;
            } else {
                $countExists++;
            }
        }

        // Если вообще ничего не выбрали (редкий случай), создаем пустышку
        if (! $record) {
            $data['partner_id'] = null;
            $record = static::getModel()::create($data);
        }

        Notification::make()
            ->title('Обработка завершена')
            ->body("Добавлено/Обновлено: {$countNew}. Найдено существующих: {$countExists}.")
            ->success()
            ->send();

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
