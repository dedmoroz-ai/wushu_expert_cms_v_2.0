<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

        // Боевая база 29.09.2026 (репетиция слияния): одна строка с опечаткой
        // пропущенной точки — 835.0000 вместо 8.35 (ввод на пульте до фиксов
        // точности). Значение вне домена 0.000–10.000 не влезает в numeric(5,3)
        // и роняло ALTER (numeric field overflow). Опечатки точки исправляем
        // делением на 10 до вхождения в домен (835 → 83.5 → 8.35); это
        // НЕ меняет итоги турниров: трим-среднее отбрасывает такой выброс
        // как максимум (заявка 448: final_score 8.2470 одинаков и при 835,
        // и при 8.35). Удаление строки НЕЛЬЗЯ — изменило бы результат.
        $bad = DB::table('scores')
            ->where('score', '>', 10)
            ->orWhere('score', '<', 0)
            ->get(['id', 'score']);

        foreach ($bad as $row) {
            $value = (float) $row->score;
            while ($value > 10.0) {
                $value /= 10;
            }
            if ($value < 0) {
                $value = 0.0;
            }
            DB::table('scores')->where('id', $row->id)->update(['score' => round($value, 3)]);
        }

        if ($bad->isNotEmpty()) {
            echo 'change_scores_score_precision: исправлено опечаток пропущенной точки: '.$bad->count().PHP_EOL;
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
