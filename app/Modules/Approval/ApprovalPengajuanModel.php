<?php

declare(strict_types=1);

namespace App\Modules\Approval;

use App\Models\BaseModel;

class ApprovalPengajuanModel extends BaseModel
{
    protected $table = 'approval_pengajuan';
    protected $primaryKey = 'id_approval';

    protected $fillable = [
        'id_approval',
        'id_perusahaan',
        'id_event_type',
        'id_referensi',
        'id_pengguna_pengaju',
        'nominal',
        'status',
        'alasan_ditolak',
    ];
}
