<?php

declare(strict_types=1);

namespace App\Modules\PermintaanPembelian\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface PermintaanPembelianRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, array $filter): LengthAwarePaginator;
    /** @return array<string,int> */
    public function ringkasanStatus(string $idPerusahaan): array;
    public function listMenungguDiproses(string $idPerusahaan, int $limit): array;
    public function findById(string $id): ?object;
    public function findByIdForUpdate(string $id): ?object;
    public function createWithItems(array $header, array $items): object;
    public function updateWithItems(object $record, array $header, array $items): void;
    public function updateHeader(object $record, array $data): void;
    /** @return object[] */
    public function listItems(string $idPermintaan): array;
    public function updateItem(string $idItem, array $data): void;
    public function softDelete(object $record): void;
    public function insertBukti(array $data): void;
    /** @return object[] */
    public function listBukti(string $idPermintaan): array;
    public function findBukti(string $idPermintaan, string $idBukti): ?object;
    public function softDeleteBukti(string $idBukti): void;
    public function supplierMilik(string $idPerusahaan, string $idSupplier): ?object;
    public function departemenMilik(string $idPerusahaan, string $idDepartemen): bool;
    public function perawatanMilik(string $idPerusahaan, string $idPerawatan): bool;
    /** @return array<string,object> */
    public function sparepartMilik(string $idPerusahaan, array $ids): array;
    public function pembelianSparepartDariPermintaan(string $idPermintaan): ?object;
    /** @return array<int,array{url_file:string,nama_asli:string}> */
    public function listBuktiMentah(string $idPermintaan): array;
    /** @return array<string,object> */
    public function jenisKendaraanMilik(string $idPerusahaan, array $ids): array;
    public function insertTermin(array $data): string;
    /** @return object[] */
    public function listTermin(string $idPermintaan): array;
    public function setPengajuanTermin(string $idTermin, string $idPengajuan): void;
    public function adaTerminMenunggu(string $idPermintaan): bool;
    public function findItemById(string $idItem): ?object;
    public function tambahQtyDiterima(string $idItem): bool;
    public function kurangiQtyDiterima(string $idItem): void;
    public function semuaItemLengkap(string $idPermintaan): bool;
    public function tanggalTransferTerminTerakhir(string $idPermintaan): ?string;
    public function getPerusahaan(string $idPerusahaan): ?object;
    /** @return object[] */
    public function laporanPermintaan(string $idPerusahaan, ?string $dari, ?string $sampai, ?string $tipe): array;
    /** @return object[] */
    public function itemsUntukLaporan(array $idPermintaanList): array;
    /** @return object[] */
    public function terminDitransferUntukPermintaan(array $idPermintaanList): array;
    /** @return object[] */
    public function menungguDiprosesLaporan(string $idPerusahaan, ?string $tipe): array;
}
