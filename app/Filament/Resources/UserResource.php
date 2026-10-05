<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Пользователи';

    protected static ?string $modelLabel = 'Пользователь';

    protected static ?string $pluralModelLabel = 'Пользователи';

    protected static ?string $navigationGroup = 'Управление';

    protected static ?int $navigationSort = 1;

    /** Замечание заказчика (01.10): у судей свой набор пунктов меню. */
    public static function shouldRegisterNavigation(): bool
    {
        return ! auth()->user()->isJudge();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Данные пользователя')
                    ->schema([
                        // Замечание заказчика (02.10): вместо заглушек —
                        // круглые аватары, заменяющие заглушки на дашборде.
                        Forms\Components\FileUpload::make('avatar_path')
                            ->label('Фото профиля')
                            ->avatar() // круглая зона загрузки, кроп 1:1
                            ->directory('avatars')
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('name')
                            ->label('Имя')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('password')
                            ->label('Пароль')
                            ->password()
                            ->dehydrated(fn ($state) => filled($state))
                            ->required(fn (string $context): bool => $context === 'create'),

                        // --- РОЛЬ ---
                        // Права в админ-панели зависят от роли: полный набор
                        // пунктов меню («Пользователи», «Клубы», «Пульт Старшего
                        // судьи» и др.) видит только «Администратор».
                        Forms\Components\Select::make('role')
                            ->label('Роль')
                            ->options(User::roleOptions())
                            ->required()
                            ->default('coach')
                            ->helperText('Определяет набор пунктов меню и права пользователя')
                            ->searchable(),

                        // --- ВЫБОР КЛУБА ---
                        Forms\Components\Select::make('club_id')
                            ->relationship('club', 'name')
                            ->label('Клуб')
                            ->helperText('Клуб, за которым закреплён пользователь (тренер). На права доступа не влияет')
                            ->searchable()
                            ->preload(),

                        // --- КАТЕГОРИЯ СУДЬИ ---
                        // Замечание заказчика (02.10): единый словарь категорий —
                        // как в карточке судьи («Судейская коллегия») и на плашке
                        // «Добро пожаловать» дашборда судьи.
                        Forms\Components\Select::make('judge_category')
                            ->label('Судейская категория')
                            ->options(User::judgeCategoryOptions())
                            ->helperText('Категория, присвоенная судье')
                            ->searchable()
                            ->nullable(),
                    ])->columns(2),

                // --- РЕШЕНИЕ ЗАКАЗЧИКА (05.10): НАСТРОЙКИ РАЗДЕЛОВ МЕНЮ ---
                // Переключатели действуют на все роли, кроме администратора:
                // админ видит разделы всегда (тумблеры показываются заблокированными,
                // чтобы настройки не «терялись» при смене роли).
                Forms\Components\Section::make('Разделы меню')
                    ->description('Управляют видимостью разделов «Аналитика», «Сводка оценок» и «Журнал судейства». Администратору они доступны всегда.')
                    ->schema([
                        Forms\Components\Toggle::make('show_analytics')
                            ->label('Аналитика')
                            ->default(true)
                            ->helperText('Включена по умолчанию.')
                            ->disabled(fn ($record): bool => (bool) $record?->isAdmin())
                            ->dehydrated(fn ($record): bool => ! $record?->isAdmin()),

                        Forms\Components\Toggle::make('show_scores_summary')
                            ->label('Сводка оценок')
                            ->default(false)
                            ->disabled(fn ($record): bool => (bool) $record?->isAdmin())
                            ->dehydrated(fn ($record): bool => ! $record?->isAdmin()),

                        Forms\Components\Toggle::make('show_judging_log')
                            ->label('Журнал судейства')
                            ->default(false)
                            ->disabled(fn ($record): bool => (bool) $record?->isAdmin())
                            ->dehydrated(fn ($record): bool => ! $record?->isAdmin()),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('avatar_path')
                    ->label('Фото')
                    ->circular(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Имя')
                    ->searchable(),

                Tables\Columns\TextColumn::make('email')
                    ->searchable(),

                // --- РОЛЬ ---
                Tables\Columns\TextColumn::make('role')
                    ->label('Роль')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => User::roleOptions()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'admin' => 'danger',
                        'head_judge' => 'warning',
                        'judge' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),

                // Клуб пользователя (тренера); если не закреплён — прочерк.
                Tables\Columns\TextColumn::make('club.name')
                    ->label('Клуб')
                    ->placeholder('—')
                    ->sortable()
                    ->badge()
                    ->color(fn ($state) => $state ? 'info' : 'gray'),

                // --- КАТЕГОРИЯ СУДЬИ ---
                Tables\Columns\TextColumn::make('judge_category')
                    ->label('Категория')
                    ->placeholder('—')
                    ->badge()
                    ->color('success')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('d.m.Y H:i')
                    ->label('Создан')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->label('Роль')
                    ->options(User::roleOptions()),

                Tables\Filters\SelectFilter::make('club')
                    ->relationship('club', 'name')
                    ->label('Клуб'),

                Tables\Filters\SelectFilter::make('judge_category')
                    ->label('Категория судьи')
                    ->options(User::judgeCategoryOptions()),
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
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
