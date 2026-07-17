<?php

declare(strict_types=1);

namespace App\Modules\KaryawanExit\Contracts;

interface KaryawanExitRepositoryInterface
{
    public function create(array $data): object;
}
