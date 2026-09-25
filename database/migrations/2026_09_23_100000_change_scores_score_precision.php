<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Правило 8.1 (docs/JUDGING_RULES.md): точность оценок судей.
 *
 * Было: scores.score = decimal(4,2) — СУБД округляла 8.125 до 8.13,
 * хотя пульт старшего судьи позволяет вводить 3 знака, а итоговый балл
 * (registrations.final_score) хранится как decimal(8,3).
 *
 * Стало: scores.score = decimal(5,3) — единая точность 3 знака после точки
 * (максимум 99.999, чего с запасом хватает для диапазона 0.000–10.000).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('scores') || !Schema::hasColumn('scores', 'score')) {
            return;
        }

        Schema::table('scores', function (Blueprint $table) {
            $table->decimal('score', 5, 3)->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('scores') || !Schema::hasColumn('scores', 'score')) {
            return;
        }

        Schema::table('scores', function (Blueprint $table) {
            $table->decimal('score', 4, 2)->change();
        });
    }
};
