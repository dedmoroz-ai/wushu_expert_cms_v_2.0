<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DeductionCodeResource\Pages;
use App\Models\DeductionCode;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Правило R-7.8 (docs/JUDGING_RULES.md): глобальный справочник кодов сбавок
 * судьи A (сценарий A/B).
 */
class DeductionCodeResource extends Resource
{
    protected static ?string $model = DeductionCode::class;

    protected static ?string $navigationIcon = 'heroicon-o-minus-circle';

    protected static ?string $navigationLabel = 'Коды сбавок (судья A)';

    protected static ?string $modelLabel = 'Код сбавки';

    protected static ?string $pluralModelLabel = 'Коды сбавок';

    protected static ?string $navigationGroup = 'Справочники';

    protected static ?int $navigationSort = 3;

    /**
     * Замечание заказчика (01.10): у судей свой набор пунктов меню,
     * «Коды сбавок» в него не входит — в меню раздел видит только админ.
     * Сам доступ старшего судьи к странице сохранён (памятки, работа с кодами).
     */
    public static function shouldRegisterNavigation(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        return $user && $user->isAdmin();
    }

    public static function canViewAny(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        return $user && ($user->isAdmin() || $user->isHeadJudge());
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('code')
                    ->label('Код')
                    ->required()
                    ->maxLength(20)
                    ->unique(ignoreRecord: true)
                    ->placeholder('Например: 11'),

                Forms\Components\TextInput::make('value')
                    ->label('Сбавка, баллов')
                    ->required()
                    ->numeric()
                    ->step(0.001)
                    ->minValue(0.001)
                    ->maxValue(5)
                    ->default(0.1)
                    ->helperText('Обычно от 0.100 до 0.500. Один код можно нажать не более 2 раз за выступление.'),

                Forms\Components\TextInput::make('label')
                    ->label('Описание ошибки')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),

                Forms\Components\TextInput::make('group_label')
                    ->label('Группа')
                    ->maxLength(255)
                    ->placeholder('Например: Равновесие, Прыжки'),

                Forms\Components\TextInput::make('sort_order')
                    ->label('Порядок на пульте')
                    ->numeric()
                    ->default(0),

                Forms\Components\Toggle::make('is_active')
                    ->label('Активен (показывать на пульте)')
                    ->default(true)
                    ->inline(false),
            ])
            ->columns(2);
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

                Tables\Columns\TextColumn::make('code')
                    ->label('Код')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('value')
                    ->label('Сбавка')
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => '−'.number_format((float) $state, 3, '.', '')),

                Tables\Columns\TextColumn::make('label')
                    ->label('Описание')
                    ->wrap()
                    ->searchable(),

                Tables\Columns\TextColumn::make('group_label')
                    ->label('Группа')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean(),
            ])
            ->defaultSort('sort_order', 'asc')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Активен'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                // Удаление безопасно: у выставленных оценок хранится снимок кода.
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
            'index' => Pages\ListDeductionCodes::route('/'),
            'create' => Pages\CreateDeductionCode::route('/create'),
            'edit' => Pages\EditDeductionCode::route('/{record}/edit'),
        ];
    }
}
