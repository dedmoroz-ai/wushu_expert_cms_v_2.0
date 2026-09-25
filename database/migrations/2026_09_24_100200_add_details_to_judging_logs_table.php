<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Правило R-6.13 (docs/JUDGING_RULES.md): подробный аудит.
 *
 *  judging_logs.details — json: панель, коды сбавок, состав расчёта итога
 *  judging_logs.reason  — varchar(255) → text (краткое резюме может быть длинным)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('judging_logs')) {
            return;
        }

        Schema::table('judging_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('judging_logs', 'details')) {
                $table->json('details')->nullable();
            }

            $table->text('reason')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('judging_logs')) {
            return;
        }

        Schema::table('judging_logs', function (Blueprint $table) {
            if (Schema::hasColumn('judging_logs', 'details')) {
                $table->dropColumn('details');
            }
        });
    }
};
