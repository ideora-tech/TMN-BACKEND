<?php

declare(strict_types=1);

namespace App\Modules\Faktur;

use App\Modules\Faktur\Contracts\FakturItemRepositoryInterface;

class FakturItemRepository implements FakturItemRepositoryInterface
{
    public function create(array $data): FakturItemModel
    {
        return FakturItemModel::create($data);
    }

    public function deleteByFaktur(string $idFaktur): void
    {
        FakturItemModel::active()
            ->where('id_faktur', $idFaktur)
            ->each(fn(FakturItemModel $item) => $item->softDelete());
    }
}
