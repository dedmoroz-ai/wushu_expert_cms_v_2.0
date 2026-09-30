<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Группа «O» (особые спортсмены) — R-6.15, п. 9.16 (docs/JUDGING_RULES.md).
 *
 * Отметка комбинированно (решение заказчика 30.09.2026):
 *  - athletes.is_special — значение по умолчанию (карточка спортсмена);
 *  - registrations.is_special — финальная отметка под конкретное соревнование,
 *    управляет разбивкой протоколов на подгруппы «(O)».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('athletes', function (Blueprint $table) {
            $table->boolean('is_special')->default(false)->after('rank');
        });

        Schema::table('registrations', function (Blueprint $table) {
            $table->boolean('is_special')->default(false)->after('is_completed');
        });
    }

    public function down(): void
    {
        Schema::table('athletes', function (Blueprint $table) {
            $table->dropColumn('is_special');
        });

        Schema::table('registrations', function (Blueprint $table) {
            $table->dropColumn('is_special');
        });
    }
};
