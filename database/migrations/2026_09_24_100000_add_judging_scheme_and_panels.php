<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Правила R-2.11, R-4.18–R-4.20 (docs/JUDGING_RULES.md): сценарии судейства
 * `simple` / `ab` и панели судей A (исполнение) / B (общее впечатление).
 *
 *  competitions.judging_scheme   — сценарий турнира (simple | ab), по умолчанию simple
 *  competition_user.panel        — функция судьи в бригаде турнира (A | B)
 *  scores.panel                  — снимок функции судьи на момент выставления оценки
 *  registrations.score_a/score_b — утверждённые средние панелей (для протокола/аудита)
 *  age_groups.b_min/b_max_score  — лимиты оценок судей B по возрастной категории (0–5)
 *
 * Миграция идемпотентна: повторный запуск ничего не ломает.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('competitions') && !Schema::hasColumn('competitions', 'judging_scheme')) {
            Schema::table('competitions', function (Blueprint $table) {
                $table->string('judging_scheme', 8)->default('simple')->comment('Сценарий судейства: simple | ab');
            });
        }

        if (Schema::hasTable('competition_user') && !Schema::hasColumn('competition_user', 'panel')) {
            Schema::table('competition_user', function (Blueprint $table) {
                $table->string('panel', 1)->nullable()->comment('Функция судьи: A | B');
            });
        }

        if (Schema::hasTable('scores') && !Schema::hasColumn('scores', 'panel')) {
            Schema::table('scores', function (Blueprint $table) {
                $table->string('panel', 1)->nullable()->comment('Панель судьи на момент оценки: A | B');
            });
        }

        if (Schema::hasTable('registrations')) {
            Schema::table('registrations', function (Blueprint $table) {
                if (!Schema::hasColumn('registrations', 'score_a')) {
                    $table->decimal('score_a', 5, 3)->nullable()->comment('Среднее панели A');
                }

                if (!Schema::hasColumn('registrations', 'score_b')) {
                    $table->decimal('score_b', 5, 3)->nullable()->comment('Среднее панели B');
                }
            });
        }

        if (Schema::hasTable('age_groups')) {
            Schema::table('age_groups', function (Blueprint $table) {
                if (!Schema::hasColumn('age_groups', 'b_min_score')) {
                    $table->decimal('b_min_score', 5, 3)->nullable()->comment('Мин. оценка судьи B');
                }

                if (!Schema::hasColumn('age_groups', 'b_max_score')) {
                    $table->decimal('b_max_score', 5, 3)->nullable()->comment('Макс. оценка судьи B');
                }
            });
        }
    }

    public function down(): void
    {
        $drop = [
            'competitions' => ['judging_scheme'],
            'competition_user' => ['panel'],
            'scores' => ['panel'],
            'registrations' => ['score_a', 'score_b'],
            'age_groups' => ['b_min_score', 'b_max_score'],
        ];

        foreach ($drop as $tableName => $columns) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            $existing = array_values(array_filter(
                $columns,
                fn (string $column) => Schema::hasColumn($tableName, $column)
            ));

            if ($existing !== []) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn($existing));
            }
        }
    }
};
