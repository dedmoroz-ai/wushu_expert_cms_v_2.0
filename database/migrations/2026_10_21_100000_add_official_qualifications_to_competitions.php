<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competitions', function (Blueprint $table) {
            // Замечание заказчика (21.10): судейская квалификация Главного судьи
            // и Главного секретаря — для листа «Состав судейской коллегии»
            // итогового протокола (в подписях подвала не печатается).
            $table->string('chief_judge_qualification')->nullable()->after('chief_judge_signature');
            $table->string('chief_secretary_qualification')->nullable()->after('chief_secretary_signature');
        });
    }

    public function down(): void
    {
        Schema::table('competitions', function (Blueprint $table) {
            $table->dropColumn([
                'chief_judge_qualification', 'chief_secretary_qualification',
            ]);
        });
    }
};