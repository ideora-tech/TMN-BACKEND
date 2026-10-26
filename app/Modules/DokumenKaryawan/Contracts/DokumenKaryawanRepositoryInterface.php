<?php

declare(strict_types=1);

namespace App\Modules\DokumenKaryawan\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface DokumenKaryawanRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idKaryawan = null, ?string $jenisDokumen = null, ?string $search = null): LengthAwarePaginator;
    public function findAllByKaryawan(string $idKaryawan): array;
    public function findAllBySupir(string $idSupir, ?string $idKaryawan): array;
    public function maxBerlakuSimTanpaKaryawan(string $idSupir): ?string;
    public function sinkronKadaluarsaSimSatuSupir(string $idSupir, string $tanggal): void;
    public function pindahkanDokumenSupirKeKaryawan(string $idSupir, string $idKaryawan): void;
    public function selaraskanKtpKaryawan(string $idKaryawan): void;
    public function nomorKtpTerbaru(string $idSupir, ?string $idKaryawan): ?string;
    public function findById(string $id): ?object;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;
    public function maxBerlakuSimAktif(string $idKaryawan): ?string;
    public function sinkronKadaluarsaSimSupir(string $idKaryawan, string $tanggal): void;
}
