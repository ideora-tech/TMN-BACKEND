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
    public function listMenungguApprovalSaya(string $idPerusahaan, string $idPengguna): Collection;
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

    public function totalPengajuanBerjalanUntukInvoiceVendor(string $idInvoiceVendor): float;
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
    public function getPengaturan(string $idPerusahaan, string $kunci): ?string;
    public function setPengaturan(string $idPerusahaan, string $kunci, string $nilai): void;
    public function saldoKasSebelum(string $idPerusahaan, string $sebelumTanggal): float;
    public function namaPerusahaan(string $idPerusahaan): ?string;
    public function listApproval(string $idPengajuan): array;
    public function listApprovalBanyak(array $idPengajuanList): array;
    public function findPengajuanForUpdate(string $id): ?PengajuanPengeluaranModel;
    public function unlinkJadwalPengajuan(string $idPengajuan): void;
    public function findPengajuanPeriodeUntukTrip(string $idTrip): ?PengajuanPengeluaranModel;

    /** Nama supir & proyek untuk menyusun penerima/keterangan pengajuan uang jalan penugasan. */
    public function dataUntukPengajuanPenugasan(string $idSupir, string $idProyek): object;

    /** Sisa baris `penugasan` aktif (non-dihapus) yang masih ber-`id_pengajuan` ini, untuk sinkron nominal/periode. */
    public function hitungPenugasanTerkaitPengajuan(string $idPengajuan): object;
}
