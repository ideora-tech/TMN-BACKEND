<?php

declare(strict_types=1);

namespace App\Modules\Sparepart\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SparepartRiwayatHargaResource extends JsonResource
{
    public function toArray($request): array
    {
        $lama = $this->harga_lama !== null ? (float) $this->harga_lama : null;
        $baru = (float) $this->harga_baru;
        $selisih = $lama !== null ? $baru - $lama : null;
        $persen = ($lama !== null && $lama != 0.0) ? round($selisih / $lama * 100, 1) : null;

        return [
            'id_riwayat'       => $this->id_riwayat,
            'tanggal'          => $this->dibuat_pada,
            'harga_lama'       => $lama,
            'harga_baru'       => $baru,
            'selisih'          => $selisih,
            'persen'           => $persen,
            'sumber'           => $this->sumber,
            'keterangan'       => $this->keterangan,
            'dibuat_oleh_nama' => $this->dibuat_oleh_nama ?? null,
        ];
    }
}
