<?php

declare(strict_types=1);

namespace App\Modules\Absensi\Contracts;

interface AbsensiRepositoryInterface
{
    public function findByTanggal(string $idPerusahaan, string $tanggal): array;
    public function upsert(string $idPerusahaan, string $idKaryawan, string $tanggal, array $data): void;
    public function hapusByKaryawanTanggal(string $idKaryawan, string $tanggal): void;
    public function rekapBulanan(string $idPerusahaan, string $awal, string $akhir): array;
    public function cutiDisetujuiDalamRentang(string $idPerusahaan, string $awal, string $akhir): array;
}
