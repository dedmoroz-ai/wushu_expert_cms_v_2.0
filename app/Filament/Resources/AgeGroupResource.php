<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AgeGroupResource\Pages;
use App\Models\AgeGroup;
use App\Support\ScoreRange;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AgeGroupResource extends Resource
{
    protected static ?string $model = AgeGroup::class;

    protected static ?string $navigationIcon = 'heroicon-o-users'; // Иконка
    
    protected static ?string $navigationLabel = 'Возрастные группы';
    protected static ?string $modelLabel = 'Группа';
    protected static ?string $pluralModelLabel = 'Возрастные группы';
    protected static ?string $navigationGroup = 'Справочники';
    protected static ?int $navigationSort = 2;


    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->label('Название')
                    ->placeholder('Например: Юниоры'),

                Forms\Components\Select::make('gender')
                    ->options([
                        'male' => 'Мужчины / Мальчики',
                        'female' => 'Женщины / Девочки',
                        'mixed' => 'Смешанная',
                    ])
                    ->required()
                    ->label('Пол'),
                
                // --- НОВОЕ ПОЛЕ: ПОРЯДОК СОРТИРОВКИ ---
                Forms\Components\TextInput::make('sort_order')
                    ->numeric()
                    ->default(0)
                    ->label('Порядок в протоколе')
                    ->helperText('Укажите цифру: 1 - выступают первыми, 2 - вторыми и т.д.'),

                Forms\Components\Grid::make(2)
                    ->schema([
                        Forms\Components\TextInput::make('min_age')
                            ->numeric()
                            ->required()
                            ->label('Мин. возраст')
                            ->suffix('лет'),
                        
                        Forms\Components\TextInput::make('max_age')
                            ->numeric()
                            ->required()
                            ->label('Макс. возраст')
                            ->suffix('лет'),
                    ]),

                // --- ЛИМИТЫ БАЛЛОВ (правила 8.2, 8.3) ---
                Forms\Components\Fieldset::make('Лимиты оценок для этой категории')
                    ->schema([
                        Forms\Components\TextInput::make('min_score')
                            ->numeric()
                            ->step(0.001)
                            ->minValue(ScoreRange::GLOBAL_MIN)
                            ->maxValue(ScoreRange::GLOBAL_MAX)
                            ->label('Мин. балл')
                            ->placeholder(number_format(ScoreRange::GLOBAL_MIN, 3, '.', ''))
                            ->helperText('Пусто = общесистемный минимум ' . number_format(ScoreRange::GLOBAL_MIN, 3, '.', '')),

                        Forms\Components\TextInput::make('max_score')
                            ->numeric()
                            ->step(0.001)
                            ->minValue(ScoreRange::GLOBAL_MIN)
                            ->maxValue(ScoreRange::GLOBAL_MAX)
                            ->label('Макс. балл')
                            ->placeholder(number_format(ScoreRange::GLOBAL_MAX, 3, '.', ''))
                            ->helperText('Пусто = общесистемный максимум ' . number_format(ScoreRange::GLOBAL_MAX, 3, '.', ''))
                            ->gte('min_score'),
                    ])
                    ->columns(2),

                // --- ЛИМИТЫ СУДЕЙ B (правило R-4.19, сценарий A/B) ---
                Forms\Components\Fieldset::make('Сценарий A/B: лимиты оценок судей B (шкала 0–5)')
                    ->schema([
                        Forms\Components\TextInput::make('b_min_score')
                            ->numeric()
                            ->step(0.001)
                            ->minValue(ScoreRange::GLOBAL_MIN)
                            ->maxValue(ScoreRange::PANEL_MAX)
                            ->label('Мин. балл судьи B')
                            ->placeholder(number_format(ScoreRange::GLOBAL_MIN, 3, '.', ''))
                            ->helperText('Пусто = мин. балл категории (не выше 5.000) или 0.000'),

                        Forms\Components\TextInput::make('b_max_score')
                            ->numeric()
                            ->step(0.001)
                            ->minValue(ScoreRange::GLOBAL_MIN)
                            ->maxValue(ScoreRange::PANEL_MAX)
                            ->label('Макс. балл судьи B')
                            ->placeholder(number_format(ScoreRange::PANEL_MAX, 3, '.', ''))
                            ->helperText('Пусто = макс. балл категории (не выше 5.000) или 5.000')
                            ->gte('b_min_score'),

                        Forms\Components\Placeholder::make('a_panel_note')
                            ->label('Судьи A')
                            ->content('Всегда от 5.000 вниз по кодам сбавок (0.000–5.000), лимиты категории не применяются.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // --- НОВАЯ КОЛОНКА: № ПОРЯДКА ---
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('№ пор.')
                    ->sortable()
                    ->alignCenter()
                    ->width(80),

                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->weight('bold')
                    ->label('Название группы'),

                Tables\Columns\TextColumn::make('gender')
                    ->badge()
                    ->colors([
                        'info' => 'male',
                        'danger' => 'female',
                        'success' => 'mixed',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'male' => 'Муж.',
                        'female' => 'Жен.',
                        'mixed' => 'Смеш.',
                        default => $state,
                    })
                    ->label('Пол'),

                Tables\Columns\TextColumn::make('min_age')
                    ->sortable()
                    ->label('От (возраст)'),

                Tables\Columns\TextColumn::make('max_age')
                    ->label('До (возраст)'),

                // Правила 8.2, 8.3: видимые лимиты оценок категории
                Tables\Columns\TextColumn::make('score_limits')
                    ->label('Лимит баллов')
                    ->badge()
                    ->color('warning')
                    ->getStateUsing(fn (AgeGroup $record): string => ScoreRange::forAgeGroup($record)->label()),

                Tables\Columns\TextColumn::make('b_score_limits')
                    ->label('Лимит B (A/B)')
                    ->badge()
                    ->color('info')
                    ->toggleable()
                    ->getStateUsing(fn (AgeGroup $record): string => ScoreRange::forPanel($record, 'B')->label()),
            ])
            ->defaultSort('sort_order', 'asc') // Сортируем таблицу по этому полю
            ->filters([
                Tables\Filters\SelectFilter::make('gender')
                    ->options([
                        'male' => 'Мужчины',
                        'female' => 'Женщины',
                    ])
                    ->label('Пол'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAgeGroups::route('/'),
            'create' => Pages\CreateAgeGroup::route('/create'),
            'edit' => Pages\EditAgeGroup::route('/{record}/edit'),
        ];
    }
}
