<?php

declare(strict_types=1);

namespace App\Modules\LaporanPerjalanan;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LaporanPerjalananModel extends BaseModel
{
    protected $table = 'laporan_perjalanan';
    protected $primaryKey = 'id_laporan';

    protected $fillable = [
        'id_laporan',
        'id_perusahaan',
        'id_trip',
        'biaya_bbm',
        'jarak_tempuh_km',
        'uang_jalan',
        'catatan_insiden',
    ];

    protected $casts = [
        'biaya_bbm'       => 'float',
        'jarak_tempuh_km' => 'float',
        'uang_jalan'      => 'float',
    ];

    public function biayaLain(): HasMany
    {
        return $this->hasMany(BiayaLainTripModel::class, 'id_laporan', 'id_laporan')
            ->whereNull('dihapus_pada');
    }

    public function foto(): HasMany
    {
        return $this->hasMany(FotoLaporanPerjalananModel::class, 'id_laporan', 'id_laporan')
            ->whereNull('dihapus_pada');
    }
}
