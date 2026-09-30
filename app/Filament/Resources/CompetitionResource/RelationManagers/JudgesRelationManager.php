<?php

namespace App\Filament\Resources\CompetitionResource\RelationManagers;

use App\Models\Competition;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class JudgesRelationManager extends RelationManager
{
    protected static string $relationship = 'judges'; // Название метода в модели Competition
    protected static ?string $title = 'Судейская бригада'; // Заголовок вкладки
    protected static ?string $recordTitleAttribute = 'name'; // Поиск по имени при добавлении

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                // Позволяем менять роль уже привязанному судье
                Forms\Components\TextInput::make('role_on_tournament')
                    ->label('Роль на турнире')
                    ->placeholder('Например: Рефери, Боковой судья'),

                // Правило R-4.18: функция судьи в сценарии A/B.
                self::panelSelect(),
            ]);
    }

    protected static function panelSelect(): Forms\Components\Select
    {
        return Forms\Components\Select::make('panel')
            ->label('Функция судьи (сценарий A/B)')
            ->options(Competition::panelLabels())
            ->placeholder('Не назначена')
            ->native(false)
            ->helperText('Используется только в турнирах со сценарием A/B. Старший судья тоже может быть A или B.');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('ФИО')
                    ->sortable()
                    ->searchable(),

                // Показываем глобальную роль (Квалификацию)
                Tables\Columns\TextColumn::make('role')
                    ->label('Квалификация')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'head_judge' => 'danger', // Красный для старших
                        'judge' => 'info',        // Синий для обычных
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'head_judge' => 'Старший судья',
                        'judge' => 'Линейный судья',
                        'trainer' => 'Тренер',
                        'admin' => 'Админ',
                        default => $state,
                    }),

                // НОВАЯ КОЛОНКА: Судейская категория
                Tables\Columns\TextColumn::make('judge_category')
                    ->label('Категория')
                    ->badge()
                    ->placeholder('—')
                    ->color(fn (?string $state): string => match ($state) {
                        'ССВК' => 'success',   // зелёный — высшая
                        'СС1К' => 'warning',   // оранжевый — первая
                        'СС2К' => 'info',      // синий — вторая
                        'СС3К' => 'gray',      // серый — третья
                        'ЮС'   => 'gray',      // юный судья
                        default => 'gray',
                    }),

                // Показываем роль на ЭТОМ турнире
                Tables\Columns\TextColumn::make('pivot.role_on_tournament')
                    ->label('Роль на турнире')
                    ->placeholder('По умолчанию'),

                Tables\Columns\TextColumn::make('pivot.panel')
                    ->label('Функция')
                    ->badge()
                    ->color(fn (?string $state): string => $state ? 'primary' : 'gray')
                    ->formatStateUsing(fn (?string $state): string => $state ? 'Судья ' . $state : '—')
                    ->placeholder('—')
                    ->visible(fn (): bool => $this->getOwnerRecord()->isAbScheme()),
            ])
            ->headerActions([
                // КНОПКА "ПРИВЯЗАТЬ" (Attach)
                Tables\Actions\AttachAction::make()
                    ->preloadRecordSelect()
                    ->recordSelectOptionsQuery(fn (Builder $query) => 
                        // ВАЖНО: В выпадающем списке показываем ТОЛЬКО тех, у кого галочка "Активен"
                        $query->where('is_active_judge', true)
                    )
                    ->form(fn (Tables\Actions\AttachAction $action): array => [
                        $action->getRecordSelect(),
                        // При добавлении можно сразу указать роль
                        Forms\Components\TextInput::make('role_on_tournament')
                            ->label('Роль на этом турнире (необязательно)'),
                        self::panelSelect(),
                    ]),
            ])
            ->actions([
                // Кнопки редактирования (изменить роль) и отвязки (удалить с турнира)
                Tables\Actions\EditAction::make(),
                Tables\Actions\DetachAction::make(),
            ]);
    }
}
