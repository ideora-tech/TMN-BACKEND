<?php

declare(strict_types=1);

namespace App\Modules\Menu\Contracts;

use App\Modules\Menu\MenuModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface MenuRepositoryInterface
{
    public function allAktif(): array;
    public function allWithPerans(): array;
    public function semuaKodePeran(): array;
    public function sinkronAksesPeran(string $kodePeran, array $idMenuTampil, array $semuaKodePeran): void;
    public function tree(?string $kodePeran = null): array;
    public function paginate(int $page, int $limit, ?string $search = null): LengthAwarePaginator;
    public function findById(string $id): ?MenuModel;
    public function create(array $data): MenuModel;
    public function update(MenuModel $model, array $data): MenuModel;
    public function delete(MenuModel $model): void;
}
