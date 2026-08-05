<?php

declare(strict_types=1);

namespace App\Modules\Cuti\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CutiRepositoryInterface
{
    public function findAllJenis(string $idPerusahaan): array;
    public function paginateJenis(string $idPerusahaan, int $page, int $limit, ?string $search = null): LengthAwarePaginator;
    public function findJenisById(string $id): ?object;
    public function createJenis(array $data): object;
    public function updateJenis(object $record, array $data): object;
    public function deleteJenis(object $record): void;

    public function paginatePengajuan(string $idPerusahaan, int $page, int $limit, ?string $status = null, ?string $search = null, ?string $tanggalDari = null, ?string $tanggalSampai = null): LengthAwarePaginator;
    public function pengajuanByKaryawan(string $idKaryawan): array;
    public function findPengajuanById(string $id): ?object;
    public function createPengajuan(array $data): object;
    public function updatePengajuan(object $record, array $data): object;
    /** @return int jumlah baris terdampak */
    public function updatePengajuanJikaStatus(string $idPengajuan, string $status, array $data): int;
    public function adaPengajuanTumpangTindih(?string $idKaryawan, ?string $idSupir, string $tanggalMulai, string $tanggalSelesai, ?string $excludeId = null): bool;

    public function sumLedger(?string $idKaryawan, ?string $idSupir, int $tahun): object;
    public function riwayatLedger(?string $idKaryawan, ?string $idSupir, int $tahun): array;
    public function insertLedger(array $data): object;
    public function softDeleteDebitByReferensi(string $idPengajuan): void;
    public function sumLedgerSemua(string $idPerusahaan, int $tahun): array;

    public function supirSedangCuti(string $idSupir, string $tanggal): bool;
    public function orangCutiPadaTanggal(string $idPerusahaan, string $tanggal): array;
}
