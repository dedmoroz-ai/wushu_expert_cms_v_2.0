<?php

namespace Tests\Feature;

use App\Filament\Pages\CoachGuide;
use App\Filament\Pages\JudgeGuide;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Раздел «Документация» в сайдбаре: «Для тренеров» и «Для судей» —
 * страницы рендерят руководства из docs/*.md и доступны всем ролям.
 *
 * Тест намеренно без БД (страницы только читают markdown-файлы),
 * чтобы он выполнялся в любом окружении.
 */
class DocsPagesTest extends TestCase
{
    /** Раздел «Документация» объявлен последним в порядке сайдбара. */
    public function test_documentation_group_is_declared_last(): void
    {
        $panel = Filament::getPanel('admin');

        $groupIds = array_map(
            static fn (array|string $group): string => is_array($group) ? (string) ($group['id'] ?? '') : $group,
            $panel->getNavigationGroups(),
        );

        $this->assertContains('Документация', $groupIds);
        $this->assertSame('Документация', end($groupIds));
    }

    /** Обе страницы — в разделе «Документация» с понятными подписями. */
    public function test_pages_are_in_documentation_group(): void
    {
        $this->assertSame('Документация', CoachGuide::getNavigationGroup());
        $this->assertSame('Документация', JudgeGuide::getNavigationGroup());

        $this->assertSame('Для тренеров', CoachGuide::getNavigationLabel());
        $this->assertSame('Для судей', JudgeGuide::getNavigationLabel());
    }

    /** Руководства в меню и открываются у всех ролей: админ, старший судья, судья, тренер. */
    public function test_every_role_can_open_guides(): void
    {
        foreach (['admin', 'head_judge', 'judge', 'coach'] as $role) {
            $user = User::make([
                'name' => 'Тестовый пользователь',
                'email' => "docs-{$role}@test.local",
                'role' => $role,
                'is_active_judge' => $role !== 'admin',
            ]);

            Filament::setCurrentPanel(Filament::getPanel('admin'));
            $this->actingAs($user);

            foreach ([CoachGuide::class, JudgeGuide::class] as $page) {
                $this->assertTrue(
                    $page::shouldRegisterNavigation(),
                    "Пункт «{$page}» скрыт в меню роли «{$role}».",
                );

                Livewire::test($page)
                    ->assertSee('Содержание')
                    ->assertSee('<table', false);
            }
        }
    }
}
