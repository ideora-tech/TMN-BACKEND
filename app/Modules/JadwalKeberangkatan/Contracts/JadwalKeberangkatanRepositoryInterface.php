<?php

declare(strict_types=1);

namespace App\Modules\JadwalKeberangkatan\Contracts;

use App\Modules\JadwalKeberangkatan\JadwalKeberangkatanModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface JadwalKeberangkatanRepositoryInterface
{
    public function paginateByPenugasan(string $idPenugasan, int $page, int $limit): LengthAwarePaginator;
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?JadwalKeberangkatanModel;
    public function findBySupir(string $idSupir, int $page, int $limit): LengthAwarePaginator;
    public function findKandidatBentrok(?string $idArmada, ?string $idSupir, ?string $idArmadaVendor, ?string $idSupirVendor, ?string $excludeJadwalId): array;
    public function create(array $data): JadwalKeberangkatanModel;
    public function update(JadwalKeberangkatanModel $model, array $data): JadwalKeberangkatanModel;
    public function delete(JadwalKeberangkatanModel $model): void;
}
