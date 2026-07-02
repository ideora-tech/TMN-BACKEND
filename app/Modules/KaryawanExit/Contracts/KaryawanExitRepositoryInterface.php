<?php

declare(strict_types=1);

namespace App\Modules\KaryawanExit\Contracts;

use App\Modules\KaryawanExit\KaryawanExitModel;

interface KaryawanExitRepositoryInterface
{
    public function create(array $data): KaryawanExitModel;
}
