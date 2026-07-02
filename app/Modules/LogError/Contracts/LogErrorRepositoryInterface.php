<?php

declare(strict_types=1);

namespace App\Modules\LogError\Contracts;

use App\Modules\LogError\LogErrorModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface LogErrorRepositoryInterface
{
    public function paginate(int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?LogErrorModel;
    public function create(array $data): LogErrorModel;
}
