<?php

declare(strict_types=1);

namespace App\Modules\ProyekRute\Contracts;

use App\Modules\ProyekRute\ProyekRuteModel;
use Illuminate\Support\Collection;

interface ProyekRuteRepositoryInterface
{
    /** Baris aktif milik satu proyek + kolom nama rute/jenis + komponen tarif (untuk Resource). */
    public function listByProyek(string $idProyek): Collection;

    /** True jika id_rute sudah terdaftar sebagai rute proyek tsb (baris aktif). */
    public function ruteTerdaftarUntukProyek(string $idProyek, string $idRute): bool;

    public function findById(string $id): ?ProyekRuteModel;

    /** Sama seperti findById tapi memuat kolom join (untuk Resource setelah create/update). */
    public function findDetailById(string $id): ?ProyekRuteModel;

    public function ruteMilik(string $id, string $idPerusahaan): ?object;

    public function jenisKendaraanMilik(string $id, string $idPerusahaan): ?object;

    public function create(array $data): ProyekRuteModel;

    public function update(ProyekRuteModel $model, array $data): ProyekRuteModel;

    public function delete(ProyekRuteModel $model): void;
}
