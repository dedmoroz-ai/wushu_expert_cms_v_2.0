<?php

namespace App\Filament\Resources\CompetitionResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder; 
use Filament\Notifications\Notification;
use App\Models\AgeGroup;
use App\Models\JudgingLog;
use App\Models\Registration;
use App\Models\Style;
use App\Support\ScoreRange;
use Illuminate\Support\HtmlString; 

class RegistrationsRelationManager extends RelationManager
{
    protected static string $relationship = 'registrations';

    protected static ?string $title = 'Стартовый протокол';
    protected static ?string $icon = 'heroicon-o-list-bullet';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('athlete_id')
                    ->relationship('athlete', 'name')
                    ->label('Спортсмен')
                    ->searchable()
                    ->preload()
                    ->required(),
                
                Forms\Components\Select::make('style_id')
                    ->relationship('style', 'name')
                    ->label('Стиль (Вид)')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live(),

                Forms\Components\Select::make('age_group_id')
                    ->relationship('ageGroup', 'name')
                    ->label('Возрастная группа')
                    ->getOptionLabelFromRecordUsing(fn (Model $record) => "{$record->name} ({$record->min_age}-{$record->max_age} лет)")
                    ->searchable()
                    ->preload()
                    ->required(),

                Forms\Components\Select::make('partner_id')
                    ->relationship('partner', 'name')
                    ->label('Второй участник (Партнер)')
                    ->searchable()
                    ->preload()
                    ->visible(function (Forms\Get $get) {
                        $styleId = $get('style_id');
                        if (!$styleId) return false;
                        $style = Style::find($styleId);
                        if (!$style) return false;
                        return str_contains($style->name, 'Дуйлянь') || str_contains($style->name, 'Дуйда');
                    }),

                // === ПОЛЕ ВВОДА ОЦЕНКИ ===
                // Правила 8.2, 8.3: диапазон ограничен лимитами возрастной категории.
                Forms\Components\TextInput::make('final_score')
                    ->label('Итоговая оценка')
                    ->numeric()
                    ->step(0.001) // Правило 8.1: 3 знака после точки
                    // Правило R-4.20: в сценарии A/B итог = A + B, диапазон 0.000–10.000.
                    ->minValue(fn (Forms\Get $get) => $this->finalScoreRange($get)->min)
                    ->maxValue(fn (Forms\Get $get) => $this->finalScoreRange($get)->max)
                    ->helperText(fn (Forms\Get $get) => 'Допустимый диапазон: '
                        . $this->finalScoreRange($get)->label())
                    ->placeholder('0.000'),

                // Правило 8.9: обоснование ручной правки итогового балла.
                Forms\Components\TextInput::make('final_score_reason')
                    ->label('Причина правки итогового балла')
                    ->helperText('Заполните при ручном изменении оценки — будет записано в журнал судейства.')
                    ->maxLength(255)
                    // Значение не хранится в registrations — оно уходит только в журнал.
                    ->formatStateUsing(fn () => null)
                    ->visible(fn (string $operation): bool => $operation === 'edit'),
            ]);
    }

    /**
     * Правила R-4.11, R-4.20: диапазон итогового балла с учётом сценария турнира.
     */
    protected function finalScoreRange(Forms\Get $get): ScoreRange
    {
        if ($this->getOwnerRecord()->isAbScheme()) {
            return ScoreRange::global();
        }

        return ScoreRange::forAgeGroup(AgeGroup::find($get('age_group_id')));
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('№')
                    ->sortable()
                    ->weight('black')
                    ->alignCenter()
                    ->width(60),

                Tables\Columns\TextColumn::make('virtual_status')
                    ->label('Статус')
                    ->badge()
                    ->getStateUsing(function ($record) {
                        if ($record->is_completed) {
                            return 'Оценен';
                        }
                        $competition = $record->competition; 
                        if ($competition && $competition->current_registration_id == $record->id) {
                            return 'На ковре';
                        }
                        return 'Ждет';
                    })
                    ->colors([
                        'success' => 'Оценен',
                        'warning' => 'На ковре',
                        'gray' => 'Ждет',
                    ])
                    ->icons([
                        'heroicon-o-check-circle' => 'Оценен',
                        'heroicon-o-play' => 'На ковре',
                        'heroicon-o-clock' => 'Ждет',
                    ]),

                Tables\Columns\TextColumn::make('athlete.name')
                    ->label('Спортсмен')
                    ->searchable(['name', 'surname'])
                    ->weight('bold')
                    ->html() 
                    ->formatStateUsing(function ($state, Model $record) {
                        $mainName = "{$record->athlete->surname} {$record->athlete->name}";
                        if ($record->partner) {
                            $partnerName = "{$record->partner->surname} {$record->partner->name}";
                            return "<div>{$mainName}</div><div>{$partnerName}</div>";
                        }
                        return $mainName;
                    })
                    ->wrap(),

                Tables\Columns\TextColumn::make('style.name')
                    ->label('Вид')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('age_group_label')
                    ->label('Группа')
                    ->formatStateUsing(function ($state, Model $record) {
                        if ($record->ageGroup) {
                            return "{$record->ageGroup->name} ({$record->ageGroup->min_age}-{$record->ageGroup->max_age} лет)";
                        }
                        return $state;
                    })
                    ->wrap()
                    ->sortable(),

                Tables\Columns\IconColumn::make('athlete.gender')
                    ->label('Пол')
                    ->icon(fn (string $state): string => match (strtolower($state)) {
                        'male', 'm', 'муж', 'мужской' => 'heroicon-m-user',
                        'female', 'f', 'жен', 'женский' => 'heroicon-m-user',
                        default => 'heroicon-m-user',
                    })
                    ->color(fn (string $state): string => match (strtolower($state)) {
                        'male', 'm', 'муж', 'мужской' => 'info',
                        'female', 'f', 'жен', 'женский' => 'danger',
                        default => 'gray',
                    }),

                // ===============================================
                // КОЛОНКА С ОЦЕНКОЙ (Форматирование вывода)
                // ===============================================
                Tables\Columns\TextColumn::make('final_score')
                    ->label('Оценка')
                    ->weight('black')
                    ->size(Tables\Columns\TextColumn\TextColumnSize::Large)
                    ->color('success')
                    ->placeholder('-')
                    ->formatStateUsing(function ($state) {
                        if ($state === null || $state === '') {
                            return '-';
                        }
                        $val = floatval($state);
                        
                        if ($val == 0 && $state !== '0' && $state !== 0) {
                             return $state;
                        }

                        // Принудительно 3 знака
                        return number_format($val, 3, '.', '');
                    }),
                // ===============================================

                Tables\Columns\TextColumn::make('place')
                    ->label('Место')
                    ->alignCenter()
                    ->weight('black')
                    ->state(function ($record) {
                        if (!$record->final_score || !$record->is_completed) return '-';

                        // Плотная нумерация мест по тем же правилам, что и в PDF:
                        // внутри одного соревнования, вида, возрастной группы и пола.
                        $distinctHigherScores = Registration::query()
                            ->where('competition_id', $record->competition_id)
                            ->where('style_id', $record->style_id)
                            ->where('age_group_id', $record->age_group_id)
                            ->whereHas('athlete', function($q) use ($record) {
                                $q->where('gender', $record->athlete->gender);
                            })
                            ->where('is_completed', true)
                            ->whereNotNull('final_score')
                            ->where('final_score', '>', $record->final_score)
                            ->distinct('final_score')
                            ->count('final_score');

                        // dense ranking: 1 + количество различных более высоких оценок
                        return $distinctHigherScores + 1;
                    })
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        1 => 'warning',
                        2 => 'gray',
                        3 => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => match ($state) {
                        1 => '🥇 1',
                        2 => '🥈 2',
                        3 => '🥉 3',
                        '-' => '-',
                        default => $state,
                    }),
            ])
            ->defaultSort('sort_order', 'asc')

            ->groups([
                Tables\Grouping\Group::make('flow')
                    ->label('Потоки (Вид + Группа + Пол)')
                    ->orderQueryUsing(fn (Builder $query) => $query
                        ->join('styles', 'registrations.style_id', '=', 'styles.id')
                        ->join('age_groups', 'registrations.age_group_id', '=', 'age_groups.id')
                        ->join('athletes', 'registrations.athlete_id', '=', 'athletes.id')
                        ->orderBy('styles.sort_order')
                        ->orderBy('age_groups.sort_order')
                        ->orderBy('athletes.gender')
                        ->select('registrations.*')
                    )
                    ->getKeyFromRecordUsing(function ($record) {
                        return sprintf(
                            '%09d_%09d_%s',
                            $record->style->sort_order ?? 9999,
                            $record->ageGroup->sort_order ?? 9999,
                            $record->athlete->gender
                        );
                    })
                    ->getTitleFromRecordUsing(function ($record) {
                        return sprintf(
                            '%s — %s (%s-%s лет)',
                            $record->style->name,
                            $record->ageGroup->name,
                            $record->ageGroup->min_age,
                            $record->ageGroup->max_age
                        );
                    })
                    ->getDescriptionFromRecordUsing(function ($record) {
                        $url = route('export.diplomas', [
                            'competition' => $record->competition_id, 
                            'style' => $record->style_id,
                            'age_group' => $record->age_group_id,
                            'gender' => $record->athlete->gender,
                        ]);

                        return new HtmlString("
                            <div style='margin-top: 10px;'>
                                <a href='{$url}' 
                                   target='_blank' 
                                   onclick='event.stopPropagation();'
                                   style='display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; background-color: #d97706; color: black; font-size: 13px; font-weight: bold; border-radius: 6px; text-decoration: none;'
                                   onmouseover=\"this.style.backgroundColor='#b45309'\"
                                   onmouseout=\"this.style.backgroundColor='#d97706'\">
                                    <svg xmlns='http://www.w3.org/2000/svg' style='width: 14px; height: 14px;' fill='none' viewBox='0 0 24 24' stroke-width='2' stroke='currentColor'>
                                      <path stroke-linecap='round' stroke-linejoin='round' d='M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.229 2.523a1.125 1.125 0 01-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0021 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 00-1.913-.247M6.34 18H5.25A2.25 2.25 0 013 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 011.913-.247m10.5 0a48.536 48.536 0 00-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5zm-3 0h.008v.008H15V10.5z' />
                                    </svg>
                                    Дипломы
                                </a>
                            </div>
                        ");
                    })
                    ->collapsible() 
                    ->titlePrefixedWithLabel(false), 
            ])
            ->defaultGroup('flow')

            ->headerActions([
                Tables\Actions\Action::make('reorder_protocol')
                    ->label('Провести жеребьевку')
                    ->icon('heroicon-o-arrows-right-left')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Сформировать протокол')
                    ->modalDescription('Строгая иерархия: 1. Вид (по номеру), 2. Группа (по номеру), 3. Рандом внутри группы.')
                    ->action(function (RelationManager $livewire) {
                        $competition = $livewire->getOwnerRecord();
                        $registrations = $competition->registrations()->with(['style', 'ageGroup'])->get();
                        $buckets = [];
                        foreach ($registrations as $reg) {
                            $styleOrder = (int) ($reg->style->sort_order ?? 99999);
                            $groupOrder = (int) ($reg->ageGroup->sort_order ?? 99999);
                            $key = sprintf('%09d_%09d', $styleOrder, $groupOrder);
                            $buckets[$key][] = $reg;
                        }
                        ksort($buckets);
                        $counter = 1;
                        foreach ($buckets as $key => $items) {
                            $shuffledItems = collect($items)->shuffle();
                            foreach ($shuffledItems as $reg) {
                                $reg->updateQuietly(['sort_order' => $counter++]);
                            }
                        }
                        Notification::make()
                            ->title('Жеребьевка успешна!')
                            ->body("Обработано участников: " . ($counter - 1))
                            ->success()
                            ->send();
                        $livewire->dispatch('refresh-table'); 
                    }),
            ])
            ->actions([
                // Правило 8.9: фиксируем ручную правку итогового балла в журнале.
                Tables\Actions\EditAction::make()
                    ->using(function (Registration $record, array $data, RelationManager $livewire): Registration {
                        $reason = $data['final_score_reason'] ?? null;
                        unset($data['final_score_reason']);

                        $oldFinal = is_null($record->final_score) ? null : (float) $record->final_score;

                        $newFinal = array_key_exists('final_score', $data) && !is_null($data['final_score'])
                            ? round((float) $data['final_score'], ScoreRange::PRECISION)
                            : null;

                        $record->update($data);

                        $changed = is_null($oldFinal) !== is_null($newFinal)
                            || (!is_null($oldFinal) && !is_null($newFinal) && abs($oldFinal - $newFinal) > 0.0005);

                        if ($changed) {
                            JudgingLog::record(JudgingLog::ACTION_FINAL_SCORE_CHANGED, [
                                'competition_id' => $livewire->getOwnerRecord()->getKey(),
                                'registration_id' => $record->id,
                                'old_value' => $oldFinal,
                                'new_value' => $newFinal,
                                'reason' => filled($reason) ? $reason : 'Ручная правка в стартовом протоколе',
                            ]);
                        }

                        return $record;
                    }),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
