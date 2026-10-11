<?php

declare(strict_types=1);

namespace App\Modules\RekapPajak\Contracts;

use Illuminate\Support\Collection;

interface RekapPajakRepositoryInterface
{
    public function fakturKlien(string $idPerusahaan, string $dari, string $sampai): Collection;

    public function dppFaktur(string $idPerusahaan, string $dari, string $sampai): Collection;

    public function pajakFaktur(string $idPerusahaan, string $dari, string $sampai): Collection;

    public function ppnInvoiceVendor(string $idPerusahaan, string $dari, string $sampai): Collection;

    public function ppnPermintaanPembelian(string $idPerusahaan, string $dari, string $sampai): Collection;

    public function ppnPembelianSparepart(string $idPerusahaan, string $dari, string $sampai): Collection;

    public function pphInvoiceVendor(string $idPerusahaan, string $dari, string $sampai): Collection;

    public function pph21(string $idPerusahaan, string $dari, string $sampai): Collection;

    public function potonganKlien(string $idPerusahaan, string $dari, string $sampai): Collection;
}
