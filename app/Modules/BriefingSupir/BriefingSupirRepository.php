<?php

declare(strict_types=1);

namespace App\Modules\BriefingSupir;

use App\Modules\BriefingSupir\Contracts\BriefingSupirRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class BriefingSupirRepository implements BriefingSupirRepositoryInterface
{
    public function paginateByPenugasan(string $idPenugasan, int $page, int $limit): LengthAwarePaginator
    {
        return BriefingSupirModel::active()
            ->where('id_penugasan', $idPenugasan)
            ->orderBy('waktu_briefing', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?BriefingSupirModel
    {
        return BriefingSupirModel::active()->find($id);
    }

    public function create(array $data): BriefingSupirModel
    {
        return BriefingSupirModel::create($data);
    }

    public function update(BriefingSupirModel $model, array $data): BriefingSupirModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(BriefingSupirModel $model): void
    {
        $model->softDelete();
    }
}
