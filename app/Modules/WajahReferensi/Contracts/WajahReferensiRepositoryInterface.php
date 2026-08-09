<?php

declare(strict_types=1);

namespace App\Modules\WajahReferensi\Contracts;

interface WajahReferensiRepositoryInterface
{
    public function findByPengguna(string $idPengguna): ?object;
    public function create(array $data): object;
    public function delete(string $idPengguna): void;
}
