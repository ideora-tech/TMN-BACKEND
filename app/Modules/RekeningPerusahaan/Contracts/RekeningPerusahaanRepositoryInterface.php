<?php

declare(strict_types=1);

namespace App\Modules\RekeningPerusahaan\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface RekeningPerusahaanRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search = null): LengthAwarePaginator;

    public function findById(string $id): ?object;

    public function kunciPerusahaan(string $idPerusahaan): void;

    public function jumlah(string $idPerusahaan): int;

    public function adaUtama(string $idPerusahaan): bool;

    public function kembar(string $idPerusahaan, string $namaBank, string $nomorRekening, ?string $kecualiId = null): bool;

    public function create(array $data): object;

    public function update(object $record, array $data): object;

    public function delete(object $record): void;

    public function lepasUtama(string $idPerusahaan): void;

    public function salinUtamaKePerusahaan(string $idPerusahaan): void;
}
