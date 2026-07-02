<?php

declare(strict_types=1);

namespace App\Modules\KaryawanExit;

use App\Modules\KaryawanExit\Contracts\KaryawanExitRepositoryInterface;
use App\Modules\Karyawan\KaryawanModel;

class KaryawanExitService
{
    public function __construct(private readonly KaryawanExitRepositoryInterface $repo) {}

    public function create(array $data): KaryawanExitModel
    {
        $exit = $this->repo->create($data);

        $karyawan = KaryawanModel::find($data['id_karyawan']);
        if ($karyawan !== null) {
            $karyawan->update(['aktif' => 0]);
        }

        return $exit;
    }
}
