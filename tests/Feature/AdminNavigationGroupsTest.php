<?php

namespace Tests\Feature;

use App\Filament\Resources\JudgingLogResource;
use Filament\Facades\Filament;
use Tests\TestCase;

/**
 * Замечание заказчика (01.10): пункт меню «Журнал судейства» перенесён в раздел
 * «Турнир», раздел «Соревнования» убран из сайдбара.
 */
class AdminNavigationGroupsTest extends TestCase
{
    /** «Журнал судейства» теперь в разделе «Турнир». */
    public function test_judging_log_belongs_to_tournament_group(): void
    {
        $this->assertSame('Турнир', JudgingLogResource::getNavigationGroup());
    }

    /** Раздел «Соревнования» убран: ни один пункт меню его не использует. */
    public function test_competitions_group_is_not_used_anymore(): void
    {
        $panel = Filament::getPanel('admin');

        foreach (array_merge($panel->getResources(), $panel->getPages()) as $item) {
            $this->assertNotSame(
                'Соревнования',
                $item::getNavigationGroup(),
                "Пункт меню «{$item}» всё ещё ссылается на удалённый раздел «Соревнования».",
            );
        }
    }

    /** Раздел объявлен в порядке сайдбара и содержит «Журнал судейства». */
    public function test_tournament_group_is_declared_and_contains_judging_log(): void
    {
        $panel = Filament::getPanel('admin');

        $groupIds = array_map(
            static fn (array|string $group): string => is_array($group) ? (string) ($group['id'] ?? '') : $group,
            $panel->getNavigationGroups(),
        );

        $this->assertContains('Турнир', $groupIds);
        $this->assertNotContains('Соревнования', $groupIds);

        $inTournament = [];
        foreach (array_merge($panel->getResources(), $panel->getPages()) as $item) {
            if ($item::getNavigationGroup() === 'Турнир') {
                $inTournament[] = $item;
            }
        }

        $this->assertContains(JudgingLogResource::class, $inTournament);
    }
}
