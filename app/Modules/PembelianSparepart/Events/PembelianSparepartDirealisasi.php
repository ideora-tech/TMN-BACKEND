<?php

declare(strict_types=1);

namespace App\Modules\PembelianSparepart\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class PembelianSparepartDirealisasi
{
    use Dispatchable;

    public function __construct(
        public readonly string $idPerusahaan,
        public readonly string $idPembelian,
        public readonly string $idPermintaanPembelian,
        public readonly string $idPengguna,
    ) {}
}
