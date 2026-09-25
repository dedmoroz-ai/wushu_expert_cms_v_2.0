<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Правила 8.2 и 8.3 (docs/JUDGING_RULES.md): единый диапазон допустимых оценок
 * с учётом лимитов возрастной категории.
 *
 * Ранее поля min_score/max_score были добавлены в таблицу `categories`
 * (миграция 2026_01_28_105148), но модели Category в проекте нет, а лимиты
 * по требованию заказчика относятся именно к возрастной категории.
 * Поэтому лимиты переносим в `age_groups`.
 *
 * Пустое значение = лимит не задан, применяется общесистемный диапазон
 * (см. App\Support\ScoreRange::GLOBAL_MIN / GLOBAL_MAX).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('age_groups')) {
            return;
        }

        Schema::table('age_groups', function (Blueprint $table) {
            if (!Schema::hasColumn('age_groups', 'min_score')) {
                $table->decimal('min_score', 5, 3)->nullable()->comment('Минимально допустимая оценка судьи');
            }

            if (!Schema::hasColumn('age_groups', 'max_score')) {
                $table->decimal('max_score', 5, 3)->nullable()->comment('Максимально допустимая оценка судьи');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('age_groups')) {
            return;
        }

        Schema::table('age_groups', function (Blueprint $table) {
            $columns = array_values(array_filter(
                ['min_score', 'max_score'],
                fn (string $column) => Schema::hasColumn('age_groups', $column)
            ));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
