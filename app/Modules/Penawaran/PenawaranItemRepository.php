<?php

declare(strict_types=1);

namespace App\Modules\Penawaran;

use App\Modules\Penawaran\Contracts\PenawaranItemRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PenawaranItemRepository implements PenawaranItemRepositoryInterface
{
    public function listByPenawaran(string $idPenawaran): Collection
    {
        return PenawaranItemModel::active()
            ->leftJoin('rute', 'rute.id_rute', '=', 'penawaran_item.id_rute')
            ->leftJoin('jenis_kendaraan', 'jenis_kendaraan.id_jenis_kendaraan', '=', 'penawaran_item.id_jenis_kendaraan')
            ->where('penawaran_item.id_penawaran', $idPenawaran)
            ->select(
                'penawaran_item.*',
                'rute.kode_rute',
                'rute.nama_rute',
                'rute.asal',
                'rute.tujuan',
                'jenis_kendaraan.nama_jenis',
            )
            ->orderBy('penawaran_item.dibuat_pada')
            ->get();
    }

    public function create(array $data): PenawaranItemModel
    {
        return PenawaranItemModel::create($data);
    }

    public function deleteByPenawaran(string $idPenawaran): void
    {
        PenawaranItemModel::active()
            ->where('id_penawaran', $idPenawaran)
            ->get()
            ->each(fn (PenawaranItemModel $item) => $item->softDelete());
    }

    public function ruteMilik(string $id, string $idPerusahaan): ?object
    {
        return DB::table('rute')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('id_rute', $id)
            ->first();
    }

    public function jenisKendaraanMilik(string $id, string $idPerusahaan): ?object
    {
        return DB::table('jenis_kendaraan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('id_jenis_kendaraan', $id)
            ->first();
    }
}
