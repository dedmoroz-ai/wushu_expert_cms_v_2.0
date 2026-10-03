<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Замечание заказчика (02.10): вместо заглушек — круглые фото
            // профиля. Аватар загружает админ в карточке пользователя,
            // отображается в плашке «Добро пожаловать» на дашборде.
            $table->string('avatar_path')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
    }
};
