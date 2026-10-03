<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Замечание заказчика (01.10): у судей свой набор пунктов меню.
            // «Аналитика» включена по умолчанию, «Сводка оценок» и «Журнал судейства»
            // включаются/выключаются админом в настройках судей.
            $table->boolean('show_analytics')->default(true)->after('judge_category');
            $table->boolean('show_scores_summary')->default(false)->after('show_analytics');
            $table->boolean('show_judging_log')->default(false)->after('show_scores_summary');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['show_analytics', 'show_scores_summary', 'show_judging_log']);
        });
    }
};
