<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StyleResource\Pages;
use App\Models\Style;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class StyleResource extends Resource
{
    protected static ?string $model = Style::class;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationLabel = 'Виды программы';

    protected static ?string $modelLabel = 'Вид';

    protected static ?string $pluralModelLabel = 'Виды программы';

    protected static ?string $navigationGroup = 'Справочники';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->label('Название вида')
                    ->placeholder('Например: Чанцюань'),

                Forms\Components\Select::make('category')
                    // Единый канон названий категорий — константа Style::CATEGORIES
                    ->options(Style::CATEGORIES)
                    ->required()
                    ->default('taolu')
                    ->label('Категория'),

                Forms\Components\TextInput::make('sort_order')
                    ->numeric()
                    ->default(0)
                    ->label('Порядок в протоколе')
                    ->helperText('Укажите цифру: 1 - выступают первыми, 2 - вторыми и т.д.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('№ пор.')
                    ->sortable()
                    ->alignCenter()
                    ->width(80),

                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->label('Вид'),

                Tables\Columns\TextColumn::make('category')
                    ->badge()
                    ->colors([
                        // ИЗМЕНЕНИЕ 2: Цвета значков
                        'success' => 'taolu',
                        'info' => 'traditional',
                        'warning' => 'yongchun', // Дадим Юнчуню желтый/оранжевый цвет
                    ])
                    // Единый канон названий категорий — константа Style::CATEGORIES
                    ->formatStateUsing(fn (string $state): string => Style::categoryLabel($state))
                    ->label('Категория'),
            ])
            ->defaultSort('sort_order', 'asc')
            ->filters([
                // ИЗМЕНЕНИЕ 4: Фильтр справа
                Tables\Filters\SelectFilter::make('category')
                    // Единый канон названий категорий — константа Style::CATEGORIES
                    ->options(Style::CATEGORIES)
                    ->label('Категория'),
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
            'index' => Pages\ListStyles::route('/'),
            'create' => Pages\CreateStyle::route('/create'),
            'edit' => Pages\EditStyle::route('/{record}/edit'),
        ];
    }
}
