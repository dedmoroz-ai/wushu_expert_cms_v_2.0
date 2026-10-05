<?php

namespace Tests\Feature;

use App\Filament\Resources\CompetitionResource\Pages\EditCompetition;
use App\Http\Controllers\DeductionCodesMemoPdfController;
use App\Models\Competition;
use App\Models\DeductionCode;
use App\Models\Federation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Решение заказчика (05.10, вариант A): набор кодов сбавок per-competition —
 * override у соревнования (competition_deduction_codes), глобальный is_active —
 * набор по умолчанию. Пустой набор соревнования = глобальный активный набор.
 */
class CompetitionDeductionCodesTest extends TestCase
{
    use RefreshDatabase;

    /** Как в JudgeDashboardTest: тестам нужен рабочий PDO-драйвер БД. */
    protected function setUp(): void
    {
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

    /** Без своего набора пульт берёт глобальный активный набор справочника. */
    public function test_competition_without_set_uses_global_active_codes(): void
    {
        $active = $this->makeCode('10', 1, true);
        $this->makeCode('20', 2, false); // выключен глобально

        $competition = $this->makeCompetition();

        $this->assertSame(
            [$active->id],
            $competition->padDeductionCodes()->pluck('id')->all(),
            'Пустой набор соревнования должен давать глобальный активный набор.',
        );
    }

    /** Свой набор соревнования перекрывает глобальный (включая глобально выключенные коды). */
    public function test_competition_set_overrides_global(): void
    {
        $this->makeCode('10', 1, true);
        $second = $this->makeCode('20', 2, true);
        $third = $this->makeCode('30', 3, false); // глобально выключен, но включён в наборе

        $competition = $this->makeCompetition();
        $competition->deductionCodes()->attach([$second->id, $third->id]);

        // Порядок — как в глобальном справочнике (sort_order, code).
        $this->assertSame(
            [$second->id, $third->id],
            $competition->padDeductionCodes()->pluck('id')->all(),
            'Набор соревнования должен перекрывать глобальный и идти в порядке справочника.',
        );
    }

    /** Памятка кодов сбавок (PDF) строится по эффективному набору соревнования. */
    public function test_memo_uses_effective_set(): void
    {
        $this->makeCode('10', 1, true, 'Техника');
        $kept = $this->makeCode('20', 2, true, 'Ритм');

        $competition = $this->makeCompetition();
        $competition->deductionCodes()->attach([$kept->id]);

        $data = DeductionCodesMemoPdfController::memoData($competition);

        $ids = collect($data['groups'])->flatten(1)->pluck('id')->all();

        $this->assertSame([$kept->id], $ids, 'Памятка должна печататься по набору соревнования.');
    }

    /** Карточка соревнования: чекбокс-список набора кодов сохраняет выбор. */
    public function test_admin_can_pick_codes_in_competition_form(): void
    {
        $admin = User::create([
            'name' => 'Админ',
            'email' => 'codes-admin@test.local',
            'password' => Hash::make('secret'),
            'role' => 'admin',
            'is_active_judge' => false,
        ]);

        $picked = $this->makeCode('10', 1, true);
        $this->makeCode('20', 2, true);

        $competition = $this->makeCompetition();

        Livewire::actingAs($admin)
            ->test(EditCompetition::class, ['record' => $competition->id])
            ->assertFormFieldExists('deductionCodes')
            ->fillForm(['deductionCodes' => [$picked->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            [$picked->id],
            $competition->fresh()->deductionCodes()->pluck('deduction_codes.id')->all(),
        );
    }

    private function makeCode(string $code, int $sortOrder, bool $isActive, ?string $group = null): DeductionCode
    {
        return DeductionCode::create([
            'code' => $code,
            'label' => 'Описание '.$code,
            'value' => 0.1,
            'group_label' => $group,
            'sort_order' => $sortOrder,
            'is_active' => $isActive,
        ]);
    }

    private function makeCompetition(): Competition
    {
        // Обязательные поля карточки «Соревнования» — чтобы Livewire-форма
        // проходила валидацию без ошибок (assertHasNoFormErrors).
        $federation = Federation::create(['name' => 'Тестовая федерация']);

        return Competition::create([
            'federation_id' => $federation->id,
            'name' => 'Тестовый турнир',
            'city' => 'Москва',
            'start_date' => now()->toDateString(),
            'chief_judge_name' => 'Иванов И.И.',
            'chief_secretary_name' => 'Петрова А.А.',
            'status_code' => 0,
        ]);
    }
}
