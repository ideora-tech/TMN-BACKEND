<?php

declare(strict_types=1);

namespace App\Modules\PembayaranVendor\Contracts;

use App\Modules\PembayaranVendor\PembayaranVendorModel;

interface PembayaranVendorRepositoryInterface
{
    public function findInvoiceUntukPerusahaan(string $idInvoice, string $idPerusahaan): ?object;

    public function listByInvoice(string $idInvoice): array;

    public function findByIdUntukInvoice(string $id, string $idInvoice, string $idPerusahaan): ?PembayaranVendorModel;

    public function kunciInvoice(string $idInvoice): ?object;

    public function totalDibayar(string $idInvoice): float;

    public function create(array $data): PembayaranVendorModel;

    public function delete(PembayaranVendorModel $model): void;

    public function recalcStatusPembayaran(string $idInvoice): void;
}
