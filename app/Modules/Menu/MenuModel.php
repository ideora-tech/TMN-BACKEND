<?php

declare(strict_types=1);

namespace App\Modules\Menu;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuModel extends BaseModel
{
    protected $table = 'menu';
    protected $primaryKey = 'id_menu';

    protected $fillable = [
        'id_menu',
        'nama_menu',
        'path',
        'id_menu_induk',
        'icon',
        'urutan',
        'aktif',
    ];

    public function children(): HasMany
    {
        return $this->hasMany(MenuModel::class, 'id_menu_induk', 'id_menu');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(MenuModel::class, 'id_menu_induk', 'id_menu');
    }

    // Role yang boleh akses menu ini
    public function perans(): HasMany
    {
        return $this->hasMany(MenuPeran::class, 'id_menu', 'id_menu');
    }
}
