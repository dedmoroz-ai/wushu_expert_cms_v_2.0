<?php

namespace App\Filament\Resources;

use App\Filament\Resources\JudgingLogResource\Pages;
use App\Models\JudgingLog;
use App\Support\ScoreRange;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Правило 8.9 (docs/JUDGING_RULES.md): журнал действий судейства.
 *
 * Только чтение: записи создаются автоматически пультами и протоколом.
 */
class JudgingLogResource extends Resource
{
    protected static ?string $model = JudgingLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Журнал судейства';

    protected static ?string $modelLabel = 'Запись журнала';

    protected static ?string $pluralModelLabel = 'Журнал судейства';

    protected static ?string $navigationGroup = 'Соревнования';

    protected static ?int $navigationSort = 90;

    public static function shouldRegisterNavigation(): bool
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        return $user && ($user->isAdmin() || $user->isHeadJudge());
    }

    public static function canViewAny(): bool
    {
        return static::shouldRegisterNavigation();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['registration.athlete', 'judge', 'actor', 'competition']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Время')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('action')
                    ->label('Действие')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        JudgingLog::ACTION_SCORE_CREATED => 'success',
                        JudgingLog::ACTION_SCORE_UPDATED => 'warning',
                        JudgingLog::ACTION_SCORE_DELETED => 'danger',
                        JudgingLog::ACTION_FINAL_SCORE_CHANGED => 'warning',
                        JudgingLog::ACTION_PROTOCOL_FINALIZED => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => JudgingLog::actionLabels()[$state] ?? $state),

                Tables\Columns\TextColumn::make('competition.name')
                    ->label('Соревнование')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),

                Tables\Columns\TextColumn::make('registration.sort_order')
                    ->label('№')
                    ->alignCenter()
                    ->width(60),

                Tables\Columns\TextColumn::make('registration.athlete.name')
                    ->label('Участник')
                    ->formatStateUsing(function ($state, JudgingLog $record): string {
                        $registration = $record->registration;
                        $athlete = $registration?->athlete;

                        if (! $athlete) {
                            return '—';
                        }

                        // В локальной схеме ФИО хранится одним полем name, но
                        // допускаем и раздельное surname+name (серверная схема).
                        $mainName = trim(($athlete->surname ?? '').' '.$athlete->name);

                        if ($registration->partner) {
                            $partnerName = trim(($registration->partner->surname ?? '').' '.$registration->partner->name);

                            return $mainName.' / '.$partnerName;
                        }

                        return $mainName;
                    })
                    ->placeholder('—')
                    ->searchable(['name']),

                // Замечание заказчика (30.09): колонка «Судья» убрана — таблица не умещалась
                // по горизонтали. Кто выполнил действие — в колонке «Кто выполнил».
                // Правило R-6.13: панель судьи (сценарий A/B).
                Tables\Columns\TextColumn::make('details.panel')
                    ->label('Панель')
                    ->alignCenter()
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('old_value')
                    ->label('Было')
                    ->alignCenter()
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state) => is_null($state)
                        ? '—'
                        : number_format((float) $state, ScoreRange::PRECISION, '.', '')),

                Tables\Columns\TextColumn::make('new_value')
                    ->label('Стало')
                    ->alignCenter()
                    ->weight('bold')
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state) => is_null($state)
                        ? '—'
                        : number_format((float) $state, ScoreRange::PRECISION, '.', '')),

                Tables\Columns\TextColumn::make('actor.name')
                    ->label('Кто выполнил')
                    ->searchable(),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Причина')
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('action')
                    ->label('Действие')
                    ->options(JudgingLog::actionLabels()),

                Tables\Filters\SelectFilter::make('competition_id')
                    ->label('Соревнование')
                    ->relationship('competition', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('judge_id')
                    ->label('Судья')
                    ->relationship('judge', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                // Правило R-6.13: подробный состав действия (сбавки, расчёт итога).
                Tables\Actions\Action::make('details')
                    ->label('Подробно')
                    ->icon('heroicon-o-magnifying-glass')
                    ->visible(fn (JudgingLog $record): bool => ! empty($record->details))
                    ->modalHeading('Подробности записи журнала')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Закрыть')
                    ->modalContent(fn (JudgingLog $record) => view('filament.resources.judging-log-details', [
                        'record' => $record,
                        'lines' => self::detailLines($record->details ?? []),
                    ])),
            ])
            ->bulkActions([]);
    }

    /**
     * Человекочитаемое представление judging_logs.details.
     *
     * @return array<int, string>
     */
    public static function detailLines(array $details): array
    {
        $fmt = fn ($v) => is_null($v) ? '—' : number_format((float) $v, ScoreRange::PRECISION, '.', '');
        $lines = [];

        if (isset($details['scheme'])) {
            $lines[] = 'Сценарий: '.($details['scheme'] === 'ab' ? 'A/B' : 'простой');
        }

        if (! empty($details['panel'])) {
            $lines[] = 'Панель: '.$details['panel'];
        }

        if (array_key_exists('deductions', $details)) {
            $lines[] = 'Старт: '.$fmt($details['start'] ?? 5);

            foreach ($details['deductions'] as $d) {
                $lines[] = '  • '.$d['code'].' — '.($d['label'] ?? '').': −'.$fmt($d['value']);
            }

            $lines[] = 'Сумма сбавок: −'.$fmt($details['deductions_total'] ?? 0);
        }

        if (! empty($details['old_deductions'])) {
            $lines[] = 'Сбавки до изменения:';
            foreach ($details['old_deductions'] as $d) {
                $lines[] = '  • '.$d['code'].': −'.$fmt($d['value']);
            }
        }

        if (! empty($details['scores'])) {
            $lines[] = 'Оценки судей:';
            foreach ($details['scores'] as $s) {
                $codes = collect($s['deductions'] ?? [])->map(fn ($d) => $d['code'].' −'.$fmt($d['value']))->implode(', ');
                $lines[] = '  • '.($s['judge'] ?? ('#'.$s['judge_id']))
                    .(! empty($s['panel']) ? ' ['.$s['panel'].']' : '')
                    .': '.$fmt($s['score'])
                    .($codes !== '' ? ' ('.$codes.')' : '');
            }
        }

        if (array_key_exists('avg_a', $details) && ! is_null($details['avg_a'])) {
            $lines[] = 'Среднее A: '.$fmt($details['avg_a']);
        }

        if (array_key_exists('avg_b', $details) && ! is_null($details['avg_b'])) {
            $lines[] = 'Среднее B: '.$fmt($details['avg_b']);
        }

        if (array_key_exists('auto', $details)) {
            $lines[] = 'Авто-расчёт: '.$fmt($details['auto']);
        }

        if (! empty($details['formula'])) {
            $lines[] = 'Формула: '.$details['formula'];
        }

        return $lines;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListJudgingLogs::route('/'),
        ];
    }
}
