<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Правила R-3.12–R-3.16, R-7.8 (docs/JUDGING_RULES.md): сбавки судьи A.
 *
 *  deduction_codes  — глобальный справочник кодов сбавок (настраивается администратором)
 *  score_deductions — какие коды нажал судья A в конкретной оценке.
 *                     code/label/value хранятся снимком, чтобы правка справочника
 *                     не меняла задним числом уже выставленные оценки.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('deduction_codes')) {
            Schema::create('deduction_codes', function (Blueprint $table) {
                $table->id();
                $table->string('code', 20)->unique()->comment('Код сбавки, например 10');
                $table->string('label')->comment('Описание ошибки');
                $table->decimal('value', 4, 3)->comment('Размер сбавки, баллы (0.1–0.5)');
                $table->string('group_label')->nullable()->comment('Группа ошибок для группировки кнопок');
                $table->integer('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('score_deductions')) {
            Schema::create('score_deductions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('score_id')->constrained('scores')->cascadeOnDelete();
                $table->foreignId('deduction_code_id')->nullable()->constrained('deduction_codes')->nullOnDelete();
                $table->string('code', 20);
                $table->string('label');
                $table->decimal('value', 4, 3);
                $table->timestamps();

                $table->index('score_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('score_deductions');
        Schema::dropIfExists('deduction_codes');
    }
};
