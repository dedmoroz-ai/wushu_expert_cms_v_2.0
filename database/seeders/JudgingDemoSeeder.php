<?php

namespace Database\Seeders;

use App\Models\AgeGroup;
use App\Models\Athlete;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\Style;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Демо-данные для локальной проверки судейских пультов
 * (docs/JUDGING_RULES.md, п. 8.1–8.9).
 *
 * Запуск: php artisan db:seed --class=JudgingDemoSeeder
 *
 * Все пароли: password
 */
class JudgingDemoSeeder extends Seeder
{
    public function run(): void
    {
        $club = Club::firstOrCreate(
            ['name' => 'Демо-клуб «Ушу-Эксперт»'],
            ['city' => 'Москва', 'region' => 'Москва']
        );

        // Возрастная группа с суженным диапазоном — для проверки лимитов (п. 8.3).
        $ageGroup = AgeGroup::firstOrCreate(
            ['name' => 'Демо 12-15', 'gender' => 'male'],
            [
                'min_age' => 12,
                'max_age' => 15,
                'sort_order' => 1,
                'min_score' => 5.000,
                'max_score' => 10.000,
            ]
        );

        $style = Style::firstOrCreate(
            ['name' => 'Чанцюань'],
            ['category' => 'taolu', 'sort_order' => 1]
        );

        $admin = User::updateOrCreate(
            ['email' => 'admin@demo.local'],
            [
                'name' => 'Администратор',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'is_active_judge' => false,
            ]
        );

        $headJudge = User::updateOrCreate(
            ['email' => 'head@demo.local'],
            [
                'name' => 'Старший судья Петров',
                'password' => Hash::make('password'),
                'role' => 'head_judge',
                'is_active_judge' => true,
            ]
        );

        // Три линейных судьи в бригаде.
        $judges = collect(['Иванов', 'Сидоров', 'Кузнецов'])
            ->map(fn (string $surname, int $i) => User::updateOrCreate(
                ['email' => 'judge' . ($i + 1) . '@demo.local'],
                [
                    'name' => 'Судья ' . $surname,
                    'password' => Hash::make('password'),
                    'role' => 'judge',
                    'is_active_judge' => true,
                ]
            ));

        // Судья НЕ в бригаде — для проверки п. 8.5 (должен получить отказ).
        $outsider = User::updateOrCreate(
            ['email' => 'judge-outsider@demo.local'],
            [
                'name' => 'Судья Вне-Бригады',
                'password' => Hash::make('password'),
                'role' => 'judge',
                'is_active_judge' => true,
            ]
        );

        $competition = Competition::updateOrCreate(
            ['name' => 'Демо-турнир (проверка судейства)'],
            [
                'start_date' => now()->toDateString(),
                'city' => 'Москва',
                'status_code' => 1,
                'max_events' => 2,
                // Правило R-2.11: базовое демо — простой сценарий.
                'judging_scheme' => Competition::SCHEME_SIMPLE,
            ]
        );

        // Бригада турнира (п. 8.4): старший + 3 линейных. Вне бригады — не добавляем.
        $competition->judges()->sync(
            $judges->pluck('id')->push($headJudge->id)->all()
        );

        // Пять участников, чтобы проверить автопереход только вперёд (п. 8.8).
        $registrations = collect();

        foreach (range(1, 5) as $n) {
            $athlete = Athlete::updateOrCreate(
                ['name' => "Спортсмен №{$n}", 'club_id' => $club->id],
                [
                    'birth_date' => now()->subYears(14)->toDateString(),
                    'gender' => 'male',
                ]
            );

            $registrations->push(Registration::updateOrCreate(
                [
                    'competition_id' => $competition->id,
                    'athlete_id' => $athlete->id,
                ],
                [
                    'style_id' => $style->id,
                    'age_group_id' => $ageGroup->id,
                    'age_group_label' => $ageGroup->name,
                    'sort_order' => $n,
                    'status' => 0,
                    'is_completed' => false,
                    'final_score' => null,
                ]
            ));
        }

        // Ставим на ковёр третьего — впереди есть №4 и №5, позади «пропущены» №1 и №2.
        $competition->update([
            'current_registration_id' => $registrations[2]->id,
        ]);

        $this->command->info('Демо-данные готовы. Пароль у всех: password');
        $this->command->table(
            ['Роль', 'E-mail', 'Что проверять'],
            [
                ['admin', $admin->email, 'Журнал судейства, лимиты возрастных групп'],
                ['head_judge', $headJudge->email, 'Пульт старшего судьи, /super-judge-pad'],
                ['judge', 'judge1@demo.local', 'Пульт судьи — доступ есть (в бригаде)'],
                ['judge', 'judge2@demo.local', 'Пульт судьи — доступ есть (в бригаде)'],
                ['judge', 'judge3@demo.local', 'Пульт судьи — доступ есть (в бригаде)'],
                ['judge', $outsider->email, 'Пульт судьи — доступа НЕТ (п. 8.5)'],
            ]
        );
        $this->command->info('На ковре: Спортсмен №3 (sort_order=3). Позади не оценены №1 и №2 — проверка п. 8.8.');
        $this->command->info('Диапазон демо-категории: 5.000–10.000 — проверка п. 8.2/8.3.');
    }
}
