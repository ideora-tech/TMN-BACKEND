<?php

declare(strict_types=1);

namespace App\Modules\ProyekRute;

use App\Modules\ProyekRute\Contracts\ProyekRuteRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProyekRuteRepository implements ProyekRuteRepositoryInterface
{
    private function detailQuery(): Builder
    {
        return ProyekRuteModel::active()
            ->leftJoin('rute', 'rute.id_rute', '=', 'proyek_rute.id_rute')
            ->leftJoin('jenis_kendaraan', 'jenis_kendaraan.id_jenis_kendaraan', '=', 'proyek_rute.id_jenis_kendaraan')
            ->select(
                'proyek_rute.*',
                'rute.kode_rute',
                'rute.nama_rute',
                'rute.asal',
                'rute.tujuan',
                'jenis_kendaraan.nama_jenis',
            );
    }

    public function listByProyek(string $idProyek): Collection
    {
        return $this->detailQuery()
            ->where('proyek_rute.id_proyek', $idProyek)
            ->orderBy('proyek_rute.dibuat_pada')
            ->get();
    }

    public function ruteTerdaftarUntukProyek(string $idProyek, string $idRute): bool
    {
        return ProyekRuteModel::active()
            ->where('id_proyek', $idProyek)
            ->where('id_rute', $idRute)
            ->exists();
    }

    public function findById(string $id): ?ProyekRuteModel
    {
        return ProyekRuteModel::active()->find($id);
    }

    public function findDetailById(string $id): ?ProyekRuteModel
    {
        return $this->detailQuery()->where('proyek_rute.id_proyek_rute', $id)->first();
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

    public function existsDuplikat(string $idProyek, string $idRute, ?string $idJenisKendaraan, ?string $excludeId = null): bool
    {
        $query = ProyekRuteModel::active()
            ->where('id_proyek', $idProyek)
            ->where('id_rute', $idRute);

        if ($idJenisKendaraan === null) {
            $query->whereNull('id_jenis_kendaraan');
        } else {
            $query->where('id_jenis_kendaraan', $idJenisKendaraan);
        }

        if ($excludeId !== null) {
            $query->where('id_proyek_rute', '!=', $excludeId);
        }

        return $query->exists();
    }

    public function findHarga(string $idProyek, string $idRute, ?string $idJenisKendaraan): ?object
    {
        if ($idJenisKendaraan !== null) {
            $baris = ProyekRuteModel::active()
                ->where('id_proyek', $idProyek)
                ->where('id_rute', $idRute)
                ->where('id_jenis_kendaraan', $idJenisKendaraan)
                ->first();

            if ($baris !== null) {
                return $baris;
            }
        }

        return ProyekRuteModel::active()
            ->where('id_proyek', $idProyek)
            ->where('id_rute', $idRute)
            ->whereNull('id_jenis_kendaraan')
            ->first();
    }

    public function adaPenawaranDisetujui(string $idProyek): bool
    {
        return DB::table('penawaran')
            ->where('id_proyek', $idProyek)
            ->where('status', 'disetujui')
            ->whereNull('dihapus_pada')
            ->exists();
    }

    public function tipeHargaProyek(string $idProyek): ?string
    {
        return DB::table('proyek')
            ->whereNull('dihapus_pada')
            ->where('id_proyek', $idProyek)
            ->value('tipe_harga');
    }

    public function findBarisTepat(string $idProyek, string $idRute, ?string $idJenisKendaraan): ?ProyekRuteModel
    {
        $query = ProyekRuteModel::active()
            ->where('id_proyek', $idProyek)
            ->where('id_rute', $idRute);

        if ($idJenisKendaraan === null) {
            $query->whereNull('id_jenis_kendaraan');
        } else {
            $query->where('id_jenis_kendaraan', $idJenisKendaraan);
        }

        return $query->first();
    }

    public function create(array $data): ProyekRuteModel
    {
        return ProyekRuteModel::create($data);
    }

    public function update(ProyekRuteModel $model, array $data): ProyekRuteModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(ProyekRuteModel $model): void
    {
        $model->softDelete();
    }
}
