<?php

declare(strict_types=1);

namespace App\Modules\IzinPeran;

use App\Traits\HasAuditColumns;
use App\Traits\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

class IzinPeranModel extends Model
{
    use HasUuidPrimaryKey, HasAuditColumns;

    public $timestamps = false;

    protected $table = 'izin_peran';
    protected $primaryKey = 'id_izin';

    protected $fillable = [
        'id_izin',
        'id_perusahaan',
        'kode_peran',
        'id_menu',
        'aksi',
        'diizinkan',
    ];
}
