<?php

declare(strict_types=1);

namespace App\Modules\TipePermintaan\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface TipePermintaanRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search = null, ?bool $aktif = null): LengthAwarePaginator;
    public function listAktifByPerusahaan(string $idPerusahaan): array;
    public function findById(string $id): ?object;
    public function findByNama(string $idPerusahaan, string $nama, ?string $kecualiId = null): ?object;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;
    public function jumlahJudulPemakai(string $idTipePermintaan): int;
    public function sinkronJenisFormJudul(string $idTipePermintaan, string $jenisForm): void;
}
