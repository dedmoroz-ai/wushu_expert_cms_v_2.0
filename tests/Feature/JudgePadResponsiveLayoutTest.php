<?php

namespace Tests\Feature;

use App\Filament\Pages\JudgePad;
use App\Models\AgeGroup;
use App\Models\Athlete;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\Style;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Уточнение заказчика 10.10: пульты линейных судей (панели A и B) должны
 * корректно отображаться на телефонах — кнопки не уходят за нижний край
 * экрана и не перекрываются. Вёрстка: оболочка .pad-shell на 100dvh,
 * прокручивается только средняя часть (.pad-scroll), нижняя кнопка —
 * обычный блок (.pad-bottom) с учётом safe-area, а не position: fixed
 * (на iOS Safari fixed-bottom прячется за панелью браузера).
 *
 * Пульт Старшего судьи (SuperJudgePad) не трогаем.
 */
class JudgePadResponsiveLayoutTest extends TestCase
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

        if (! in_array($driver, \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped("PDO-драйвер «{$driver}» не установлен (нужен для тестовой БД).");
        }

        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * Оболочка на 100dvh + скролл средней части + нижняя кнопка обычным
     * блоком с safe-area — для обеих панелей (A: коды, B: клавиатура).
     */
    public function test_pad_shell_fits_viewport_for_both_panels(): void
    {
        [, , $judges] = $this->makeTournament();

        foreach (['a1', 'b1'] as $key) {
            $this->actingAs($judges[$key]);

            Livewire::test(JudgePad::class)
                ->assertSet('canVote', true)
                ->assertSeeHtml('class="pad-shell')
                ->assertSeeHtml('class="pad-scroll"')
                ->assertSeeHtml('class="pad-bottom"')
                ->assertSeeHtml('height: 100dvh')
                ->assertSeeHtml('overscroll-behavior: contain')
                ->assertSeeHtml('env(safe-area-inset-bottom')
                // Регрессия: нижняя кнопка больше не position: fixed.
                ->assertDontSeeHtml('fixed bottom-0');
        }
    }

    /**
     * Угловые кнопки: выход из пульта сохранён, добавлено «во весь экран»
     * (блок с wire:ignore — детект поддержки fullscreen не отменяется poll).
     */
    public function test_corner_buttons_keep_exit_and_add_fullscreen(): void
    {
        [, , $judges] = $this->makeTournament();

        $this->actingAs($judges['b1']);

        Livewire::test(JudgePad::class)
            ->assertSeeHtml('wire:click="exitPad"')
            ->assertSeeHtml('pad-fullscreen-btn')
            ->assertSeeHtml('requestFullscreen')
            ->assertSeeHtml('wire:ignore');
    }

    /**
     * В состоянии «Оценка принята» нижняя кнопка скрыта (не нужна),
     * оболочка и угловые кнопки остаются.
     */
    public function test_pad_shell_persists_after_submit(): void
    {
        [, , $judges] = $this->makeTournament();

        $this->actingAs($judges['b1']);

        Livewire::test(JudgePad::class)
            ->call('addNumber', '4')->call('addNumber', '.')->call('addNumber', '2')
            ->call('submitScore')
            ->assertSeeHtml('class="pad-shell')
            ->assertSeeHtml('wire:click="exitPad"')
            ->assertDontSeeHtml('class="pad-bottom"');
    }

    /**
     * @return array{0: Competition, 1: Registration, 2: array<string, User>}
     */
    private function makeTournament(): array
    {
        $competition = Competition::create([
            'name' => 'Адаптив турнир',
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

        $make = fn (string $name, string $panel) => tap(User::create([
            'name' => $name,
            'email' => str()->random(12) . '@test.local',
            'password' => Hash::make('secret'),
            'role' => 'judge',
            'is_active_judge' => true,
        ]), fn (User $user) => $competition->judges()->attach($user->id, ['panel' => $panel]));

        $judges = ['a1' => $make('A1', 'A'), 'b1' => $make('B1', 'B')];

        return [$competition->fresh(), $reg, $judges];
    }
}