<?php

declare(strict_types=1);

namespace App\Modules\Departemen;

use App\Models\BaseModel;

class DepartemenModel extends BaseModel
{
    protected $table = 'departemen';
    protected $primaryKey = 'id_departemen';

    protected $fillable = [
        'id_departemen',
        'id_perusahaan',
        'id_departemen_induk',
        'kode_departemen',
        'nama_departemen',
        'aktif',
    ];

    public function children(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DepartemenModel::class, 'id_departemen_induk', 'id_departemen');
    }

    public function parent(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(DepartemenModel::class, 'id_departemen_induk', 'id_departemen');
    }
}
