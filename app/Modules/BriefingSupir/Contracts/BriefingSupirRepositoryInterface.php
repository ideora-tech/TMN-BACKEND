<?php

declare(strict_types=1);

namespace App\Modules\BriefingSupir\Contracts;

use App\Modules\BriefingSupir\BriefingSupirModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface BriefingSupirRepositoryInterface
{
    public function paginateByPenugasan(string $idPenugasan, int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?BriefingSupirModel;
    public function create(array $data): BriefingSupirModel;
    public function update(BriefingSupirModel $model, array $data): BriefingSupirModel;
    public function delete(BriefingSupirModel $model): void;
}
