<?php

declare(strict_types=1);

namespace App\Modules\PermintaanPembelian\Listeners;

use App\Modules\PembelianSparepart\Events\PembelianSparepartDirealisasi;
use App\Modules\PermintaanPembelian\PermintaanPembelianService;

class PermintaanPembelianDariSparepartListener
{
    public function __construct(private readonly PermintaanPembelianService $service) {}

    public function handle(PembelianSparepartDirealisasi $event): void
    {
        $this->service->selesaikanDariPembelianSparepart(
            $event->idPermintaanPembelian,
            $event->idPembelian,
            $event->idPerusahaan,
            $event->idPengguna,
        );
    }
}
