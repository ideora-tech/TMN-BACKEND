<?php

declare(strict_types=1);

namespace App\Modules\IzinPeran\Contracts;

use App\Modules\IzinPeran\IzinPeranModel;

interface IzinPeranRepositoryInterface
{
    /** @return IzinPeranModel[] */
    public function findByPeran(string $idPerusahaan, string $kodePeran): array;

    public function findById(string $id): ?IzinPeranModel;

    public function upsert(
        string $idPerusahaan,
        string $kodePeran,
        string $idMenu,
        string $aksi,
        int $diizinkan
    ): IzinPeranModel;

    public function update(IzinPeranModel $model, array $data): IzinPeranModel;
}
