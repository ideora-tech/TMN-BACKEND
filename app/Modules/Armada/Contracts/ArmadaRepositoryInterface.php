<?php

declare(strict_types=1);

namespace App\Modules\Armada\Contracts;

use App\Modules\Armada\ArmadaModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface ArmadaRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $status): LengthAwarePaginator;
    public function findById(string $id): ?ArmadaModel;
    public function findByNopol(string $nopol): ?ArmadaModel;
    public function findByNopolMilikPerusahaan(string $nopol, string $idPerusahaan): ?ArmadaModel;
    public function findByNomorRangka(string $nomorRangka): ?ArmadaModel;
    public function create(array $data): ArmadaModel;
    public function update(ArmadaModel $model, array $data): ArmadaModel;
    public function delete(ArmadaModel $model): void;
    public function findServisJatuhTempo(string $idPerusahaan, int $days): array;
}
