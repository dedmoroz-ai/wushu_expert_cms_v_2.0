<?php

namespace Tests\Feature;

use App\Filament\Resources\CompetitionResource\Pages\ListCompetitions;
use App\Models\Competition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Замечание заказчика (30.09): в списке соревнований кнопка «Протокол» убрана,
 * вместо неё кнопка «Табло» — ссылка на публичное табло /scoreboard (R-5.1),
 * открывается в новой вкладке.
 */
class CompetitionListActionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Как в JudgingRulesTest: тесты требуют рабочего драйвера БД. Если нужного
     * PDO-драйвера нет — пропускаем, а не падаем.
     */
    protected function setUp(): void
    {
        // Проверяем до parent::setUp(), поэтому config() ещё недоступен.
        $connection = $_SERVER['DB_CONNECTION'] ?? $_ENV['DB_CONNECTION'] ?? 'sqlite';

        $driver = match ($connection) {
            'pgsql' => 'pgsql',
            'mysql', 'mariadb' => 'mysql',
            'sqlsrv' => 'sqlsrv',
            default => 'sqlite',
        };

        if (! in_array($driver, \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped("PDO-драйвер «{$driver}» не установлен (нужен для тестовой БД).");
        }

        parent::setUp();
    }

    /** «Протокол» убрана; «Табло» ведёт на /scoreboard и открывается в новой вкладке. */
    public function test_protocol_action_replaced_with_scoreboard_link(): void
    {
        $competition = $this->makeCompetition();

        $this->actingAs($this->makeUser('cmp-actions-admin@test.local', 'admin'));

        $table = Livewire::test(ListCompetitions::class)
            ->assertCanSeeTableRecords([$competition]);

        // Кнопка «Протокол» (generate_protocol) убрана.
        $table->assertTableActionDoesNotExist('generate_protocol');

        // Кнопка «Табло» на месте.
        $table->assertTableActionExists('scoreboard');

        $action = $table->instance()->getTable()->getAction('scoreboard');

        $this->assertNotNull($action);
        $this->assertSame(route('scoreboard'), $action->getUrl());
        $this->assertTrue($action->shouldOpenUrlInNewTab());
    }

    /** R-5.1: табло доступно без авторизации по адресу /scoreboard (регресс «404 Not Found»). */
    public function test_scoreboard_route_is_public(): void
    {
        $this->get(route('scoreboard'))->assertOk();
    }

    private function makeCompetition(): Competition
    {
        return Competition::create([
            'name' => 'Тестовый турнир',
            'start_date' => '2026-03-01',
            'city' => 'Симферополь',
            'status_code' => 1,
            'judging_scheme' => Competition::SCHEME_AB,
        ]);
    }

    private function makeUser(string $email, string $role): User
    {
        return User::create([
            'name' => 'Пользователь',
            'email' => $email,
            'password' => Hash::make('secret'),
            'role' => $role,
            'is_active_judge' => $role !== 'admin',
        ]);
    }
}
