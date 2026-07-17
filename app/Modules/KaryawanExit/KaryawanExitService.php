<?php

declare(strict_types=1);

namespace App\Modules\KaryawanExit;

use App\Modules\KaryawanExit\Contracts\KaryawanExitRepositoryInterface;
use App\Modules\Karyawan\Contracts\KaryawanRepositoryInterface;

class KaryawanExitService
{
    public function __construct(
        private readonly KaryawanExitRepositoryInterface $repo,
        private readonly KaryawanRepositoryInterface $karyawanRepo,
    ) {}

    public function create(array $data): object
    {
        $exit = $this->repo->create($data);

        $karyawan = $this->karyawanRepo->findById($data['id_karyawan']);
        if ($karyawan !== null) {
            $this->karyawanRepo->update($karyawan, ['aktif' => 0]);
        }

        return $exit;
    }
}
