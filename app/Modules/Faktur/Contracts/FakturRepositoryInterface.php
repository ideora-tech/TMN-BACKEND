<?php

declare(strict_types=1);

namespace App\Modules\Faktur\Contracts;

use App\Modules\Faktur\FakturModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface FakturRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search = null, ?string $status = null): LengthAwarePaginator;
    public function paginateByKlien(string $idKlien, string $idPerusahaan, int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?FakturModel;
    public function namaKlien(string $idKlien, string $idPerusahaan): ?string;
    public function namaProyek(string $idProyek, string $idPerusahaan): ?string;
    public function getPerusahaan(string $idPerusahaan): ?object;
    public function findByNomor(string $nomor, string $idPerusahaan): ?FakturModel;
    public function nomorBerikutnya(string $idPerusahaan): string;
    public function create(array $data): FakturModel;
    public function update(FakturModel $model, array $data): FakturModel;
    public function delete(FakturModel $model): void;
    public function namaPengguna(array $ids): array;
    public function insertStatusLog(string $idFaktur, string $status, ?string $keterangan = null): void;
    public function listStatusLog(string $idFaktur): array;
}
