<?php

declare(strict_types=1);

namespace App\Modules\IzinPeran;

use App\Modules\IzinPeran\Contracts\IzinPeranRepositoryInterface;

class IzinPeranRepository implements IzinPeranRepositoryInterface
{
    /**
     * Izin EFEKTIF sebuah role: baseline global (id_perusahaan NULL) digabung
     * baris per-perusahaan — per-perusahaan menang, selaras CheckIzinPeran.
     * Tanpa merge ini, matriks di UI tidak menampilkan baseline global dan
     * admin bisa tanpa sadar me-revoke blanket saat menyimpan.
     */
    public function findByPeran(string $idPerusahaan, string $kodePeran): array
    {
        return IzinPeranModel::where('kode_peran', $kodePeran)
            ->where(function ($q) use ($idPerusahaan) {
                $q->where('id_perusahaan', $idPerusahaan)->orWhereNull('id_perusahaan');
            })
            ->get()
            ->sortBy(fn ($r) => $r->id_perusahaan === null ? 0 : 1)
            ->keyBy(fn ($r) => $r->id_menu . '|' . $r->aksi)
            ->values()
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
