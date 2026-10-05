<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competitions', function (Blueprint $table) {
            // Замечание заказчика (05.10): аватар самого турнира — картинка,
            // которая показывается справа от логотипа (в том же размере) на
            // виджете «Актуальное соревнование», публичной странице результатов
            // и табло. В документы (протоколы, дипломы) не входит.
            $table->string('avatar_path')->nullable()->after('organization_logo');
        });
    }

    public function down(): void
    {
        Schema::table('competitions', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
    }
};