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

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Данные пользователя')
                    ->schema([
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

                        // --- ВЫБОР КЛУБА ---
                        Forms\Components\Select::make('club_id')
                            ->relationship('club', 'name')
                            ->label('Клуб')
                            ->helperText('Если оставить пустым — пользователь будет полным АДМИНИСТРАТОРОМ')
                            ->searchable()
                            ->preload(),

                        // --- КАТЕГОРИЯ СУДЬИ ---
                        Forms\Components\Select::make('judge_category')
                            ->label('Судейская категория')
                            ->options([
                                'ССВК' => 'Всероссийская категория (ССВК)',
                                'СС1К' => 'Первая категория (СС1К)',
                                'СС2К' => 'Вторая категория (СС2К)',
                                'СС3К' => 'Третья категория (СС3К)',
                                'ЮС'   => 'Юный судья (ЮС)',
                                'ССМК' => 'Международная категория (ССМК)',
                            ])
                            ->helperText('Категория, присвоенная судье')
                            ->searchable()
                            ->nullable(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Имя')
                    ->searchable(),

                Tables\Columns\TextColumn::make('email')
                    ->searchable(),

                // Показываем клуб. Если пусто — пишем "Администратор"
                Tables\Columns\TextColumn::make('club.name')
                    ->label('Клуб / Роль')
                    ->placeholder('Администратор')
                    ->sortable()
                    ->badge()
                    ->color(fn ($state) => $state ? 'info' : 'danger'),

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
                Tables\Filters\SelectFilter::make('club')
                    ->relationship('club', 'name')
                    ->label('Клуб'),

                Tables\Filters\SelectFilter::make('judge_category')
                    ->label('Категория судьи')
                    ->options([
                        'ССВК' => 'ССВК',
                        'СС1К' => 'СС1К',
                        'СС2К' => 'СС2К',
                        'СС3К' => 'СС3К',
                        'ЮС'   => 'ЮС',
                        'ССМК' => 'ССМК',
                    ]),
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