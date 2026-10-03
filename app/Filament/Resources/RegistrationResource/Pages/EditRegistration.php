<?php

namespace App\Filament\Resources\RegistrationResource\Pages;

use App\Filament\Resources\RegistrationResource;
use App\Models\Athlete;
use App\Models\Registration;
use App\Models\Style;
use App\Support\AgeGroupResolver;
use Closure;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Pages\EditRecord;

/**
 * Замечание заказчика (01.10.2026): кнопка «Изменить» в «Заявках» работала
 * неправильно: открывалась форма создания с виртуальными полями без
 * автозаполнения, сохранение падало (update писал в несуществующие колонки),
 * а дисциплина и партнёр не менялись.
 *
 * Теперь редактирование — «одна заявка = одна строка»:
 *  - дисциплина (style_id) и партнёр (partner_id) меняются напрямую;
 *  - спортсмен и соревнование — только чтение (заявка привязана к ним);
 *  - категория пересчитывается через AgeGroupResolver при каждом сохранении —
 *    как при создании (age_group_id + age_group_label не разъезжаются);
 *  - виртуальные поля формы создания здесь не участвуют.
 *
 * Массовые изменения набора дисциплин — через «Стартовый протокол».
 */
class EditRegistration extends EditRecord
{
    protected static string $resource = RegistrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Своя форма — «одна заявка = одна строка» вместо общей формы создания
     * с виртуальными полями (events_*_virtual, partner_*_virtual).
     */
    public function form(Form $form): Form
    {
        // unique_athlete_style_per_competition: понятное сообщение валидации
        // вместо 500 от БД-констрейнта при смене дисциплины на уже заявленную.
        // (Как в RegistrationResource: внешняя closure вычисляется Filament,
        //  возвращаемая уходит в Laravel-валидатор.)
        $uniqueStyleRule = fn (): Closure => function (string $attribute, $value, Closure $fail) {
            if (! $value) {
                return;
            }

            $exists = Registration::query()
                ->where('competition_id', $this->record->competition_id)
                ->where('athlete_id', $this->record->athlete_id)
                ->where('style_id', $value)
                ->whereKeyNot($this->record)
                ->exists();

            if ($exists) {
                $fail('Такая дисциплина уже заявлена для этого спортсмена на этом соревновании.');
            }
        };

        // R-6.15: пара «O / не O» невозможна — отметки карточек основного
        // и партнёра должны совпадать (как в форме создания).
        $partnerSpecialRule = fn (): Closure => function (string $attribute, $value, Closure $fail) {
            if (! $value) {
                return;
            }

            $main = Athlete::find($this->record->athlete_id);
            $partner = Athlete::find($value);

            if ($main && $partner && (bool) $main->is_special !== (bool) $partner->is_special) {
                $fail('Пара «O / не O» невозможна: отметки карточек основного и партнёра должны совпадать (оба «O» или оба не «O»).');
            }
        };

        return $form
            ->schema([
                Forms\Components\Section::make('Данные заявки')
                    ->schema([
                        Forms\Components\Placeholder::make('competition_title')
                            ->label('Соревнование')
                            ->content(fn (): string => $this->record->competition?->name ?? '—'),

                        Forms\Components\Placeholder::make('athlete_title')
                            ->label('Спортсмен')
                            ->content(fn (): string => $this->record->athlete?->name ?? '—'),

                        // Категория пересчитается при сохранении (как при создании).
                        Forms\Components\Placeholder::make('age_group_title')
                            ->label('Категория (будет пересчитана)')
                            ->content(fn (): string => AgeGroupResolver::resolve(
                                $this->record->athlete,
                                $this->record->competition,
                            )['age_group_label']),
                    ])->columns(2),

                Forms\Components\Section::make('Заявка')
                    ->schema([
                        Forms\Components\Select::make('style_id')
                            ->relationship('style', 'name')
                            ->label('Дисциплина (Вид)')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->rules([$uniqueStyleRule]),

                        Forms\Components\Select::make('partner_id')
                            ->relationship('partner', 'name', fn ($query) => $query->whereKeyNot($this->record->athlete_id))
                            ->label('Второй участник (Партнёр)')
                            ->searchable()
                            ->preload()
                            ->rules([$partnerSpecialRule])
                            // Партнёр нужен только парным видам (Дуйлянь / Дуйда).
                            ->visible(fn (Get $get): bool => (bool) Style::find($get('style_id'))?->isPair()),

                        // Группа «O» (особые спортсмены) — R-6.15, п. 9.16.
                        Forms\Components\Toggle::make('is_special')
                            ->label('Группа «O» (особые спортсмены)')
                            ->helperText('Отдельный зачёт внутри номинации.')
                            ->default(false),
                    ])->columns(2),
            ]);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Категория — всегда через общий AgeGroupResolver (как при создании):
        // пишем и id, и метку, чтобы они не разъезжались.
        $resolved = AgeGroupResolver::resolve($this->record->athlete, $this->record->competition);
        $data['age_group_id'] = $resolved['age_group_id'];
        $data['age_group_label'] = $resolved['age_group_label'];

        // Партнёр хранится только у парных видов (Дуйлянь / Дуйда) — R-6.15.
        $style = Style::find($data['style_id'] ?? null);
        if (! $style || ! $style->isPair()) {
            $data['partner_id'] = null;
        }

        return $data;
    }
}
