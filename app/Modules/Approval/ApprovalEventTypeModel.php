<?php

declare(strict_types=1);

namespace App\Modules\Approval;

use App\Models\BaseModel;

class ApprovalEventTypeModel extends BaseModel
{
    protected $table = 'approval_event_type';
    protected $primaryKey = 'id_event_type';

    protected $fillable = [
        'id_event_type',
        'id_perusahaan',
        'kode',
        'nama',
        'mode_resolusi',
        'aktif',
    ];
}
