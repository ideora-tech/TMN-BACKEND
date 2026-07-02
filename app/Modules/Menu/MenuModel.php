<?php

declare(strict_types=1);

namespace App\Modules\Menu;

use App\Models\BaseModel;

class MenuModel extends BaseModel
{
    protected $table = 'menu';
    protected $primaryKey = 'id_menu';

    protected $fillable = [
        'id_menu',
        'nama_menu',
        'path',
        'id_menu_induk',
        'urutan',
        'aktif',
    ];

    public function children(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MenuModel::class, 'id_menu_induk', 'id_menu');
    }

    public function parent(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(MenuModel::class, 'id_menu_induk', 'id_menu');
    }
}
