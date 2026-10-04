<?php

namespace Tests\Feature;

use App\Filament\Widgets\AccountWidget;
use App\Filament\Widgets\ClubInfoWidget;
use App\Filament\Widgets\CurrentCompetitionWidget;
use App\Models\Club;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Замечание заказчика (04.10): на дашборде тренера рядом с плашкой «Добро
 * пожаловать» (в половину строки) — виджет клуба в той же строке: логотип
 * клуба и данные клуба из его карточки (раздел «Клубы»): название, город,
 * регион. Виден только тренерам — дашборды администратора и судей не тронуты.
 * Заголовка «Мой клуб» у секции нет — верхние виджеты выровнены по высоте.
 */
class CoachClubWidgetTest extends TestCase
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
    }

    /** Виджет клуба виден только тренеру. */
    public function test_club_widget_is_visible_only_for_coach(): void
    {
        $this->actingAs($this->makeUser('club-canview-coach@test.local', 'coach'));
        $this->assertTrue(ClubInfoWidget::canView());

        foreach (['admin', 'judge', 'head_judge'] as $role) {
            $this->actingAs($this->makeUser("club-canview-{$role}@test.local", $role));
            $this->assertFalse(ClubInfoWidget::canView(), "Роль «{$role}» не должна видеть виджет клуба.");
        }
    }

    /**
     * Виджет клуба: логотип из настроек клуба, название, город и регион
     * из его карточки (раздел «Клубы»).
     */
    public function test_club_widget_shows_logo_and_club_settings(): void
    {
        $club = Club::create([
            'name' => 'СК «Спарта»',
            'logo_path' => 'club-logos/sparta.png',
            'city' => 'Москва',
            'region' => 'Московская область',
        ]);

        $user = $this->makeUser('club-widget@test.local', 'coach', [
            'club_id' => $club->id,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($user)
            ->test(ClubInfoWidget::class)
            ->assertSee('fi-club-info-widget', false)
            ->assertSee('club-logos/sparta.png', false)
            ->assertSee('СК «Спарта»')
            ->assertSee('Москва')
            ->assertSee('Московская область');
    }

    /** Без логотипа клуба — плитка с первой буквой названия клуба. */
    public function test_club_widget_falls_back_to_club_initial_tile(): void
    {
        $club = Club::create(['name' => 'Спарта']);

        $user = $this->makeUser('club-tile@test.local', 'coach', [
            'club_id' => $club->id,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($user)
            ->test(ClubInfoWidget::class)
            ->assertSee('>С</span>', false)
            ->assertDontSee('club-logos', false);
    }

    /** Пустые город и регион не оставляют пустых строк — только название. */
    public function test_club_widget_omits_empty_city_and_region(): void
    {
        $club = Club::create([
            'name' => 'Спарта',
            'logo_path' => 'club-logos/sparta.png',
        ]);

        $user = $this->makeUser('club-no-city@test.local', 'coach', [
            'club_id' => $club->id,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($user)
            ->test(ClubInfoWidget::class)
            ->assertSee('Спарта')
            ->assertDontSee('Москва')
            ->assertDontSee('Московская область');
    }

    /** Без привязки к клубу — заглушка, а не пустой виджет. */
    public function test_club_widget_without_club_shows_placeholder(): void
    {
        $user = $this->makeUser('club-none@test.local', 'coach');

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($user)
            ->test(ClubInfoWidget::class)
            ->assertSee('Клуб не привязан к тренеру');
    }

    /**
     * Замечание заказчика (04.10): виджет клуба занимает половину строки —
     * в пару к плашке «Добро пожаловать», и идёт сразу после неё.
     */
    public function test_club_widget_spans_half_width_and_follows_welcome_banner(): void
    {
        $this->assertSame(1, (new ClubInfoWidget)->getColumnSpan());

        $user = $this->makeUser('club-span@test.local', 'coach');

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($user)
            ->test(ClubInfoWidget::class)
            ->assertSee('--col-span-default: span 1 / span 1', false);

        // Порядок: «Добро пожаловать» → «Мой клуб» → «Актуальное соревнование».
        $this->assertLessThan(ClubInfoWidget::getSort(), AccountWidget::getSort());
        $this->assertLessThan(CurrentCompetitionWidget::getSort(), ClubInfoWidget::getSort());
    }

    /** Клубный виджет не трогает плашку «Добро пожаловать» — там личный аватар. */
    public function test_club_widget_does_not_replace_personal_avatar_on_banner(): void
    {
        $club = Club::create([
            'name' => 'Спарта',
            'logo_path' => 'club-logos/sparta.png',
        ]);

        $user = $this->makeUser('club-avatar-split@test.local', 'coach', [
            'club_id' => $club->id,
            'avatar_path' => 'avatars/face.jpg',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // Плашка: личный аватар, без логотипа клуба.
        Livewire::actingAs($user)
            ->test(AccountWidget::class)
            ->assertSee('storage/avatars/face.jpg', false)
            ->assertDontSee('club-logos/sparta.png', false);

        // Виджет клуба: логотип клуба, без личного аватара.
        Livewire::actingAs($user)
            ->test(ClubInfoWidget::class)
            ->assertSee('club-logos/sparta.png', false)
            ->assertDontSee('avatars/face.jpg', false);
    }

    /**
     * Замечание заказчика (04.10, выравнивание высоты): у секции виджета клуба
     * нет заголовка «Мой клуб» — как у плашки «Добро пожаловать», чтобы верхние
     * виджеты совпадали по высоте.
     */
    public function test_club_widget_has_no_heading(): void
    {
        $club = Club::create(['name' => 'Спарта']);

        $user = $this->makeUser('club-no-heading@test.local', 'coach', [
            'club_id' => $club->id,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($user)
            ->test(ClubInfoWidget::class)
            ->assertDontSee('Мой клуб')
            ->assertSee('fi-club-info-widget', false);
    }

    private function makeUser(string $email, string $role, array $flags = []): User
    {
        return User::create(array_merge([
            'name' => 'Тестовый пользователь',
            'email' => $email,
            'password' => Hash::make('secret'),
            'role' => $role,
            'is_active_judge' => $role !== 'admin',
        ], $flags));
    }
}