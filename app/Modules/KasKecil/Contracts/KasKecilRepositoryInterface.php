<?php

declare(strict_types=1);

namespace App\Modules\KasKecil\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface KasKecilRepositoryInterface
{
    public function kunciPerusahaan(string $idPerusahaan): void;

    public function rekeningPerusahaan(string $idPerusahaan): ?object;

    public function saldo(string $idPerusahaan): float;

    public function saldoSebelum(string $idPerusahaan, string $tanggal, bool $kunci = false): float;

    /** @return list<object> */
    public function mutasiSejak(string $idPerusahaan, string $dari): array;

    /** @return list<object> */
    public function transaksiRentang(string $idPerusahaan, string $dari, string $sampai): array;

    public function totalRentang(string $idPerusahaan, string $dari, string $sampai): object;

    public function adaTransaksi(string $idPerusahaan): bool;

    public function findSaldoAwal(string $idPerusahaan): ?object;

    public function findTransaksi(string $id): ?object;

    public function nomorTransaksiBerikutnya(string $idPerusahaan): string;

    public function createTransaksi(array $data): object;

    public function updateTransaksi(object $record, array $data): object;

    public function deleteTransaksi(object $record): void;

    public function penerimaanPengisianAda(string $idPengisian): bool;

    public function paginatePengisian(
        string $idPerusahaan,
        int $page,
        int $limit,
        ?string $search = null,
        ?string $status = null,
    ): LengthAwarePaginator;

    public function findPengisian(string $id): ?object;

    public function kunciPengisian(string $id): void;

    public function nomorPengisianBerikutnya(string $idPerusahaan): string;

    public function createPengisian(array $data): object;

    public function updatePengisian(object $record, array $data): object;

    public function deletePengisian(object $record): void;

    public function pengisianDiproses(string $idPerusahaan): object;
}
