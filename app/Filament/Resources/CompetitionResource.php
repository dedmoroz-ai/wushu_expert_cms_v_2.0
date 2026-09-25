<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CompetitionResource\Pages;
use App\Filament\Resources\CompetitionResource\RelationManagers;
use App\Models\Competition;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;

class CompetitionResource extends Resource
{
    protected static ?string $model = Competition::class;

    protected static ?string $navigationIcon = 'heroicon-o-trophy';
    
    protected static ?string $navigationLabel = 'Соревнования';
    protected static ?string $modelLabel = 'Соревнование';
    protected static ?string $pluralModelLabel = 'Соревнования';
    protected static ?string $navigationGroup = 'Турнир';
    protected static ?int $navigationSort = 3;

    // --- СКРЫВАЕМ ОТ СУДЕЙ ---
    public static function shouldRegisterNavigation(): bool
    {
        // Меню видят все (Админы, Тренеры), КРОМЕ судей
        return ! auth()->user()->isJudge();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // СЕКЦИЯ 1: Основные данные
                Forms\Components\Section::make('Основная информация')
                    ->schema([
                        // Выбор Федерации (Организатора)
                        Forms\Components\Select::make('federation_id')
                            ->relationship('federation', 'name')
                            ->label('Организатор (Федерация)')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->label('Название турнира')
                            ->columnSpanFull(),

                        // Даты в одной строке
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\DatePicker::make('start_date')
                                    ->required()
                                    ->label('Дата начала'),

                                Forms\Components\DatePicker::make('end_date')
                                    ->label('Дата окончания')
                                    ->afterOrEqual('start_date'),
                            ]),

                        // Место проведения в одной строке
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('city')
                                    ->required()
                                    ->label('Город'),

                                Forms\Components\TextInput::make('address')
                                    ->label('Адрес (Спорткомплекс)'),
                            ]),
                    ])
                    ->collapsible() 
                    ->collapsed(),  // Свернуто по умолчанию

                // --- СЕКЦИЯ 2: ОФОРМЛЕНИЕ И ПЕЧАТЬ ---
                Forms\Components\Section::make('Официальные лица и Оформление (PDF)')
                    ->description('Загрузите логотипы и подписи для формирования протоколов.')
                    ->schema([
                        // РЯД 1: Логотип и Печать
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\FileUpload::make('organization_logo')
                                    ->label('Логотип Федерации/Турнира')
                                    ->image()
                                    ->directory('competitions/logos')
                                    ->imagePreviewHeight('100')
                                    ->columnSpan(1),

                                Forms\Components\FileUpload::make('organization_stamp')
                                    ->label('Оттиск печати (синяя)')
                                    ->image()
                                    ->directory('competitions/stamps')
                                    ->imagePreviewHeight('100')
                                    ->columnSpan(1),
                            ]),

                        Forms\Components\Group::make()->columnSpanFull()->extraAttributes(['class' => 'border-t my-4']), // Разделитель

                        // РЯД 2: Главный судья
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('chief_judge_name')
                                    ->label('ФИО Главного судьи')
                                    ->placeholder('Иванов И.И.')
                                    ->required(), 

                                Forms\Components\FileUpload::make('chief_judge_signature')
                                    ->label('Факсимиле Главного судьи')
                                    ->image()
                                    ->directory('competitions/signatures')
                                    ->imagePreviewHeight('60'),
                            ]),

                        // РЯД 3: Главный секретарь
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('chief_secretary_name')
                                    ->label('ФИО Главного секретаря')
                                    ->placeholder('Петрова А.А.')
                                    ->required(),

                                Forms\Components\FileUpload::make('chief_secretary_signature')
                                    ->label('Факсимиле Главного секретаря')
                                    ->image()
                                    ->directory('competitions/signatures')
                                    ->imagePreviewHeight('60'),
                            ]),
                    ])
                    ->collapsible()
                    ->collapsed(),

                // СЕКЦИЯ 3: Регламент и ограничения
                Forms\Components\Section::make('Регламент и ограничения')
                    ->schema([
                        Forms\Components\TextInput::make('max_events')
                            ->label('Лимит видов (индивидуальных)')
                            ->numeric()
                            ->default(2)
                            ->minValue(1)
                            ->required()
                            ->helperText('Сколько дисциплин может выбрать один спортсмен (не считая Дуйлянь).'),

                        Forms\Components\Toggle::make('has_duilian')
                            ->label('Включить дисциплины Дуйлянь')
                            ->inline(false)
                            ->default(false),

                        // Правило R-2.11: сценарий судейства турнира.
                        Forms\Components\Select::make('judging_scheme')
                            ->label('Сценарий судейства')
                            ->options(Competition::schemeLabels())
                            ->default(Competition::SCHEME_SIMPLE)
                            ->required()
                            ->native(false)
                            ->helperText('A/B: назначьте каждому судье функцию A или B во вкладке «Судейская бригада». Не меняйте сценарий во время турнира.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->collapsible() 
                    ->collapsed(),
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
                    ->label('Название')
                    ->wrap(),

                Tables\Columns\TextColumn::make('federation.name')
                    ->label('Организатор')
                    ->sortable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('city')
                    ->searchable()
                    ->label('Город'),

                Tables\Columns\TextColumn::make('start_date')
                    ->date('d.m.Y')
                    ->sortable()
                    ->label('Начало'),
                    
                Tables\Columns\TextColumn::make('end_date')
                    ->date('d.m.Y')
                    ->label('Конец'),
            ])
            ->filters([
                //
            ])
            ->actions([
                // --- КНОПКА ПЕРЕХОДА В ПУЛЬТ (НОВАЯ) ---
                Tables\Actions\Action::make('manage')
                    ->label('Пульт!')
                    ->icon('heroicon-o-computer-desktop')
                    ->color('warning') // Желтая кнопка
                    ->url(fn (Competition $record): string => Pages\ManageCompetition::getUrl(['record' => $record])),

                Tables\Actions\EditAction::make(),

                // --- КНОПКА ГЕНЕРАЦИИ ПРОТОКОЛА ---
                Tables\Actions\Action::make('generate_protocol')
                    ->label('Протокол')
                    ->icon('heroicon-o-document-text')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Сформировать стартовый протокол')
                    ->modalDescription('Система расставит всех спортсменов по порядку: Стиль -> Возраст -> Пол -> Алфавит. Старые номера жеребьевки будут перезаписаны.')
                    ->action(function (Competition $record) {
                        $registrations = $record->registrations()
                            ->with(['style', 'ageGroup', 'athlete'])
                            ->get();

                        $sorted = $registrations->sortBy([
                            ['style.sort_order', 'asc'],    
                            ['ageGroup.sort_order', 'asc'], 
                            ['athlete.gender', 'desc'],     
                            ['athlete.name', 'asc'],        
                        ]);

                        $counter = 1;
                        foreach ($sorted as $reg) {
                            $reg->update([
                                'sort_order' => $counter++,
                                'status' => 0,
                                'score' => null
                            ]);
                        }
                        
                        Notification::make()
                            ->title('Протокол сформирован!')
                            ->body("Упорядочено участников: " . ($counter - 1))
                            ->success()
                            ->send();
                    }),
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
            RelationManagers\RegistrationsRelationManager::class,
            RelationManagers\JudgesRelationManager::class, // <--- ДОБАВИЛ СЮДА
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCompetitions::route('/'),
            'create' => Pages\CreateCompetition::route('/create'),
            'edit' => Pages\EditCompetition::route('/{record}/edit'),
            
            // --- НОВЫЙ МАРШРУТ ДЛЯ ПУЛЬТА ---
            'manage' => Pages\ManageCompetition::route('/{record}/manage'),
        ];
    }
}
