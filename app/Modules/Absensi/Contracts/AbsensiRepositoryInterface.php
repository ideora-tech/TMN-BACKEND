<?php

declare(strict_types=1);

namespace App\Modules\Absensi\Contracts;

interface AbsensiRepositoryInterface
{
    public function findByTanggal(string $idPerusahaan, string $tanggal): array;
    /** Satu baris absensi untuk karyawan pada tanggal tertentu, atau null. */
    public function findByKaryawanTanggal(string $idKaryawan, string $tanggal): ?object;
    public function upsert(string $idPerusahaan, string $idKaryawan, string $tanggal, array $data): void;
    public function hapusByKaryawanTanggal(string $idKaryawan, string $tanggal): void;
    public function rekapBulanan(string $idPerusahaan, string $awal, string $akhir): array;
    public function jamPulangDalamRentang(string $idPerusahaan, string $awal, string $akhir): array;
    public function cutiDisetujuiDalamRentang(string $idPerusahaan, string $awal, string $akhir): array;
    public function getPengaturan(string $idPerusahaan): ?object;
    public function upsertPengaturan(string $idPerusahaan, array $data): object;
}
