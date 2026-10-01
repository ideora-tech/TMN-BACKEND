<?php

declare(strict_types=1);

namespace App\Modules\ProyekUnit;

use App\Models\BaseModel;

class ProyekUnitModel extends BaseModel
{
    protected $table = 'proyek_unit';
    protected $primaryKey = 'id_proyek_unit';

    protected $fillable = [
        'id_proyek_unit',
        'id_perusahaan',
        'id_proyek',
        'sumber',
        'id_armada',
        'id_armada_vendor',
    ];

    public static function kunci(string $sumber, ?string $idUnit): string
    {
        return $sumber . ':' . $idUnit;
    }
}
