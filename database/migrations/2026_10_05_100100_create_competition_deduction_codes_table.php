<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Решение заказчика (05.10, вариант A): набор кодов сбавок per-competition —
 * override у соревнования для пульта судьи A; глобальный справочник
 * deduction_codes (is_active) — набор по умолчанию.
 *
 * Наличие строки = код показывается на пульте судьи A именно этого турнира;
 * порядок кодов наследуется из глобального справочника (sort_order, code).
 * Пустой набор соревнования = глобальный активный набор.
 *
 * Снимок выставленных сбавок (score_deductions) не затрагивается: code/label/value
 * хранятся в оценке, правка набора не меняет задним числом прошлые выступления.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('competition_deduction_codes')) {
            Schema::create('competition_deduction_codes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('competition_id')->constrained('competitions')->cascadeOnDelete();
                $table->foreignId('deduction_code_id')->constrained('deduction_codes')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['competition_id', 'deduction_code_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_deduction_codes');
    }
};
