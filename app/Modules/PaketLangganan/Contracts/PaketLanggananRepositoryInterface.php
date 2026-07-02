<?php

declare(strict_types=1);

namespace App\Modules\PaketLangganan\Contracts;

use App\Modules\PaketLangganan\PaketLanggananModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface PaketLanggananRepositoryInterface
{
    public function paginate(int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?PaketLanggananModel;
    public function findByKode(string $kode): ?PaketLanggananModel;
    public function create(array $data): PaketLanggananModel;
    public function update(PaketLanggananModel $model, array $data): PaketLanggananModel;
    public function delete(PaketLanggananModel $model): void;
}
