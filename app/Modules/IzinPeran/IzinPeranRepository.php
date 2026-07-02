<?php

declare(strict_types=1);

namespace App\Modules\IzinPeran;

use App\Modules\IzinPeran\Contracts\IzinPeranRepositoryInterface;

class IzinPeranRepository implements IzinPeranRepositoryInterface
{
    public function findByPeran(string $idPerusahaan, string $kodePeran): array
    {
        return IzinPeranModel::where('id_perusahaan', $idPerusahaan)
            ->where('kode_peran', $kodePeran)
            ->get()
            ->all();
    }

    public function findById(string $id): ?IzinPeranModel
    {
        return IzinPeranModel::find($id);
    }

    public function upsert(
        string $idPerusahaan,
        string $kodePeran,
        string $idMenu,
        string $aksi,
        int $diizinkan
    ): IzinPeranModel {
        return IzinPeranModel::updateOrCreate(
            [
                'id_perusahaan' => $idPerusahaan,
                'kode_peran'    => $kodePeran,
                'id_menu'       => $idMenu,
                'aksi'          => $aksi,
            ],
            ['diizinkan' => $diizinkan]
        );
    }

    public function update(IzinPeranModel $model, array $data): IzinPeranModel
    {
        $model->update($data);
        return $model->fresh();
    }
}
