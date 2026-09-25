<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // ВАЖНО (правило 8.3): таблица `categories` в проекте не создаётся
        // ни одной миграцией, а модели Category нет. Лимиты баллов перенесены
        // в `age_groups` (миграция 2026_09_23_100100). Эта миграция оставлена
        // для совместимости с уже развёрнутыми базами и выполняется только,
        // если таблица реально существует.
        if (!Schema::hasTable('categories')) {
            return;
        }

        Schema::table('categories', function (Blueprint $table) {
            // Добавляем универсальные поля лимитов (для всех видов)
            // 4 цифры всего, 3 после запятой (например, 3.000)
            if (!Schema::hasColumn('categories', 'min_score')) {
                $table->decimal('min_score', 4, 3)->nullable()->after('name')->comment('Минимальный балл');
            }

            if (!Schema::hasColumn('categories', 'max_score')) {
                $table->decimal('max_score', 4, 3)->nullable()->after('min_score')->comment('Максимальный балл');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['min_score', 'max_score']);
        });
    }
};
