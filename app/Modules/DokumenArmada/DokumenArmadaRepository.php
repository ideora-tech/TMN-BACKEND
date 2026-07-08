<?php

declare(strict_types=1);

namespace App\Modules\DokumenArmada;

use App\Modules\DokumenArmada\Contracts\DokumenArmadaRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class DokumenArmadaRepository implements DokumenArmadaRepositoryInterface
{
    public function paginateByArmada(string $idArmada, int $page, int $limit): LengthAwarePaginator
    {
        return DokumenArmadaModel::active()
            ->where('id_armada', $idArmada)
            ->orderBy('berlaku_sampai')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?DokumenArmadaModel
    {
        return DokumenArmadaModel::active()->find($id);
    }

    public function findExpiring(string $idPerusahaan, int $days): array
    {
        return DokumenArmadaModel::join('armada', 'armada.id_armada', '=', 'dokumen_armada.id_armada')
            ->where('armada.id_perusahaan', $idPerusahaan)
            ->whereNull('dokumen_armada.dihapus_pada')
            ->whereNotNull('berlaku_sampai')
            ->where('berlaku_sampai', '<=', now()->addDays($days))
            ->select('dokumen_armada.*')
            ->get()
            ->all();
    }

    public function create(array $data): DokumenArmadaModel
    {
        return DokumenArmadaModel::create($data);
    }

    public function update(DokumenArmadaModel $model, array $data): DokumenArmadaModel
    {
        $model->update($data);
        return $model->fresh() ?? $model;
    }

    public function delete(DokumenArmadaModel $model): void
    {
        $model->softDelete();
    }
}
