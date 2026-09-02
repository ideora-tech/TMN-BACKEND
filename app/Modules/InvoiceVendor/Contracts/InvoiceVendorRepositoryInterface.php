<?php

declare(strict_types=1);

namespace App\Modules\InvoiceVendor\Contracts;

use App\Modules\InvoiceVendor\InvoiceVendorModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface InvoiceVendorRepositoryInterface
{
    public function paginateByPerusahaan(
        string $idPerusahaan,
        int $page,
        int $limit,
        ?string $search = null,
        ?string $status = null,
        ?string $statusPembayaran = null,
        ?string $idVendor = null
    ): LengthAwarePaginator;

    public function findByIdUntukPerusahaan(string $id, string $idPerusahaan): ?InvoiceVendorModel;

    public function findForUpdate(string $id): ?InvoiceVendorModel;

    public function nomorSudahDipakai(string $nomor, string $idPerusahaan, ?string $kecualiId = null): bool;

    public function vendorMilikPerusahaan(string $idVendor, string $idPerusahaan): bool;

    public function findKontrakMilikPerusahaan(string $idKontrak, string $idPerusahaan): ?object;

    public function vendorInfo(string $idVendor): ?object;

    public function getPerusahaan(string $idPerusahaan): ?object;

    public function pengajuanPembayaranUntukInvoice(string $idInvoiceVendor): array;

    public function totalDibayar(string $idInvoice): float;

    public function daftarPembayaran(string $idInvoice): array;

    public function outstandingUntukMonitoring(string $idPerusahaan): array;

    /** @return array<int, array{id_trip: string, tanggal: string, nopol: ?string, driver_nama: ?string, id_proyek: string, kode_proyek: ?string, nama_proyek: string, rute: ?string}> */
    public function tripSiapTagih(string $idPerusahaan, string $idKontrakVendor, ?string $dari, ?string $sampai, ?string $idProyek = null, bool $lock = false): array;

    public function insertInvoiceVendorTrip(string $idInvoiceVendor, string $idTrip): void;

    public function tripTerkaitUntukInvoice(string $idInvoiceVendor): array;

    public function create(array $data): InvoiceVendorModel;

    public function update(InvoiceVendorModel $model, array $data): InvoiceVendorModel;

    public function delete(InvoiceVendorModel $model): void;
}
