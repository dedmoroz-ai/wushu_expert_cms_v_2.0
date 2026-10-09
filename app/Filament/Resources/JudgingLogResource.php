<?php

namespace App\Filament\Resources;

use App\Filament\Resources\JudgingLogResource\Pages;
use App\Models\Competition;
use App\Models\JudgingLog;
use App\Models\Score;
use App\Support\JudgingCalculator;
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

    protected static ?string $navigationGroup = 'Турнир';

    protected static ?int $navigationSort = 90;

    /**
     * Решение заказчика (05.10): администратор видит раздел всегда, остальные
     * роли (судья, старший судья, тренер) — по переключателю «Журнал судейства»
     * в карточке пользователя (раздел «Пользователи»).
     */
    public static function shouldRegisterNavigation(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        return $user && ($user->isAdmin() || $user->show_judging_log);
    }

    public static function canViewAny(): bool
    {
        return static::shouldRegisterNavigation();
    }

    /** У судей разделы идут плоским списком (без групп), у админа — в «Турнире». */
    public static function getNavigationGroup(): ?string
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        return $user && $user->isJudge() ? null : parent::getNavigationGroup();
    }

    /** Позиция в наборе пунктов меню судьи: Инфопанель(1), Пульт(2), … Журнал(5). */
    public static function getNavigationSort(): ?int
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        return $user && $user->isJudge() ? 5 : parent::getNavigationSort();
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

                Tables\Columns\TextColumn::make('judge.name')
                    ->label('Судья')
                    ->placeholder('—')
                    ->searchable(),

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

                // Замечание заказчика (30.09, повторное): колонка «Причина» убрана —
                // она дублирует содержимое кнопки «Подробно» (details-модалка журнала).
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
                        'lines' => self::detailLines($record->details ?? [], self::countedCodes($record)),
                    ])),
            ])
            ->bulkActions([]);
    }

    /**
     * Человекочитаемое представление judging_logs.details.
     *
     * Каждая строка — набор частей ['text' => string, 'ignored' => bool]:
     * части с `ignored` — коды сбавок, нажатые только одним судьёй панели A
     * (R-3.13–R-3.14, уточнение 08.10) — они не засчитаны в вычет и в модалке
     * выделяются цветом.
     *
     * @return array<int, array<int, array{text: string, ignored: bool}>>
     */
    public static function detailLines(array $details, ?array $countedCodes = null): array
    {
        $fmt = fn ($v) => is_null($v) ? '—' : number_format((float) $v, ScoreRange::PRECISION, '.', '');
        $plain = fn (string $text) => [['text' => $text, 'ignored' => false]];
        $lines = [];

        if (isset($details['scheme'])) {
            $lines[] = $plain('Сценарий: '.($details['scheme'] === 'ab' ? 'A/B' : 'простой'));
        }

        if (! empty($details['panel'])) {
            $lines[] = $plain('Панель: '.$details['panel']);
        }

        if (array_key_exists('deductions', $details)) {
            $lines[] = $plain('Старт: '.$fmt($details['start'] ?? 5));

            foreach ($details['deductions'] as $d) {
                $lines[] = [[
                    'text' => '  • '.$d['code'].' — '.($d['label'] ?? '').': −'.$fmt($d['value']),
                    'ignored' => $countedCodes !== null && ! in_array((string) $d['code'], $countedCodes, true),
                ]];
            }

            $lines[] = $plain('Сумма сбавок: −'.$fmt($details['deductions_total'] ?? 0));
        }

        if (! empty($details['old_deductions'])) {
            $lines[] = $plain('Сбавки до изменения:');
            foreach ($details['old_deductions'] as $d) {
                $lines[] = [[
                    'text' => '  • '.$d['code'].': −'.$fmt($d['value']),
                    'ignored' => $countedCodes !== null && ! in_array((string) $d['code'], $countedCodes, true),
                ]];
            }
        }

        if (! empty($details['scores'])) {
            $lines[] = $plain('Оценки судей:');
            foreach ($details['scores'] as $s) {
                $parts = [[
                    'text' => '  • '.($s['judge'] ?? ('#'.$s['judge_id']))
                        .(! empty($s['panel']) ? ' ['.$s['panel'].']' : '')
                        .': '.$fmt($s['score']),
                    'ignored' => false,
                ]];

                // Частичная подсветка: у одного судьи один код может быть учтён,
                // а другой — нет (нажат только им).
                $codes = collect($s['deductions'] ?? [])->map(fn ($d) => [
                    'text' => $d['code'].' −'.$fmt($d['value']),
                    'ignored' => $countedCodes !== null && ! in_array((string) $d['code'], $countedCodes, true),
                ]);

                if ($codes->isNotEmpty()) {
                    $parts[0]['text'] .= ' (';
                    $sep = '';

                    foreach ($codes as $c) {
                        if ($sep !== '') {
                            $parts[] = ['text' => $sep, 'ignored' => false];
                        }

                        $parts[] = ['text' => $c['text'], 'ignored' => $c['ignored']];
                        $sep = ', ';
                    }

                    $parts[] = ['text' => ')', 'ignored' => false];
                }

                $lines[] = $parts;
            }
        }

        if (array_key_exists('avg_a', $details) && ! is_null($details['avg_a'])) {
            $lines[] = $plain('Среднее A: '.$fmt($details['avg_a']));
        }

        if (array_key_exists('avg_b', $details) && ! is_null($details['avg_b'])) {
            $lines[] = $plain('Среднее B: '.$fmt($details['avg_b']));
        }

        if (array_key_exists('auto', $details)) {
            $lines[] = $plain('Авто-расчёт: '.$fmt($details['auto']));
        }

        if (! empty($details['formula'])) {
            $lines[] = $plain('Формула: '.$details['formula']);
        }

        return $lines;
    }

    /**
     * Коды сбавок панели A, засчитанные в вычет (замечены двумя и более судьями,
     * R-3.13–R-3.14, уточнение 08.10). Код, которого нет в списке, — неучтённый
     * (нажат только одним судьёй) и в модалке выделяется цветом.
     *
     * Источник истины — снимки на момент действия: для записи «Протокол
     * утверждён» это details.ignored_deductions/details.scores, для записей
     * «Оценка выставлена/изменена» — текущие score_deductions регистрации
     * (снимок R-3.12 не переписывается, поэтому они отражают состояние на момент
     * действия; позднейшие правки снимков делают отметки приблизительными).
     *
     * null — отметки неприменимы (запись без снимка сбавок или простая схема:
     * там правила подтверждения не действуют, засчитываются все нажатия).
     *
     * @return array<int, string>|null
     */
    public static function countedCodes(JudgingLog $record): ?array
    {
        $details = $record->details ?? [];

        if (($details['scheme'] ?? Competition::SCHEME_SIMPLE) !== Competition::SCHEME_AB) {
            return null;
        }

        // Итоговая запись: полный снимок всех оценок A и неучтённых нажатий.
        if (! empty($details['scores'])) {
            $ignoredCounts = [];

            foreach ($details['ignored_deductions'] ?? [] as $ignored) {
                $key = $ignored['judge_id'].'|'.$ignored['code'];
                $ignoredCounts[$key] = ($ignoredCounts[$key] ?? 0) + 1;
            }

            $judgesByCode = [];

            foreach ($details['scores'] as $s) {
                if (($s['panel'] ?? null) !== Competition::PANEL_A) {
                    continue;
                }

                foreach ($s['deductions'] ?? [] as $d) {
                    $key = $s['judge_id'].'|'.$d['code'];

                    // Неучтённые нажатия остаются в снимке — их вычитаем.
                    if (($ignoredCounts[$key] ?? 0) > 0) {
                        $ignoredCounts[$key]--;

                        continue;
                    }

                    $judgesByCode[$d['code']] = true;
                }
            }

            return array_map('strval', array_keys($judgesByCode));
        }

        // Запись выставления/изменения оценки: пересчёт по текущим снимкам панели.
        if (array_key_exists('deductions', $details) && $record->registration_id) {
            $scores = Score::where('registration_id', $record->registration_id)
                ->with('deductions')
                ->get();

            $judgeScores = [];

            foreach ($scores as $score) {
                if ($score->panel !== Competition::PANEL_A) {
                    continue;
                }

                $judgeScores[$score->judge_id] = [
                    'score' => (float) $score->score,
                    'deductions' => $score->deductions
                        ->map(fn ($d) => ['code' => (string) $d->code, 'value' => (float) $d->value])
                        ->values()
                        ->all(),
                ];
            }

            if ($judgeScores === []) {
                return null;
            }

            $confirmed = JudgingCalculator::confirmedPanelScores($judgeScores);

            // judges — сколько судей нажали код; засчитан только код,
            // замеченный MIN_CODE_JUDGES и более судьями.
            $counted = array_keys(array_filter(
                $confirmed['judges'],
                fn (int $count) => $count >= JudgingCalculator::MIN_CODE_JUDGES,
            ));

            return array_map('strval', $counted);
        }

        return null;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListJudgingLogs::route('/'),
        ];
    }
}
