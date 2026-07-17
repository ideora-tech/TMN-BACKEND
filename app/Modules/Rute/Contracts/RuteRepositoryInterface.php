<?php
namespace App\Modules\Rute\Contracts;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface RuteRepositoryInterface {
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search): LengthAwarePaginator;
    public function findById(string $id): ?object;
    public function findByKode(string $idPerusahaan, string $kode, ?string $excludeId = null): ?object;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;
}