<?php

declare(strict_types=1);

namespace App\Modules\SupirProyek;

use App\Modules\SupirProyek\Contracts\SupirProyekRepositoryInterface;
use Illuminate\Support\Facades\DB;

class SupirProyekRepository implements SupirProyekRepositoryInterface
{
    private const COLUMNS = [
        'supir_proyek.id_supir_proyek', 'supir_proyek.id_proyek', 'supir_proyek.id_supir',
    ];

    private const JOINED = [
        'supir.nama', 'supir.no_sim', 'supir.telepon',
    ];

    public function listByProyek(string $idProyek, string $idPerusahaan): array
    {
        return SupirProyekModel::active()
            ->join('supir', 'supir.id_supir', '=', 'supir_proyek.id_supir')
            ->whereNull('supir.dihapus_pada')
            ->where('supir_proyek.id_perusahaan', $idPerusahaan)
            ->where('supir_proyek.id_proyek', $idProyek)
            ->orderBy('supir.nama')
            ->select(array_merge(self::COLUMNS, self::JOINED))
            ->get()
            ->all();
    }

    public function terdaftar(string $idProyek, string $idSupir): bool
    {
        return SupirProyekModel::active()
            ->where('id_proyek', $idProyek)
            ->where('id_supir', $idSupir)
            ->exists();
    }

    public function create(array $data): SupirProyekModel
    {
        return SupirProyekModel::create($data);
    }

    public function findByIdMilikPerusahaan(string $id, string $idPerusahaan): ?SupirProyekModel
    {
        return SupirProyekModel::active()
            ->where('id_supir_proyek', $id)
            ->where('id_perusahaan', $idPerusahaan)
            ->first();
    }

    public function delete(SupirProyekModel $m): void
    {
        $m->softDelete();
    }

    public function supirMilikPerusahaan(string $idSupir, string $idPerusahaan): bool
    {
        return DB::table('supir')
            ->where('id_supir', $idSupir)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->exists();
    }

    public function proyekMilikPerusahaan(string $idProyek, string $idPerusahaan): bool
    {
        return DB::table('proyek')
            ->where('id_proyek', $idProyek)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->exists();
    }
}
