<?php

declare(strict_types=1);

namespace App\Modules\BriefingSupir;

use App\Models\BaseModel;

class BriefingSupirModel extends BaseModel
{
    protected $table = 'briefing_supir';
    protected $primaryKey = 'id_briefing';

    protected $fillable = [
        'id_briefing',
        'id_penugasan',
        'catatan_rute',
        'catatan_keselamatan',
        'id_dibriefing_oleh',
        'waktu_briefing',
    ];
}
