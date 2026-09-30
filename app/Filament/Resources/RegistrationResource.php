<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RegistrationResource\Pages;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\Style;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Closure;
use Illuminate\Database\Eloquent\Builder;

class RegistrationResource extends Resource
{
    protected static ?string $model = Registration::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static ?string $navigationLabel = 'Заявки';
    protected static ?string $modelLabel = 'Заявка';
    protected static ?string $pluralModelLabel = 'Заявки';
    protected static ?string $navigationGroup = 'Турнир';
    protected static ?int $navigationSort = 5;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (auth()->check() && auth()->user()->club_id) {
            return $query->whereHas('athlete', function ($q) {
                $q->where('club_id', auth()->user()->club_id);
            });
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        // --- ПРАВИЛО ВАЛИДАЦИИ (Лимит) ---
        $maxEventsRule = function (Get $get) {
            return function (string $attribute, $value, Closure $fail) use ($get) {
                $competitionId = $get('competition_id');
                if (!$competitionId) return;

                $competition = Competition::find($competitionId);
                if (!$competition) return;
                
                $limit = $competition->max_events ?? 2; 

                $taolu = $get('events_taolu_virtual') ?? [];
                $trad = $get('events_trad_virtual') ?? [];
                
                $allSelectedIds = array_unique(array_merge($taolu, $trad));
                
                if (empty($allSelectedIds)) return;

                // Лимит: Дуйлянь не считается
                $duilianCount = Style::whereIn('id', $allSelectedIds)
                    ->where('name', 'like', '%Дуйлянь%') 
                    ->count();

                $totalCount = count($allSelectedIds);
                $cleanCount = $totalCount - $duilianCount;

                if ($cleanCount > $limit) {
                    $fail("Лимит превышен! Разрешено видов: {$limit}. Вы выбрали: {$cleanCount} (Дуйлянь не считается).");
                }
            };
        };

        // --- ПРАВИЛО ВАЛИДАЦИИ (Пара «O / не O» невозможна) — R-6.15 ---
        // Отметки карточек основного и партнёра должны совпадать:
        // либо оба «O», либо оба не «O».
        $partnerSpecialRule = function (Get $get) {
            return function (string $attribute, $value, Closure $fail) use ($get) {
                if (!$value) return;

                $main = \App\Models\Athlete::find($get('athlete_id'));
                $partner = \App\Models\Athlete::find($value);

                if ($main && $partner && (bool) $main->is_special !== (bool) $partner->is_special) {
                    $fail('Пара «O / не O» невозможна: отметки карточек основного и партнёра должны совпадать (оба «O» или оба не «O»).');
                }
            };
        };

        return $form
            ->schema([
                Forms\Components\Section::make('Данные заявки')
                    ->schema([
                        Forms\Components\Select::make('competition_id')
                            ->relationship('competition', 'name')
                            ->label('Соревнование')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live(),

                        Forms\Components\Select::make('athlete_id')
                            ->relationship('athlete', 'name', function ($query) {
                                if (auth()->user()->club_id) {
                                    return $query->where('club_id', auth()->user()->club_id);
                                }
                                return $query;
                            })
                            ->label('Спортсмен')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->helperText('Начните вводить имя...')
                            // Группа «O» (R-6.15): авто-галочка из карточки спортсмена
                            // (значение по умолчанию; финальная отметка — поле ниже).
                            ->afterStateUpdated(function (Set $set, ?int $state) {
                                $set('is_special', (bool) \App\Models\Athlete::find($state)?->is_special);
                            }),

                        // Группа «O» (особые спортсмены) — R-6.15, п. 9.16:
                        // финальная отметка под конкретное соревнование.
                        Forms\Components\Toggle::make('is_special')
                            ->label('Группа «O» (особые спортсмены)')
                            ->helperText('Отдельный зачёт внутри номинации. Значение по умолчанию — из карточки спортсмена.')
                            ->default(false),
                    ])->columns(2),

                // --- СЕКЦИЯ 1: ТАОЛУ ---
                Forms\Components\Section::make('Спортивное Ушу (Таолу)')
                    ->collapsible()
                    ->schema([
                        Forms\Components\CheckboxList::make('events_taolu_virtual')
                            ->hiddenLabel()
                            ->options(
                                Style::where('category', 'taolu')
                                     ->orderBy('sort_order', 'asc')
                                     ->orderBy('name', 'asc')
                                     ->pluck('name', 'id')
                            )
                            ->columns(1)
                            ->extraAttributes([
                                'style' => 'column-count: 3; column-gap: 2rem; display: block;',
                                'class' => '[&_label]:break-inside-avoid [&_label]:mb-2',
                            ])
                            ->bulkToggleable()
                            ->searchable()
                            ->live()
                            ->rules([$maxEventsRule]),
                    ]),

                // --- СЕКЦИЯ 2: ТРАДИЦИОННОЕ ---
                Forms\Components\Section::make('Традиционное Ушу')
                    ->collapsible()
                    ->schema([
                        Forms\Components\CheckboxList::make('events_trad_virtual')
                            ->hiddenLabel()
                            ->options(
                                Style::where('category', 'traditional')
                                     ->orderBy('sort_order', 'asc')
                                     ->orderBy('name', 'asc')
                                     ->pluck('name', 'id')
                            )
                            ->columns(1)
                            ->extraAttributes([
                                'style' => 'column-count: 3; column-gap: 2rem; display: block;',
                                'class' => '[&_label]:break-inside-avoid [&_label]:mb-2',
                            ])
                            ->bulkToggleable()
                            ->searchable()
                            ->live()
                            ->rules([$maxEventsRule]),
                    ]),

                // --- ПОЛЕ 1: ПАРТНЕР ДЛЯ ДУЙЛЯНЬ ---
                Forms\Components\Section::make('Дуйлянь (Пара)')
                    ->schema([
                        Forms\Components\Select::make('partner_duilian_virtual') // УНИКАЛЬНОЕ ИМЯ 1
                            ->label('Партнер для Дуйлянь')
                            ->required()
                            ->searchable()
                            ->preload()
                            // R-6.15: пара «O / не O» невозможна.
                            ->rules([$partnerSpecialRule])
                            ->options(function (Get $get) {
                                $query = \App\Models\Athlete::query();
                                if (auth()->check() && auth()->user()->club_id) {
                                    $query->where('club_id', auth()->user()->club_id);
                                }
                                $currentAthleteId = $get('athlete_id');
                                if ($currentAthleteId) {
                                    $query->where('id', '!=', $currentAthleteId);
                                }
                                return $query->pluck('name', 'id');
                            }),
                    ])
                    ->visible(function (Get $get) {
                        $taolu = $get('events_taolu_virtual') ?? [];
                        $trad = $get('events_trad_virtual') ?? [];
                        $allSelectedIds = array_merge($taolu, $trad);

                        if (empty($allSelectedIds)) return false;

                        // Показываем, если есть слово "Дуйлянь"
                        return Style::whereIn('id', $allSelectedIds)
                            ->where('name', 'like', '%Дуйлянь%')
                            ->exists();
                    }),

                // --- ПОЛЕ 2: ПАРТНЕР ДЛЯ ГУЙДИН ДУЙДА ---
                Forms\Components\Section::make('Гуйдин Дуйда (Пара)')
                    ->schema([
                        Forms\Components\Select::make('partner_duida_virtual') // УНИКАЛЬНОЕ ИМЯ 2
                            ->label('Партнер для Гуйдин Дуйда')
                            ->required()
                            ->searchable()
                            ->preload()
                            // R-6.15: пара «O / не O» невозможна.
                            ->rules([$partnerSpecialRule])
                            ->options(function (Get $get) {
                                $query = \App\Models\Athlete::query();
                                if (auth()->check() && auth()->user()->club_id) {
                                    $query->where('club_id', auth()->user()->club_id);
                                }
                                $currentAthleteId = $get('athlete_id');
                                if ($currentAthleteId) {
                                    $query->where('id', '!=', $currentAthleteId);
                                }
                                return $query->pluck('name', 'id');
                            }),
                    ])
                    ->visible(function (Get $get) {
                        $taolu = $get('events_taolu_virtual') ?? [];
                        $trad = $get('events_trad_virtual') ?? [];
                        $allSelectedIds = array_merge($taolu, $trad);

                        if (empty($allSelectedIds)) return false;

                        // Показываем, если есть слово "Дуйда"
                        return Style::whereIn('id', $allSelectedIds)
                            ->where('name', 'like', '%Дуйда%')
                            ->exists();
                    }),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('competition.name')
                    ->label('Турнир')
                    ->wrap()
                    ->searchable(),

                Tables\Columns\TextColumn::make('athlete.name')
                    ->label('Спортсмен')
                    ->weight('bold')
                    ->wrap()
                    ->searchable(),

                Tables\Columns\TextColumn::make('partner.name')
                    ->label('Партнер')
                    ->placeholder('-')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('age_group_label')
                    ->label('Категория')
                    ->color('info')
                    ->wrap()
                    ->sortable(),

                // Группа «O» (особые спортсмены) — R-6.15, п. 9.16.
                Tables\Columns\IconColumn::make('is_special')
                    ->label('Группа O')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                
                Tables\Columns\TextColumn::make('athlete.club.name')
                    ->label('Клуб')
                    ->wrap()
                    ->sortable(),

                Tables\Columns\TextColumn::make('style.name')
                    ->label('Дисциплина')
                    ->weight('black')
                    ->sortable()
                    ->searchable()
                    ->placeholder('-'), 

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('d.m.Y')
                    ->label('Дата')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('competition')
                    ->relationship('competition', 'name')
                    ->label('Турнир'),

                Tables\Filters\SelectFilter::make('age_group_label')
                    ->label('Категория')
                    ->options(fn() => Registration::distinct()->pluck('age_group_label', 'age_group_label')->toArray()),
                
                Tables\Filters\SelectFilter::make('style')
                    ->relationship('style', 'name')
                    ->label('Дисциплина')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRegistrations::route('/'),
            'create' => Pages\CreateRegistration::route('/create'),
            'edit' => Pages\EditRegistration::route('/{record}/edit'),
        ];
    }
}
