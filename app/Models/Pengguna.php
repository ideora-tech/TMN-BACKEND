<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasSoftDeleteColumns;
use App\Traits\HasUuidPrimaryKey;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Pengguna extends Authenticatable
{
    use HasApiTokens, HasUuidPrimaryKey, HasAuditColumns, HasSoftDeleteColumns;

    protected $table = 'pengguna';
    protected $primaryKey = 'id_pengguna';
    public $timestamps = false;

    protected $hidden = ['kata_sandi'];

    protected $fillable = [
        'id_pengguna', 'id_perusahaan', 'kode_peran', 'id_karyawan',
        'username', 'email', 'kata_sandi', 'aktif',
        'harus_ganti_password', 'login_terakhir',
    ];

    public function getAuthPassword(): string
    {
        return $this->kata_sandi;
    }
}
