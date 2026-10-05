<?php

namespace App\Filament\Resources;

use App\Filament\Resources\JudgeResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;

class JudgeResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationLabel = 'Судейская коллегия';

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $modelLabel = 'Судья';

    protected static ?string $pluralModelLabel = 'Судьи';

    protected static ?string $navigationGroup = 'Справочники';

    /** Замечание заказчика (01.10): у судей свой набор пунктов меню. */
    public static function shouldRegisterNavigation(): bool
    {
        return ! auth()->user()->isJudge();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('role', ['judge', 'head_judge']);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('ФИО Судьи')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('email')
                    ->email()
                    ->required()
                    ->label('Email (Логин)')
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),

                Forms\Components\TextInput::make('password')
                    ->password()
                    ->label('Пароль')
                    ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                    ->dehydrated(fn ($state) => filled($state))
                    ->required(fn (string $context): bool => $context === 'create'),

                Forms\Components\Select::make('role')
                    ->label('Роль в системе')
                    ->options([
                        'judge' => 'Линейный судья',
                        'head_judge' => 'Старший судья (Супер-судья)',
                    ])
                    ->default('judge')
                    ->required(),

                // --- НОВОЕ: КАТЕГОРИЯ СУДЬИ ---
                // Замечание заказчика (02.10): единый словарь категорий —
                // как на плашке «Добро пожаловать» дашборда судьи.
                Forms\Components\Select::make('judge_category')
                    ->label('Судейская категория')
                    ->options(User::judgeCategoryOptions())
                    ->helperText('Категория, присвоенная судье')
                    ->searchable()
                    ->nullable(),

                Forms\Components\Toggle::make('is_active_judge')
                    ->label('Допущен к судейству (Активен)')
                    ->helperText('Включите, чтобы судья мог войти в пульт.')
                    ->default(true)
                    ->columnSpanFull(),

                // Разделы меню («Аналитика», «Сводка оценок», «Журнал судейства»)
                // настраиваются в разделе «Пользователи» (решение заказчика 05.10):
                // переключатели действуют на все роли, админ видит разделы всегда.
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('ФИО')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),

                Tables\Columns\TextColumn::make('role')
                    ->label('Роль')
                    ->badge()
                    ->colors([
                        'info' => 'judge',
                        'danger' => 'head_judge',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'judge' => 'Линейный судья',
                        'head_judge' => 'СТАРШИЙ СУДЬЯ',
                        default => $state,
                    }),

                // --- НОВАЯ КОЛОНКА: КАТЕГОРИЯ ---
                Tables\Columns\TextColumn::make('judge_category')
                    ->label('Категория')
                    ->badge()
                    ->placeholder('—')
                    ->sortable()
                    ->color(fn (?string $state): string => match ($state) {
                        'ССМК' => 'success',   // зелёный — международная
                        'ССВК' => 'success',   // зелёный — высшая
                        'СС1К' => 'warning',   // оранжевый — первая
                        'СС2К' => 'info',      // синий — вторая
                        'СС3К' => 'gray',      // серый — третья
                        'ЮС' => 'gray',      // юный судья
                        default => 'gray',
                    }),

                Tables\Columns\IconColumn::make('is_active_judge')
                    ->label('Активен')
                    ->boolean(),
            ])
            ->filters([
                // --- НОВЫЙ ФИЛЬТР: ПО КАТЕГОРИИ ---
                Tables\Filters\SelectFilter::make('judge_category')
                    ->label('Категория')
                    ->options([
                        'ССМК' => 'ССМК',
                        'ССВК' => 'ССВК',
                        'СС1К' => 'СС1К',
                        'СС2К' => 'СС2К',
                        'СС3К' => 'СС3К',
                        'ЮС' => 'ЮС',
                    ]),

                // --- ФИЛЬТР ПО РОЛИ ---
                Tables\Filters\SelectFilter::make('role')
                    ->label('Роль')
                    ->options([
                        'judge' => 'Линейный судья',
                        'head_judge' => 'Старший судья',
                    ]),

                // --- ФИЛЬТР ПО АКТИВНОСТИ ---
                Tables\Filters\TernaryFilter::make('is_active_judge')
                    ->label('Активен'),
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
            'index' => Pages\ListJudges::route('/'),
            'create' => Pages\CreateJudge::route('/create'),
            'edit' => Pages\EditJudge::route('/{record}/edit'),
        ];
    }
}
