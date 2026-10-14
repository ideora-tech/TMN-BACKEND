<?php

declare(strict_types=1);

namespace App\Modules\FeeSupir\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface FeeSupirRepositoryInterface
{
    public function infoTrip(string $idTrip): ?object;
    public function idTripSelesaiPenugasan(string $idPenugasan): array;
    public function jumlahPenugasanSupirSebelum(string $idSupir, string $idProyek, string $tanggal, ?string $idPenugasan): int;
    public function proyekMilikPerusahaan(string $idProyek, string $idPerusahaan): ?object;
    public function findByTrip(string $idTrip): ?object;
    public function findTerhapusByTrip(string $idTrip): ?object;
    public function create(array $data): void;
    public function perbarui(string $idFee, array $data): void;
    public function pulihkan(string $idFee, array $data): void;
    public function hapusUntukTrip(string $idTrip): void;
    public function daftarUntukSupir(string $idSupir, string $dari, string $sampai): array;
    public function ringkasan(string $idPerusahaan, string $dari, string $sampai, ?string $search, ?string $idSupir): object;
    public function paginateRekap(string $idPerusahaan, string $dari, string $sampai, ?string $search, ?string $idSupir, int $page, int $limit): LengthAwarePaginator;
    public function semuaRekap(string $idPerusahaan, string $dari, string $sampai, ?string $search, ?string $idSupir): array;
    public function paginateRincian(string $idPerusahaan, string $dari, string $sampai, ?string $search, ?string $idSupir, int $page, int $limit): LengthAwarePaginator;
    public function semuaRincian(string $idPerusahaan, string $dari, string $sampai, ?string $search, ?string $idSupir, int $batas): array;
}
