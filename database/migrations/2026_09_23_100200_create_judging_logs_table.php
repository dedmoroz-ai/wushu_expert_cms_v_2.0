<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Правило 8.9 (docs/JUDGING_RULES.md): журнал действий (аудит) судейства.
 *
 * Фиксируем, кто и когда ввёл, изменил или удалил оценку, а также кто
 * правил итоговый балл и утверждал протокол.
 *
 * action:
 *   score_created          — судья выставил оценку
 *   score_updated          — оценка изменена (самим судьёй или старшим судьёй)
 *   score_deleted          — оценка снята (перевыставление)
 *   final_score_changed    — изменён итоговый балл участника
 *   protocol_finalized     — протокол участника утверждён
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('judging_logs')) {
            return;
        }

        Schema::create('judging_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('competition_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('registration_id')->nullable()->constrained()->nullOnDelete();

            // Судья, к чьей оценке относится запись (для final_score_changed — null).
            $table->foreignId('judge_id')->nullable()->constrained('users')->nullOnDelete();

            // Кто выполнил действие (может отличаться от judge_id: правка старшим судьёй).
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('action', 40);
            $table->decimal('old_value', 8, 3)->nullable();
            $table->decimal('new_value', 8, 3)->nullable();
            $table->string('reason')->nullable();
            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            $table->index(['registration_id', 'created_at']);
            $table->index(['competition_id', 'created_at']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('judging_logs');
    }
};
