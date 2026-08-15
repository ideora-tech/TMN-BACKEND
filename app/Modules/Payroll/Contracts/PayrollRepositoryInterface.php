<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PayrollRepositoryInterface
{
    public function getPengaturan(string $idPerusahaan): ?object;
    public function upsertPengaturan(string $idPerusahaan, array $data): object;

    public function paginatePeriode(string $idPerusahaan, int $page, int $limit, ?string $search = null, ?string $status = null): LengthAwarePaginator;
    public function findPeriodeById(string $id): ?object;
    public function adaPeriodeTumpangTindih(string $idPerusahaan, string $mulai, string $selesai, ?string $excludeId = null): bool;
    public function createPeriode(array $data): object;
    public function updatePeriode(object $record, array $data): object;
    public function deletePeriode(object $record): void;

    public function slipByPeriode(string $idPeriode): array;
    public function findSlipById(string $id): ?object;
    public function findSlipByPeriodeKaryawan(string $idPeriode, string $idKaryawan): ?object;

    /** @return array<int, object{id_karyawan: string, nama_karyawan: string}> */
    public function semuaKaryawan(string $idPerusahaan): array;

    public function karyawanUntukTemplate(string $idPerusahaan): array;
    public function getPerusahaan(string $idPerusahaan): ?object;
    public function createSlip(array $data): object;
    public function updateSlip(object $record, array $data): object;
    public function hapusSlipByPeriode(string $idPeriode): void;
    public function ringkasanPeriode(string $idPeriode): object;

    /** @return array<string, string> map id_karyawan => tanggal_efektif exit terakhir */
    public function tanggalExitTerakhir(string $idPerusahaan): array;
}
