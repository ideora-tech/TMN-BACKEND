<?php

declare(strict_types=1);

namespace App\Modules\ProyekRute\Contracts;

use App\Modules\ProyekRute\ProyekRuteModel;
use Illuminate\Support\Collection;

interface ProyekRuteRepositoryInterface
{
    /** Baris aktif milik satu proyek + kolom nama rute/jenis + komponen tarif (untuk Resource). */
    public function listByProyek(string $idProyek): Collection;

    public function findById(string $id): ?ProyekRuteModel;

    /** Sama seperti findById tapi memuat kolom join (untuk Resource setelah create/update). */
    public function findDetailById(string $id): ?ProyekRuteModel;

    public function ruteMilik(string $id, string $idPerusahaan): ?object;

    public function jenisKendaraanMilik(string $id, string $idPerusahaan): ?object;

    public function create(array $data): ProyekRuteModel;

    public function update(ProyekRuteModel $model, array $data): ProyekRuteModel;

    public function delete(ProyekRuteModel $model): void;
}
