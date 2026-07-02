<?php

declare(strict_types=1);

namespace App\Modules\KaryawanExit;

use App\Modules\KaryawanExit\Contracts\KaryawanExitRepositoryInterface;

class KaryawanExitRepository implements KaryawanExitRepositoryInterface
{
    public function create(array $data): KaryawanExitModel
    {
        return KaryawanExitModel::create($data);
    }
}
