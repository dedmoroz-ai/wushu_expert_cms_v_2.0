<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Правило R-7.8 (docs/JUDGING_RULES.md): глобальный справочник кодов сбавок
 * судьи A (сценарий A/B).
 */
class DeductionCode extends Model
{
    protected $fillable = ['code', 'label', 'value', 'group_label', 'sort_order', 'is_active'];

    protected $casts = [
        'value' => 'double',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('code');
    }
}
