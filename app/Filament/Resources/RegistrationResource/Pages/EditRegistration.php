<?php

namespace App\Filament\Resources\RegistrationResource\Pages;

use App\Filament\Resources\RegistrationResource;
use App\Models\Athlete;
use App\Models\Competition;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRegistration extends EditRecord
{
    protected static string $resource = RegistrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // 1. Защита от ошибок с чекбоксами
        $taolu = $data['events_taolu_virtual'] ?? [];
        if (! is_array($taolu)) {
            $taolu = [];
        }

        $trad = $data['events_trad_virtual'] ?? [];
        if (! is_array($trad)) {
            $trad = [];
        }

        $yongchun = $data['events_yongchun_virtual'] ?? [];
        if (! is_array($yongchun)) {
            $yongchun = [];
        }

        $data['events'] = array_merge($taolu, $trad, $yongchun);

        unset($data['events_taolu_virtual']);
        unset($data['events_trad_virtual']);
        unset($data['events_yongchun_virtual']);

        // 2. АВТО-РАСЧЕТ КАТЕГОРИИ (С учетом ПОЛА)
        $data['age_group_label'] = $this->calculateAgeGroup($data['athlete_id'], $data['competition_id']);

        return $data;
    }

    protected function calculateAgeGroup($athleteId, $competitionId)
    {
        $athlete = Athlete::find($athleteId);
        $competition = Competition::find($competitionId);

        if (! $athlete || ! $competition || ! $athlete->birth_date) {
            return null;
        }

        $age = $competition->start_date->year - $athlete->birth_date->year;

        // Определяем пол
        $genderRaw = mb_strtolower($athlete->gender ?? '');
        $isMale = in_array($genderRaw, ['male', 'm', 'man', 'мужской', 'муж', 'м']);

        if ($age < 9) {
            return $isMale ? 'Мальчики (до 9 лет)' : 'Девочки (до 9 лет)';
        }

        if ($age >= 9 && $age <= 11) {
            return $isMale ? 'Мальчики (9-11 лет)' : 'Девочки (9-11 лет)';
        }

        if ($age >= 12 && $age <= 14) {
            return $isMale ? 'Юноши (12-14 лет)' : 'Девушки (12-14 лет)';
        }

        if ($age >= 15 && $age <= 17) {
            return $isMale ? 'Юниоры (15-17 лет)' : 'Юниорки (15-17 лет)';
        }

        if ($age >= 18 && $age <= 35) {
            return $isMale ? 'Мужчины (18-35 лет)' : 'Женщины (18-35 лет)';
        }

        if ($age >= 36) {
            return $isMale ? 'Ветераны (36+ лет)' : 'Ветераны-женщины (36+ лет)';
        }

        return "Не определено ($age лет)";
    }
}
