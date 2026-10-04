<?php

namespace Tests\Feature;

use App\Filament\Widgets\AccountWidget;
use App\Models\Club;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Замечание заказчика (02.10): плашка «Добро пожаловать» на дашборде занимает
 * всю ширину — как строка карточек статистики под ней. Плашка общая для
 * дашбордов всех ролей (админ, тренер, судья, старший судья).
 *
 * У тренера на плашке — имя-фамилия авторизованного тренера (к клубу может
 * быть привязано несколько тренеров).
 *
 * Замечание заказчика (02.10): у судьи и старшего судьи — как у всех, но
 * с личным аватаром из настроек пользователя, именем-фамилией судьи и
 * судейской категорией из настроек судейской коллегии.
 *
 * Замечание заказчика (04.10): у тренера плашка занимает половину строки
 * (рядом — виджет клуба ClubInfoWidget), аватаром плашки у всех ролей служит
 * личный аватар авторизованного из настроек пользователя (логотип клуба
 * переехал в виджет клуба). Дашборды администратора и судей не тронуты.
 */
class DashboardWelcomeBannerTest extends TestCase
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

    /** Плашка «Добро пожаловать» занимает все колонки грида дашборда. */
    public function test_account_widget_spans_full_grid_width(): void
    {
        $this->assertSame('full', (new AccountWidget)->getColumnSpan());
    }

    /**
     * Замечание заказчика (04.10): у тренера плашка — в половину строки
     * (вторую половину занимает виджет клуба), у остальных ролей — во всю.
     */
    public function test_account_widget_spans_half_width_for_coach(): void
    {
        $user = $this->makeUser('span-coach@test.local', 'coach');

        $this->actingAs($user);

        $this->assertSame(1, (new AccountWidget)->getColumnSpan());
    }

    /**
     * На дашборде администратора и судей плашка рендерится во всю ширину
     * (col-span full), совпадая по ширине с виджетом статистики.
     */
    #[DataProvider('fullWidthRoleProvider')]
    public function test_welcome_banner_is_full_width_for_admin_and_judges(string $role): void
    {
        $user = $this->makeUser("banner-{$role}@test.local", $role);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($user)
            ->test(AccountWidget::class)
            ->assertSee('fi-account-widget', false)
            ->assertSee('--col-span-default: 1 / -1', false);

        // Плашка присутствует и на самом дашборде.
        $this->actingAs($user)
            ->get('/admin')
            ->assertOk()
            ->assertSee('fi-account-widget', false);
    }

    /**
     * Замечание заказчика (04.10): у тренера плашка занимает половину строки —
     * рядом в той же строке виджет клуба.
     */
    public function test_welcome_banner_is_half_width_for_coach(): void
    {
        $user = $this->makeUser('banner-half-coach@test.local', 'coach');

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($user)
            ->test(AccountWidget::class)
            ->assertSee('fi-account-widget', false)
            ->assertSee('--col-span-default: span 1 / span 1', false);

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk()
            ->assertSee('fi-account-widget', false);
    }

    /**
     * Замечание заказчика (02.10): аватар в плашке увеличен до 100px.
     */
    #[DataProvider('roleProvider')]
    public function test_welcome_banner_avatar_is_100px(string $role): void
    {
        $user = $this->makeUser("banner-avatar-{$role}@test.local", $role, [
            'avatar_path' => 'avatars/face.jpg',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($user)
            ->test(AccountWidget::class)
            ->assertSee('width: 100px', false)
            ->assertSee('height: 100px', false);
    }

    /**
     * Увеличение касается только плашки: верхний аватар в шапке (user-menu)
     * остаётся прежним — на странице ровно один аватар 100px.
     */
    public function test_topbar_avatar_stays_default_size(): void
    {
        $user = $this->makeUser('banner-topbar@test.local', 'admin', [
            'avatar_path' => 'avatars/face.jpg',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $html = $this->actingAs($user)->get('/admin')->assertOk()->getContent();

        $this->assertSame(
            1,
            substr_count($html, 'width: 100px'),
            'На дашборде должен быть ровно один аватар 100px (в плашке), верхний не тронут.',
        );
    }

    /**
     * Замечание заказчика (04.10): у тренера аватар плашки — личный аватар
     * авторизованного из настроек пользователя (не логотип клуба — он теперь
     * в виджете клуба), а под приветствием — имя и фамилия авторизованного
     * тренера.
     */
    public function test_coach_banner_shows_personal_avatar_and_trainer_name(): void
    {
        $club = Club::create([
            'name' => 'СК «Спарта»',
            'logo_path' => 'club-logos/sparta.png',
        ]);

        $user = $this->makeUser('coach-banner@test.local', 'coach', [
            'club_id' => $club->id,
            'avatar_path' => 'avatars/face.jpg',
            'name' => 'Иванов Иван',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($user)
            ->test(AccountWidget::class)
            ->assertSee('storage/avatars/face.jpg', false)
            ->assertDontSee('club-logos/sparta.png', false)
            ->assertSee('Иванов Иван')
            ->assertSee('width: 100px', false)
            ->assertSee('height: 100px', false);
    }

    /**
     * Без загруженного фото остаётся стандартный компонент аватара —
     * логотип клуба в плашку не подставляется.
     */
    public function test_coach_banner_without_photo_keeps_user_avatar_fallback(): void
    {
        $club = Club::create([
            'name' => 'Спарта',
            'logo_path' => 'club-logos/sparta.png',
        ]);

        $user = $this->makeUser('coach-tile@test.local', 'coach', [
            'club_id' => $club->id,
            'name' => 'Иванов Иван',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($user)
            ->test(AccountWidget::class)
            ->assertSee('fi-avatar', false)
            ->assertDontSee('club-logos', false);
    }

    /**
     * К клубу привязано несколько тренеров — в плашке видно имя именно того,
     * кто авторизовался.
     */
    public function test_coach_banner_shows_logged_in_trainer_name(): void
    {
        $club = Club::create([
            'name' => 'Спарта',
            'logo_path' => 'club-logos/sparta.png',
        ]);

        // Второй тренер того же клуба — его имя на плашке быть не должно.
        $this->makeUser('coach-mate@test.local', 'coach', [
            'club_id' => $club->id,
            'name' => 'Петров Пётр',
        ]);

        $user = $this->makeUser('coach-me@test.local', 'coach', [
            'club_id' => $club->id,
            'name' => 'Иванов Иван',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($user)
            ->test(AccountWidget::class)
            ->assertSee('Иванов Иван')
            ->assertDontSee('Петров Пётр');
    }

    /**
     * Замечание заказчика (02.10): у судьи (линейного и старшего) на плашке —
     * личный аватар из настроек пользователя, имя-фамилия судьи и судейская
     * категория из настроек судейской коллегии.
     */
    #[DataProvider('judgeRoleProvider')]
    public function test_judge_banner_shows_avatar_name_and_category(string $role): void
    {
        $user = $this->makeUser("judge-category-{$role}@test.local", $role, [
            'avatar_path' => 'avatars/face.jpg',
            'name' => 'Судейкин Иван',
            'judge_category' => 'ССВК',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($user)
            ->test(AccountWidget::class)
            ->assertSee('storage/avatars/face.jpg', false)
            ->assertSee('Судейкин Иван')
            ->assertSee('Всероссийская категория (ССВК)');
    }

    /** Без заданной категории — заглушка, а не пустая строка. */
    #[DataProvider('judgeRoleProvider')]
    public function test_judge_banner_without_category_shows_placeholder(string $role): void
    {
        $user = $this->makeUser("judge-no-category-{$role}@test.local", $role, [
            'name' => 'Судейкин Иван',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($user)
            ->test(AccountWidget::class)
            ->assertSee('Судейкин Иван')
            ->assertSee('Судейская категория не задана');
    }

    /** Администратор и тренер строку судейской категории не видят. */
    #[DataProvider('nonJudgeRoleProvider')]
    public function test_category_line_is_hidden_for_non_judges(string $role): void
    {
        $user = $this->makeUser("non-judge-category-{$role}@test.local", $role, [
            'name' => 'Тестовый пользователь',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($user)
            ->test(AccountWidget::class)
            ->assertDontSee('Судейская категория');
    }

    /** Категория на плашке — та же подпись, что и в настройках судейской коллегии. */
    public function test_category_label_matches_judge_collegium_settings(): void
    {
        $user = $this->makeUser('category-label@test.local', 'judge');

        $this->assertSame('Международная категория (ССМК)', User::judgeCategoryOptions()['ССМК']);
        $this->assertSame('Юный судья (ЮС)', User::judgeCategoryOptions()['ЮС']);

        $user->judge_category = 'СС2К';
        $this->assertSame('Вторая категория (СС2К)', $user->judgeCategoryLabel());

        // Неизвестный код не теряется — показываем как есть.
        $user->judge_category = 'НЕТ';
        $this->assertSame('НЕТ', $user->judgeCategoryLabel());

        $user->judge_category = null;
        $this->assertNull($user->judgeCategoryLabel());
    }

    /** Судья с клубом получает личный аватар, а не аватар клуба. */
    public function test_judge_with_club_keeps_personal_avatar(): void
    {
        $club = Club::create([
            'name' => 'Спарта',
            'logo_path' => 'club-logos/sparta.png',
        ]);

        $user = $this->makeUser('judge-banner@test.local', 'judge', [
            'club_id' => $club->id,
            'avatar_path' => 'avatars/face.jpg',
            'name' => 'Судейкин Иван',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($user)
            ->test(AccountWidget::class)
            ->assertSee('avatars/face.jpg', false)
            ->assertDontSee('club-logos/sparta.png', false);
    }

    public static function roleProvider(): array
    {
        return [
            'админ' => ['admin'],
            'тренер' => ['coach'],
            'линейный судья' => ['judge'],
            'старший судья' => ['head_judge'],
        ];
    }

    /** Роли, чья плашка остаётся во всю ширину (все, кроме тренера). */
    public static function fullWidthRoleProvider(): array
    {
        return [
            'админ' => ['admin'],
            'линейный судья' => ['judge'],
            'старший судья' => ['head_judge'],
        ];
    }

    /** Роли судей: линейный и старший. */
    public static function judgeRoleProvider(): array
    {
        return [
            'линейный судья' => ['judge'],
            'старший судья' => ['head_judge'],
        ];
    }

    /** Роли без судейской категории: администратор и тренер. */
    public static function nonJudgeRoleProvider(): array
    {
        return [
            'админ' => ['admin'],
            'тренер' => ['coach'],
        ];
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
