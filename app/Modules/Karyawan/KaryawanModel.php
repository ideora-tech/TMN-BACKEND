<?php

declare(strict_types=1);

namespace App\Modules\Karyawan;

use App\Models\BaseModel;

class KaryawanModel extends BaseModel
{
    protected $table = 'karyawan';
    protected $primaryKey = 'id_karyawan';

    protected $fillable = [
        'id_karyawan',
        'id_perusahaan',
        'id_jabatan',
        'id_lokasi',
        'nik',
        'nama_karyawan',
        'email',
        'telepon',
        'jenis_kelamin',
        'tanggal_lahir',
        'tanggal_masuk',
        'status_kepegawaian',
        'gaji_pokok',
        'aktif',
    ];

    public function jabatan(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\Jabatan\JabatanModel::class, 'id_jabatan', 'id_jabatan');
    }

    public function lokasi(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\LokasiKantor\LokasiKantorModel::class, 'id_lokasi', 'id_lokasi');
    }

    public function exitHistory(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Modules\KaryawanExit\KaryawanExitModel::class, 'id_karyawan', 'id_karyawan');
    }
}
