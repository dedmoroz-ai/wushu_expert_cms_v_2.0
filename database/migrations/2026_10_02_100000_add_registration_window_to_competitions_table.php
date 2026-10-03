<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Замечание заказчика (02.10): окно подачи заявок тренерами —
     * два поля «Открытие/Закрытие подачи заявок». По ним дашборд считает
     * статус сессии регистрации («Ожидает открытия» / «Идёт регистрация» /
     * «Регистрация завершена»). Если оба пусты — «Не задано».
     */
    public function up(): void
    {
        Schema::table('competitions', function (Blueprint $table) {
            $table->timestamp('registration_opens_at')->nullable();
            $table->timestamp('registration_closes_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('competitions', function (Blueprint $table) {
            $table->dropColumn(['registration_opens_at', 'registration_closes_at']);
        });
    }
};
