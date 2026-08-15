<?php

declare(strict_types=1);

namespace App\Modules\ArusKas\Contracts;

use App\Modules\ArusKas\PemasukanModel;
use App\Modules\ArusKas\PengajuanPengeluaranModel;
use Illuminate\Support\Collection;

interface ArusKasRepositoryInterface
{
    public function listPengajuanByPerusahaan(string $idPerusahaan, ?string $status = null): Collection;
    public function findPengajuanById(string $id): ?PengajuanPengeluaranModel;
    public function createPengajuan(array $data): PengajuanPengeluaranModel;
    public function updatePengajuan(PengajuanPengeluaranModel $model, array $data): PengajuanPengeluaranModel;
    public function deletePengajuan(PengajuanPengeluaranModel $model): void;
    public function nomorPengajuanBerikutnya(string $idPerusahaan): string;
    public function rekap(string $idPerusahaan, string $dari, string $sampai): Collection;
    public function findPengajuanByTrip(string $idTrip): ?PengajuanPengeluaranModel;
    public function namaPengguna(array $ids): array;
    public function dataTripUntukPengajuan(string $idTrip): ?object;
    public function findPengajuanByPerawatan(string $idPerawatan): ?PengajuanPengeluaranModel;
    public function dataPerawatanUntukPengajuan(string $idPerawatan): ?object;
    public function findPengajuanByPembelian(string $idPembelian): ?PengajuanPengeluaranModel;
    public function dataPembelianUntukPengajuan(string $idPembelian): ?object;
    public function statusPembelian(string $idPembelian): ?string;
    public function sinkronPembelianSetujui(string $idPembelian): void;
    public function sinkronPembelianTolak(string $idPembelian, string $alasan): void;
    public function sinkronPembelianLunas(string $idPembelian, string $tanggalPembayaran): void;
    public function sinkronPembelianUangMuka(string $idPembelian, string $tanggalTransfer): void;
    public function findPengajuanByPeriode(string $idPeriode): ?PengajuanPengeluaranModel;
    public function dataPeriodeUntukPengajuan(string $idPeriode): ?object;
    public function findPemasukanById(string $id): ?PemasukanModel;
    public function createPemasukan(array $data): PemasukanModel;
    public function updatePemasukan(PemasukanModel $model, array $data): PemasukanModel;
    public function deletePemasukan(PemasukanModel $model): void;
    public function nomorPemasukanBerikutnya(string $idPerusahaan): string;
    public function listPemasukanGabungan(string $idPerusahaan, string $dari, string $sampai): Collection;
    public function listApprover(string $idPerusahaan): array;
    public function insertApprover(array $data): void;
    public function softDeleteApprover(string $id, string $idPerusahaan): bool;
    public function adaApproverAktif(string $idPerusahaan, string $tipe, ?string $idRef): bool;
    public function getPengaturan(string $idPerusahaan, string $kunci): ?string;
    public function setPengaturan(string $idPerusahaan, string $kunci, string $nilai): void;
    public function resolusiApprover(string $idPerusahaan): array;
    public function jabatanMilik(string $idJabatan, string $idPerusahaan): bool;
    public function penggunaMilik(string $idPengguna, string $idPerusahaan): bool;
    public function saldoKasSebelum(string $idPerusahaan, string $sebelumTanggal): float;
    public function namaPerusahaan(string $idPerusahaan): ?string;
    public function insertApprovalRows(string $idPengajuan, array $idPenggunaList): void;
    public function voidApprovalRows(string $idPengajuan): void;
    public function listApproval(string $idPengajuan): array;
    public function listApprovalBanyak(array $idPengajuanList): array;
    public function findApprovalMenunggu(string $idPengajuan, string $idPengguna): ?object;
    public function findPengajuanForUpdate(string $id): ?PengajuanPengeluaranModel;
    public function updateApprovalRowJikaMenunggu(string $idApproval, array $data): int;
    public function hitungApprovalMenunggu(string $idPengajuan): int;
}
