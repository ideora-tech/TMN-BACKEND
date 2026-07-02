<?php

declare(strict_types=1);

namespace App\Modules\Menu\Contracts;

use App\Modules\Menu\MenuModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface MenuRepositoryInterface
{
    public function allAktif(?string $kodeModul = null): array;
    public function tree(): array;
    public function paginate(int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?MenuModel;
    public function create(array $data): MenuModel;
    public function update(MenuModel $model, array $data): MenuModel;
    public function delete(MenuModel $model): void;
}
