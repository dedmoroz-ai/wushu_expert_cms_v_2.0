<?php

namespace Tests\Feature;

use App\Filament\Pages\JudgePad;
use App\Filament\Pages\SuperJudgePad;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Замечание заказчика (03.10): кнопка «Выход» в правом верхнем углу судейского
 * пульта должна выводить только из пульта (возврат на «Инфопанель»), но не из
 * аккаунта. Полный выход остаётся в меню аккаунта (стандартный logout Filament).
 */
class JudgePadExitTest extends TestCase
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
     * Пульт линейного судьи: кнопка выхода уводит на «Инфопанель»,
     * судья остаётся авторизованным.
     */
    public function test_judge_pad_exits_to_dashboard_without_logout(): void
    {
        $judge = $this->makeUser('exit-judge@test.local', 'judge');

        $this->actingAs($judge);

        Livewire::test(JudgePad::class)
            ->assertSeeHtml('wire:click="exitPad"')
            ->call('exitPad')
            ->assertRedirect('/admin');

        $this->assertAuthenticated();
        $this->assertTrue($judge->is(auth()->user()));
    }

    /** Пульт Старшего судьи: выход из пульта на «Инфопанель», сессия сохраняется. */
    public function test_super_judge_pad_exits_to_dashboard_without_logout(): void
    {
        $head = $this->makeUser('exit-head@test.local', 'head_judge');

        $this->actingAs($head);

        Livewire::test(SuperJudgePad::class)
            ->assertSeeHtml('wire:click="exitPad"')
            ->call('exitPad')
            ->assertRedirect('/admin');

        $this->assertAuthenticated();
        $this->assertTrue($head->is(auth()->user()));
    }

    /** Админ в пульте (режим просмотра) тоже выходит только из пульта. */
    public function test_admin_exits_super_judge_pad_without_logout(): void
    {
        $admin = $this->makeUser('exit-admin@test.local', 'admin');

        $this->actingAs($admin);

        Livewire::test(SuperJudgePad::class)
            ->call('exitPad')
            ->assertRedirect('/admin');

        $this->assertAuthenticated();
        $this->assertTrue($admin->is(auth()->user()));
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
