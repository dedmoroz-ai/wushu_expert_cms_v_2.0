<?php

namespace Tests\Feature;

use App\Filament\Pages\JudgePad;
use App\Filament\Pages\SuperJudgePad;
use App\Filament\Resources\JudgingLogResource;
use App\Models\AgeGroup;
use App\Models\Athlete;
use App\Models\Club;
use App\Models\Competition;
use App\Models\DeductionCode;
use App\Models\JudgingLog;
use App\Models\Registration;
use App\Models\Style;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Модалка «Подробно» журнала судейства: коды сбавок, нажатые только одним
 * судьёй панели A (не засчитанные в вычет, R-3.13–R-3.14), выделяются цветом
 * ТОЛЬКО у записей «Протокол утверждён» (решение заказчика 10.10: в личную
 * оценку судьи засчитаны все её сбавки, поэтому у строк «Оценка выставлена/
 * изменена» зачёркиваний нет). В простой схеме отметок нет.
 */
class JudgingLogDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $connection = $_SERVER['DB_CONNECTION'] ?? $_ENV['DB_CONNECTION'] ?? 'sqlite';

        $driver = match ($connection) {
            'pgsql' => 'pgsql',
            'mysql', 'mariadb' => 'mysql',
            'sqlsrv' => 'sqlsrv',
            default => 'sqlite',
        };

        if (!in_array($driver, \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped("PDO-драйвер «{$driver}» не установлен (нужен для тестовой БД).");
        }

        parent::setUp();
    }

    public function test_single_judge_codes_are_highlighted_only_in_finalized_log(): void
    {
        [, $reg, $judges, $head] = $this->makeAbTournament();

        $c11 = DeductionCode::create(['code' => '11', 'label' => 'Руки', 'value' => 0.1, 'sort_order' => 1]);
        $c22 = DeductionCode::create(['code' => '22', 'label' => 'Падение', 'value' => 0.3, 'sort_order' => 2]);
        $c33 = DeductionCode::create(['code' => '33', 'label' => 'Вираж', 'value' => 0.5, 'sort_order' => 3]);

        // Судья A1: 11, 11, 22; судья A2: 22, 33.
        // 11 (×2, только A1) и 33 (только A2) — не засчитываются; 22 — засчитан.
        $this->actingAs($judges['a1']);
        Livewire::test(JudgePad::class)
            ->call('pressCode', $c11->id)
            ->call('pressCode', $c11->id)
            ->call('pressCode', $c22->id)
            ->call('submitScore');

        $this->actingAs($judges['a2']);
        Livewire::test(JudgePad::class)
            ->call('pressCode', $c22->id)
            ->call('pressCode', $c33->id)
            ->call('submitScore');

        $this->actingAs($judges['b1']);
        Livewire::test(JudgePad::class)
            ->call('addNumber', '4')->call('addNumber', '.')->call('addNumber', '2')
            ->call('submitScore');

        $this->actingAs($judges['b2']);
        Livewire::test(JudgePad::class)
            ->call('addNumber', '3')->call('addNumber', '.')->call('addNumber', '8')
            ->call('submitScore');

        $this->actingAs($head);
        Livewire::test(SuperJudgePad::class)
            ->call('addNumber', '4')->call('addNumber', '.')->call('addNumber', '1')
            ->call('submitMyScore')
            ->call('finalizeProtocol');

        // Запись «Протокол утверждён»: засчитан только код 22.
        $finalLog = JudgingLog::where('action', JudgingLog::ACTION_PROTOCOL_FINALIZED)->firstOrFail();
        $counted = JudgingLogResource::countedCodes($finalLog);
        $this->assertSame(['22'], $counted);

        $lines = JudgingLogResource::detailLines($finalLog->details ?? [], $counted);
        $ignoredText = $this->ignoredText($lines);

        $this->assertStringContainsString('11 −0.100', $ignoredText);
        $this->assertStringContainsString('33 −0.500', $ignoredText);
        $this->assertStringNotContainsString('22 −0.300', $ignoredText);

        // Запись «Оценка выставлена» судьи A1: в личную оценку засчитаны все
        // сбавки (R-3.12) — зачёркиваний нет (решение заказчика 10.10).
        $createLog = JudgingLog::where('action', JudgingLog::ACTION_SCORE_CREATED)
            ->where('judge_id', $judges['a1']->id)
            ->firstOrFail();
        $this->assertNull(JudgingLogResource::countedCodes($createLog));

        $createLines = JudgingLogResource::detailLines($createLog->details ?? [], null);
        $this->assertSame('', $this->ignoredText($createLines));

        // Модалка рендерится с цветовой подсветкой и легендой.
        $html = view('filament.resources.judging-log-details', [
            'record' => $finalLog,
            'lines' => JudgingLogResource::detailLines($finalLog->details ?? [], $counted),
        ])->render();

        $this->assertStringContainsString('line-through', $html);
        $this->assertStringContainsString('не учтены в вычете', $html);
    }

    public function test_finalized_snapshot_marks_only_single_judge_codes(): void
    {
        $record = JudgingLog::make([
            'action' => JudgingLog::ACTION_PROTOCOL_FINALIZED,
            'details' => [
                'scheme' => Competition::SCHEME_AB,
                'scores' => [
                    [
                        'judge_id' => 7,
                        'judge' => 'A1',
                        'panel' => Competition::PANEL_A,
                        'score' => 4.7,
                        'deductions' => [
                            ['code' => '11', 'value' => 0.1],
                            ['code' => '22', 'value' => 0.3],
                        ],
                    ],
                    [
                        'judge_id' => 8,
                        'judge' => 'B1',
                        'panel' => Competition::PANEL_B,
                        'score' => 4.2,
                        'deductions' => [],
                    ],
                ],
                'ignored_deductions' => [
                    ['judge_id' => 7, 'judge' => 'A1', 'code' => '11', 'value' => 0.1],
                ],
            ],
        ]);

        $counted = JudgingLogResource::countedCodes($record);
        $this->assertSame(['22'], $counted);

        $lines = JudgingLogResource::detailLines($record->details, $counted);

        // Строка судьи A1: подсвечивается только часть с кодом 11, имя судьи — нет.
        $a1Line = null;
        foreach ($lines as $line) {
            if (str_contains(implode('', array_column($line, 'text')), 'A1')) {
                $a1Line = $line;
            }
        }

        $this->assertNotNull($a1Line);
        $this->assertSame('  • A1 [A]: 4.700 (', $a1Line[0]['text']);
        $this->assertFalse($a1Line[0]['ignored']);
        $this->assertSame('11 −0.100', $a1Line[1]['text']);
        $this->assertTrue($a1Line[1]['ignored']);
        $this->assertSame(', ', $a1Line[2]['text']);
        $this->assertFalse($a1Line[2]['ignored']);
        $this->assertSame('22 −0.300', $a1Line[3]['text']);
        $this->assertFalse($a1Line[3]['ignored']);
    }

    public function test_score_created_snapshot_is_never_marked(): void
    {
        // Снимок «Оценка выставлена»: судья A1 нажал 11 и 22; 11 не подтверждён
        // вторым судьёй — но в личную оценку A1 засчитаны обе сбавки (R-3.12),
        // поэтому строка не зачёркивается (решение заказчика 10.10).
        $record = JudgingLog::make([
            'action' => JudgingLog::ACTION_SCORE_CREATED,
            'details' => [
                'scheme' => Competition::SCHEME_AB,
                'panel' => Competition::PANEL_A,
                'start' => 5.0,
                'deductions' => [
                    ['code' => '11', 'label' => 'Руки', 'value' => 0.1],
                    ['code' => '22', 'label' => 'Падение', 'value' => 0.3],
                ],
                'deductions_total' => 0.4,
            ],
        ]);

        $this->assertNull(JudgingLogResource::countedCodes($record));

        $lines = JudgingLogResource::detailLines($record->details, JudgingLogResource::countedCodes($record));

        $this->assertSame('', $this->ignoredText($lines));

        $html = view('filament.resources.judging-log-details', [
            'record' => $record,
            'lines' => $lines,
        ])->render();

        $this->assertStringNotContainsString('line-through', $html);
        $this->assertStringNotContainsString('не учтены в вычете', $html);
    }

    public function test_simple_scheme_marks_nothing_as_ignored(): void
    {
        $record = JudgingLog::make([
            'details' => [
                'scheme' => Competition::SCHEME_SIMPLE,
                'scores' => [
                    [
                        'judge_id' => 1,
                        'judge' => 'Судья',
                        'panel' => null,
                        'score' => 8.5,
                        'deductions' => [],
                    ],
                ],
            ],
        ]);

        // Правила подтверждения кодов действуют только в схеме A/B.
        $this->assertNull(JudgingLogResource::countedCodes($record));

        $lines = JudgingLogResource::detailLines($record->details, JudgingLogResource::countedCodes($record));

        $this->assertSame('', $this->ignoredText($lines));
    }

    /**
     * Тексты всех частей строк с флагом ignored — через «|».
     */
    private function ignoredText(array $lines): string
    {
        $texts = [];

        foreach ($lines as $line) {
            foreach ($line as $part) {
                if ($part['ignored']) {
                    $texts[] = $part['text'];
                }
            }
        }

        return implode('|', $texts);
    }

    /**
     * @return array{0: Competition, 1: Registration, 2: array<string, User>, 3: User}
     */
    private function makeAbTournament(): array
    {
        $competition = Competition::create([
            'name' => 'A/B турнир',
            'start_date' => '2026-02-01',
            'city' => 'Москва',
            'status_code' => 1,
            'judging_scheme' => Competition::SCHEME_AB,
        ]);

        $group = AgeGroup::create(['name' => 'Юниоры', 'gender' => 'male', 'min_age' => 12, 'max_age' => 14]);
        $club = Club::create(['name' => 'Клуб ' . str()->random(5)]);
        $athlete = Athlete::create([
            'club_id' => $club->id,
            'name' => 'Иванов Иван',
            'birth_date' => '2013-05-01',
            'gender' => 'male',
        ]);
        $style = Style::create(['name' => 'Чанцюань']);

        $reg = Registration::create([
            'competition_id' => $competition->id,
            'athlete_id' => $athlete->id,
            'style_id' => $style->id,
            'age_group_id' => $group->id,
            'sort_order' => 1,
            'is_completed' => false,
        ]);

        $competition->update(['current_registration_id' => $reg->id]);

        $make = fn (string $name, string $role = 'judge') => User::create([
            'name' => $name,
            'email' => str()->random(12) . '@test.local',
            'password' => Hash::make('secret'),
            'role' => $role,
            'is_active_judge' => true,
        ]);

        $judges = ['a1' => $make('A1'), 'a2' => $make('A2'), 'b1' => $make('B1'), 'b2' => $make('B2')];
        $head = $make('Старший', 'head_judge');
        $panels = ['a1' => 'A', 'a2' => 'A', 'b1' => 'B', 'b2' => 'B'];

        foreach ($judges as $key => $user) {
            $competition->judges()->attach($user->id, ['panel' => $panels[$key]]);
        }

        $competition->judges()->attach($head->id, ['panel' => 'B']);

        return [$competition->fresh(), $reg, $judges, $head];
    }
}
