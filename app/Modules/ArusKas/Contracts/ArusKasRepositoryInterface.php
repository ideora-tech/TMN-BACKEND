<?php

declare(strict_types=1);

namespace App\Modules\ArusKas\Contracts;

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
}
