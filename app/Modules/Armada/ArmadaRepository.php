<?php

declare(strict_types=1);

namespace App\Modules\Armada;

use App\Modules\Armada\Contracts\ArmadaRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class ArmadaRepository implements ArmadaRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $status): LengthAwarePaginator
    {
        $query = ArmadaModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('nopol');

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?ArmadaModel
    {
        return ArmadaModel::active()->find($id);
    }

    public function findByNopol(string $nopol): ?ArmadaModel
    {
        return ArmadaModel::active()->where('nopol', $nopol)->first();
    }

    /**
     * Cari armada by nopol, di-scope ke perusahaan (dipakai oleh import supir
     * untuk matching id_armada_default). Perbandingan case-insensitive & trim
     * agar nopol dari file Excel tetap match walau beda kapitalisasi/spasi.
     */
    public function findByNopolMilikPerusahaan(string $nopol, string $idPerusahaan): ?ArmadaModel
    {
        return ArmadaModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->whereRaw('UPPER(TRIM(nopol)) = ?', [mb_strtoupper(trim($nopol))])
            ->first();
    }

    public function findByNomorRangka(string $nomorRangka): ?ArmadaModel
    {
        return ArmadaModel::active()->where('nomor_rangka', $nomorRangka)->first();
    }

    public function create(array $data): ArmadaModel
    {
        return ArmadaModel::create($data);
    }

    public function update(ArmadaModel $model, array $data): ArmadaModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(ArmadaModel $model): void
    {
        $model->softDelete();
    }
}
