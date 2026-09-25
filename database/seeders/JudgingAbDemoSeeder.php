<?php

namespace Database\Seeders;

use App\Models\AgeGroup;
use App\Models\Competition;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Демо сценария A/B (docs/JUDGING_RULES.md, R-2.11, R-3.12–R-3.16, R-4.18–R-4.20).
 *
 * Берёт демо-турнир из JudgingDemoSeeder и переключает его на сценарий A/B:
 *  - судьи A: judge1, judge2, head (старший судья) — ставят оценку сбавками;
 *  - судьи B: judge3, judge4 — ставят оценку 0–5 (лимит категории B: 2.000–5.000).
 *
 * Запуск: php artisan db:seed --class=JudgingAbDemoSeeder
 * Вернуть простой сценарий: php artisan db:seed --class=JudgingDemoSeeder
 */
class JudgingAbDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([JudgingDemoSeeder::class, DeductionCodeSeeder::class]);

        $competition = Competition::where('name', 'Демо-турнир (проверка судейства)')->firstOrFail();
        $competition->update(['judging_scheme' => Competition::SCHEME_AB]);

        AgeGroup::where('name', 'Демо 12-15')->update([
            'b_min_score' => 2.000,
            'b_max_score' => 5.000,
        ]);

        User::updateOrCreate(
            ['email' => 'judge4@demo.local'],
            [
                'name' => 'Судья Смирнов',
                'password' => Hash::make('password'),
                'role' => 'judge',
                'is_active_judge' => true,
            ]
        );

        $panels = [
            'judge1@demo.local' => Competition::PANEL_A,
            'judge2@demo.local' => Competition::PANEL_A,
            'head@demo.local' => Competition::PANEL_A,
            'judge3@demo.local' => Competition::PANEL_B,
            'judge4@demo.local' => Competition::PANEL_B,
        ];

        $sync = [];
        foreach ($panels as $email => $panel) {
            $sync[User::where('email', $email)->value('id')] = ['panel' => $panel];
        }

        $competition->judges()->sync($sync);

        $this->command->info('Сценарий A/B включён. A: judge1, judge2, head. B: judge3, judge4 (пароль: password).');
        $this->command->info('Лимит судей B в демо-категории: 2.000–5.000. Итог = среднее A + среднее B.');
    }
}
