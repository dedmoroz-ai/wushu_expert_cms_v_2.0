<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Приведение категорий видов программ к единому справочнику.
 *
 * В StyleSeeder стили «Юнчуньцюань - ...» ошибочно создавались с категорией
 * 'traditional', из-за чего в форме заявки они попадали в секцию
 * «Традиционное ушу» вместо «Юнчуньцюань». Идемпотентно переводит их
 * в категорию 'yongchun' (включая строки, добавленные вручную).
 *
 * Категория участвует только в UI (раскладка секций формы заявки и
 * справочник «Виды программы»); протоколы и зачёты группируются по style_id,
 * поэтому данные заявок и результаты не затрагиваются.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('styles')
            ->where('name', 'like', 'Юнчуньцюань%')
            ->where('category', '!=', 'yongchun')
            ->update(['category' => 'yongchun']);
    }

    public function down(): void
    {
        DB::table('styles')
            ->where('name', 'like', 'Юнчуньцюань%')
            ->where('category', 'yongchun')
            ->update(['category' => 'traditional']);
    }
};
