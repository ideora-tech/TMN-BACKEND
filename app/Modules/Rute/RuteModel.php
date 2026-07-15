<?php
namespace App\Modules\Rute;
use App\Models\BaseModel;

class RuteModel extends BaseModel {
    protected $table = 'rute';
    protected $primaryKey = 'id_rute';
    protected $fillable = [
        'id_rute','id_perusahaan','kode_rute','nama_rute',
        'asal','tujuan','id_lokasi_asal','id_lokasi_tujuan',
        'estimasi_jarak_km','estimasi_durasi_menit',
        'keterangan','aktif',
    ];
}