<?php

declare(strict_types=1);

namespace App\Modules\KontrakKaryawan\Contracts;

interface KontrakKaryawanRepositoryInterface
{
    public function findAllByKaryawan(string $idKaryawan): array;
    public function findById(string $id): ?object;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;
}
