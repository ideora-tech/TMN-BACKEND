<?php

declare(strict_types=1);

namespace App\Modules\Penugasan;

use App\Models\BaseModel;
use App\Modules\Armada\ArmadaModel;
use App\Modules\ArmadaVendor\ArmadaVendorModel;
use App\Modules\Proyek\ProyekModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PenugasanModel extends BaseModel
{
    protected $table = 'penugasan';
    protected $primaryKey = 'id_penugasan';

    protected $fillable = [
        'id_penugasan',
        'id_proyek',
        'id_armada',
        'id_supir',
        'id_karyawan',
        'id_rute',
        'id_pengajuan',
        'tanggal_tugas',
        'status',
        'estimasi_biaya',
        'keterangan',
        'sumber',
        'id_kontrak_vendor',
        'id_armada_vendor',
        'id_supir_vendor',
    ];

    protected $attributes = [
        'sumber' => 'internal',
    ];

    public function proyek(): BelongsTo
    {
        return $this->belongsTo(ProyekModel::class, 'id_proyek', 'id_proyek');
    }

    public function armada(): BelongsTo
    {
        return $this->belongsTo(ArmadaModel::class, 'id_armada', 'id_armada');
    }

    public function armadaVendor(): BelongsTo
    {
        return $this->belongsTo(ArmadaVendorModel::class, 'id_armada_vendor', 'id_armada_vendor');
    }
}
