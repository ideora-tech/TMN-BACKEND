<?php
declare(strict_types=1);
namespace App\Modules\Supir;

use App\Models\BaseModel;

class SupirModel extends BaseModel
{
    protected $table = 'supir';
    protected $primaryKey = 'id_supir';

    protected $fillable = [
        'id_supir', 'id_pengguna', 'id_perusahaan', 'nama', 'no_sim',
        'jenis_sim', 'tgl_kadaluarsa_sim', 'telepon', 'status', 'foto',
    ];
}
