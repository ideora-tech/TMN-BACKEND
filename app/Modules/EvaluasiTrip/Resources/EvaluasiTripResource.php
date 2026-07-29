<?php

declare(strict_types=1);

namespace App\Modules\EvaluasiTrip\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class EvaluasiTripResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_evaluasi'           => $this->id_evaluasi,
            'id_penugasan'          => $this->id_penugasan,
            'nilai_armada'          => $this->nilai_armada,
            'nilai_supir'           => $this->nilai_supir,
            'nilai_ketepatan_waktu' => $this->nilai_ketepatan_waktu,
            'nilai_kualitas'        => $this->nilai_kualitas,
            'nilai_harga'           => $this->nilai_harga,
            'nilai_responsif'       => $this->nilai_responsif,
            'catatan'               => $this->catatan,
            'id_dievaluasi_oleh'    => $this->id_dievaluasi_oleh,
            'dibuat_pada'           => $this->dibuat_pada,
            'diubah_pada'           => $this->diubah_pada,
        ];
    }
}
