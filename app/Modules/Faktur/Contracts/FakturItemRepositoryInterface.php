<?php

declare(strict_types=1);

namespace App\Modules\Faktur\Contracts;

use App\Modules\Faktur\FakturItemModel;

interface FakturItemRepositoryInterface
{
    public function create(array $data): FakturItemModel;
    public function deleteByFaktur(string $idFaktur): void;
}
