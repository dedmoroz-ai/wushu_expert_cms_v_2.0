<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AthleteResource\Pages;
use App\Models\Athlete;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AthleteResource extends Resource
{
    protected static ?string $model = Athlete::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    // Русские названия в меню
    protected static ?string $navigationLabel = 'Спортсмены';
    protected static ?string $modelLabel = 'Спортсмен';
    protected static ?string $pluralModelLabel = 'Спортсмены';
    
    // Группа в меню
    protected static ?string $navigationGroup = 'Справочники';

    // --- СКРЫВАЕМ ОТ СУДЕЙ ---
    public static function shouldRegisterNavigation(): bool
    {
        return ! auth()->user()->isJudge();
    }

    // --- 1. ФИЛЬТРАЦИЯ (Скрываем чужих спортсменов) ---
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // Если это тренер (есть club_id), фильтруем список
        if (auth()->check() && auth()->user()->club_id) {
            $query->where('club_id', auth()->user()->club_id);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Карточка спортсмена')
                    ->description('Личные данные и клубная принадлежность')
                    ->schema([
                        // --- 2. УМНОЕ ПОЛЕ КЛУБА ---
                        Forms\Components\Select::make('club_id')
                            ->relationship('club', 'name')
                            ->label('Клуб')
                            ->required()
                            ->searchable()
                            ->preload()
                            // Если это тренер - ставим его клуб автоматически
                            ->default(fn () => auth()->user()->club_id)
                            // Если это тренер - запрещаем менять клуб
                            ->disabled(fn () => auth()->user()->club_id !== null)
                            // dehydrated нужен, чтобы значение сохранилось, даже если поле disabled
                            ->dehydrated(),

                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->label('ФИО'),

                        Forms\Components\Select::make('gender')
                            ->options([
                                'male' => 'Мужской',
                                'female' => 'Женский',
                            ])
                            ->required()
                            ->label('Пол'),

                        Forms\Components\DatePicker::make('birth_date')
                            ->required()
                            ->label('Дата рождения')
                            ->native(false) // Красивый календарь
                            ->displayFormat('d.m.Y'),

                        Forms\Components\TextInput::make('rank')
                            ->maxLength(255)
                            ->placeholder('Например: 1 дуань, КМС')
                            ->label('Разряд'),

                        // Группа «O» (особые спортсмены) — R-6.15, п. 9.16.
                        // Значение по умолчанию: при подаче заявки тренер может
                        // оставить/снять отметку для конкретного соревнования.
                        Forms\Components\Toggle::make('is_special')
                            ->label('Группа «O» (особые спортсмены)')
                            ->helperText('Выступают в отдельном зачёте внутри своей номинации. Финальная отметка — в заявке.')
                            ->default(false),
                    ])
                    ->columns(2), // Разделяем на 2 колонки
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->label('ФИО'),

                Tables\Columns\TextColumn::make('club.name')
                    ->searchable()
                    ->sortable()
                    ->label('Клуб'),

                // --- 3. ИСПРАВЛЕННАЯ КОЛОНКА ПОЛ ---
                Tables\Columns\TextColumn::make('gender')
                    ->label('Пол')
                    // Текст
                    ->formatStateUsing(function (string $state): string {
                        return match (mb_strtolower($state)) {
                            'male', 'm', 'man', 'мужской', 'муж', 'м' => 'Мужской',
                            'female', 'f', 'woman', 'женский', 'жен', 'ж' => 'Женский',
                            default => $state,
                        };
                    })
                    // Иконка
                    ->icon(function (string $state): string {
                        return match (mb_strtolower($state)) {
                            'male', 'm', 'man', 'мужской', 'муж', 'м' => 'heroicon-m-user',
                            'female', 'f', 'woman', 'женский', 'жен', 'ж' => 'heroicon-m-user',
                            default => 'heroicon-o-question-mark-circle',
                        };
                    })
                    // Цвет
                    ->color(function (string $state): string {
                        return match (mb_strtolower($state)) {
                            'male', 'm', 'man', 'мужской', 'муж', 'м' => 'info',   // Синий
                            'female', 'f', 'woman', 'женский', 'жен', 'ж' => 'danger', // Красный
                            default => 'gray',
                        };
                    }),

                Tables\Columns\TextColumn::make('birth_date')
                    ->date('d.m.Y')
                    ->sortable()
                    ->label('Дата рождения'),

                // Подсчет возраста
                Tables\Columns\TextColumn::make('age')
                    ->label('Возраст')
                    ->state(function (Athlete $record): string {
                        if (!$record->birth_date) return '-';
                        $age = now()->year - $record->birth_date->year;
                        return $age . ' лет';
                    }),

                Tables\Columns\TextColumn::make('rank')
                    ->label('Разряд'),

                // Группа «O» (особые спортсмены) — R-6.15, п. 9.16.
                Tables\Columns\IconColumn::make('is_special')
                    ->label('Группа O')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                // ВОТ ЗДЕСЬ БЫЛА ОШИБКА, Я ИСПРАВИЛ:
                Tables\Filters\SelectFilter::make('gender')
                    ->options([
                        'male' => 'Мужчины',
                        'female' => 'Женщины',
                    ])
                    ->label('Пол'),

                // Фильтр: показать спортсменов конкретного клуба
                Tables\Filters\SelectFilter::make('club')
                    ->relationship('club', 'name')
                    ->label('Клуб'),

                // Группа «O» (особые спортсмены) — R-6.15, п. 9.16.
                Tables\Filters\TernaryFilter::make('is_special')
                    ->label('Группа O')
                    ->placeholder('Все')
                    ->trueLabel('Только группа O')
                    ->falseLabel('Без группы O'),
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

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAthletes::route('/'),
            'create' => Pages\CreateAthlete::route('/create'),
            'edit' => Pages\EditAthlete::route('/{record}/edit'),
        ];
    }
}
