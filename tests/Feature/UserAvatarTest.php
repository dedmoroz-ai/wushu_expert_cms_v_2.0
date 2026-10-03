<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Widgets\AccountWidget;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Замечание заказчика (02.10): вместо заглушек ui-avatars — круглые
 * фото профиля. Аватар загружает админ в карточке пользователя
 * (UserResource), отображается в плашке «Добро пожаловать» на дашборде.
 */
class UserAvatarTest extends TestCase
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

    /** Модель отдаёт URL загруженного фото; без файла — null (заглушка Filament). */
    public function test_user_model_exposes_uploaded_avatar_url(): void
    {
        $user = $this->makeUser('avatar-model@test.local', 'admin');

        $this->assertNull($user->getFilamentAvatarUrl());

        $user->avatar_path = 'avatars/face.jpg';

        $this->assertSame(
            asset('storage/avatars/face.jpg'),
            $user->getFilamentAvatarUrl(),
        );
    }

    /**
     * На дашборде каждой роли заглушка ui-avatars заменяется загруженным
     * фото (круглый компонент Filament).
     */
    #[DataProvider('roleProvider')]
    public function test_dashboard_shows_uploaded_avatar_instead_of_placeholder(string $role): void
    {
        $user = $this->makeUser("avatar-dash-{$role}@test.local", $role, [
            'avatar_path' => 'avatars/face.jpg',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($user)
            ->test(AccountWidget::class)
            ->assertSee('storage/avatars/face.jpg', false)
            ->assertDontSee('ui-avatars.com', false);

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk()
            ->assertSee('storage/avatars/face.jpg', false)
            ->assertDontSee('ui-avatars.com', false);
    }

    /** Без загруженного фото остаётся прежняя заглушка. */
    public function test_dashboard_keeps_placeholder_without_avatar(): void
    {
        $user = $this->makeUser('avatar-placeholder@test.local', 'coach');

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($user)
            ->test(AccountWidget::class)
            ->assertSee('ui-avatars.com', false);
    }

    /** Админ загружает фото в карточке пользователя: файл сохраняется, путь пишется в БД. */
    public function test_admin_can_upload_avatar_in_user_settings(): void
    {
        Storage::fake('public');

        $admin = $this->makeUser('avatar-admin@test.local', 'admin');
        $target = $this->makeUser('avatar-target@test.local', 'judge');

        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
            ->assertFormFieldExists('avatar_path')
            ->fillForm([
                'avatar_path' => UploadedFile::fake()->image('face.jpg'),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $target->refresh();

        $this->assertNotNull($target->avatar_path);
        $this->assertStringStartsWith('avatars/', $target->avatar_path);
        Storage::disk('public')->assertExists($target->avatar_path);
    }

    /** В списке пользователей фото показывается круглым. */
    public function test_users_table_shows_circular_avatar(): void
    {
        // ImageColumn проверяет существование файла на диске — кладём файл.
        Storage::fake('public');
        Storage::disk('public')->put('avatars/face.jpg', 'fake-image');

        $admin = $this->makeUser('avatar-list-admin@test.local', 'admin');
        $this->makeUser('avatar-list-judge@test.local', 'judge', [
            'avatar_path' => 'avatars/face.jpg',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($admin)
            ->test(ListUsers::class)
            ->assertSee('storage/avatars/face.jpg', false)
            ->assertSee('rounded-full', false);
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
