<?php

declare(strict_types=1);

namespace App\Modules\LogError;

use App\Modules\LogError\Contracts\LogErrorRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class LogErrorRepository implements LogErrorRepositoryInterface
{
    public function paginate(int $page, int $limit): LengthAwarePaginator
    {
        return LogErrorModel::orderByDesc('dibuat_pada')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?LogErrorModel
    {
        return LogErrorModel::find($id);
    }

    public function create(array $data): LogErrorModel
    {
        return LogErrorModel::create($data);
    }
}
