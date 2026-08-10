<?php
declare(strict_types=1);

namespace App\Modules\PembelianSparepart\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface PembelianSparepartRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, array $filter = []): LengthAwarePaginator;
    public function findById(string $id): ?object;
    public function listItems(string $idPembelian): array;
    public function listBukti(string $idPembelian): array;
    public function nomorBerikutnya(string $idPerusahaan): string;
    public function createWithItems(array $header, array $items): object;
    public function updateWithItems(object $record, array $header, array $items): object;
    public function updateHeader(object $record, array $data): object;
    public function softDelete(object $record): void;
    public function sparepartMilik(string $idPerusahaan, array $ids): array;
    public function supplierMilik(string $idPerusahaan, string $idSupplier): bool;
    public function perawatanMilik(string $idPerusahaan, string $idPerawatan): bool;
    public function insertBukti(array $data): void;
    public function findBukti(string $idPembelian, string $idBukti): ?object;
    public function softDeleteBukti(string $idBukti): void;
    public function gantiHargaAktualItems(string $idPembelian, array $hargaPerItem): void;
    public function tambahStokDanMutasi(object $header, array $items): void;
    public function laporan(string $idPerusahaan, ?string $dari, ?string $sampai): array;
    public function getPerusahaan(string $idPerusahaan): ?object;
}
