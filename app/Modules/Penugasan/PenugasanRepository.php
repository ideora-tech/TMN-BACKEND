<?php

declare(strict_types=1);

namespace App\Modules\Penugasan;

use App\Modules\Penugasan\Contracts\PenugasanRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PenugasanRepository implements PenugasanRepositoryInterface
{
    public function listBySupirUntukProyek(string $idSupir, array $idProyekList): Collection
    {
        if ($idProyekList === []) {
            return new Collection();
        }

        return PenugasanModel::active()
            ->with(['proyek', 'armada'])
            ->where('id_supir', $idSupir)
            ->whereIn('id_proyek', $idProyekList)
            ->orderBy('dibuat_pada', 'desc')
            ->get();
    }

    public function listJadwalSupir(string $idSupir, string $dari, string $sampai): Collection
    {
        return PenugasanModel::active()
            ->with(['proyek', 'armada'])
            ->where('id_supir', $idSupir)
            ->whereBetween('tanggal_tugas', [$dari, $sampai])
            ->orderBy('tanggal_tugas')
            ->orderBy('dibuat_pada')
            ->get();
    }

    public function paginateByProyek(string $idProyek, int $page, int $limit, ?string $sumber = null, ?string $status = null): LengthAwarePaginator
    {
        return PenugasanModel::active()
            ->where('id_proyek', $idProyek)
            ->when($sumber, fn ($q, $v) => $this->terapkanFilterSumber($q, $v))
            ->when($status, fn ($q, $v) => $q->whereIn('status', explode(',', $v)))
            ->orderBy('dibuat_pada', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }

    /** Tabel penugasan tidak punya id_perusahaan — tenant di-scope lewat proyek. */
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $sumber = null, ?string $status = null): LengthAwarePaginator
    {
        return PenugasanModel::active()
            ->with(['proyek', 'armada'])
            ->whereHas('proyek', fn ($q) => $q->where('id_perusahaan', $idPerusahaan)->whereNull('dihapus_pada'))
            ->when($sumber, fn ($q, $v) => $this->terapkanFilterSumber($q, $v))
            ->when($status, fn ($q, $v) => $q->whereIn('status', explode(',', $v)))
            ->orderBy('tanggal_tugas', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function paginateByArmada(string $idArmada, int $page, int $limit, ?string $sumber = null, ?string $status = null): LengthAwarePaginator
    {
        return PenugasanModel::active()
            ->where('id_armada', $idArmada)
            ->when($sumber, fn ($q, $v) => $this->terapkanFilterSumber($q, $v))
            ->when($status, fn ($q, $v) => $q->whereIn('status', explode(',', $v)))
            ->orderBy('tanggal_tugas', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function paginateBySupir(string $idSupir, int $page, int $limit, ?string $sumber = null, ?string $status = null): LengthAwarePaginator
    {
        return PenugasanModel::active()
            ->with(['proyek', 'armada'])
            ->where('id_supir', $idSupir)
            ->when($sumber, fn ($q, $v) => $this->terapkanFilterSumber($q, $v))
            ->when($status, fn ($q, $v) => $q->whereIn('status', explode(',', $v)))
            ->orderBy('tanggal_tugas', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }

    /**
     * `sumber=operasional` adalah filter gabungan khusus untuk daftar Penugasan
     * Operasional: internal + vendor bermekanisme unit_only (id_supir_vendor kosong
     * membedakannya dari unit_driver/full yang tetap hanya tampil di Penugasan Vendor).
     * Nilai lain ('internal'/'vendor') tetap exact-match seperti sebelumnya.
     */
    private function terapkanFilterSumber($query, string $sumber): void
    {
        if ($sumber === 'operasional') {
            $query->where(function ($q) {
                $q->where('sumber', 'internal')
                    ->orWhere(function ($q2) {
                        $q2->where('sumber', 'vendor')->whereNull('id_supir_vendor');
                    });
            });
            return;
        }

        $query->where('sumber', $sumber);
    }

    public function countSelesaiByProyek(string $idProyek): int
    {
        return PenugasanModel::active()
            ->where('id_proyek', $idProyek)
            ->where('status', 'selesai')
            ->count();
    }

    public function findById(string $id): ?PenugasanModel
    {
        return PenugasanModel::active()->find($id);
    }

    public function hasConflict(string $idKaryawan, string $tanggalTugas, ?string $excludeId = null): bool
    {
        $query = PenugasanModel::active()
            ->where('id_karyawan', $idKaryawan)
            ->where('tanggal_tugas', $tanggalTugas)
            ->whereIn('status', ['pending', 'aktif']);

        if ($excludeId !== null) {
            $query->where('id_penugasan', '!=', $excludeId);
        }

        return $query->exists();
    }

    public function existsAktifUntukSupirProyek(string $idProyek, string $idSupir, ?string $excludeId = null): bool
    {
        $query = PenugasanModel::active()
            ->where('id_proyek', $idProyek)
            ->where('id_supir', $idSupir)
            ->where('sumber', 'internal')
            ->whereIn('status', ['pending', 'aktif']);

        if ($excludeId !== null) {
            $query->where('id_penugasan', '!=', $excludeId);
        }

        return $query->exists();
    }

    public function findAktifUntukSupirDiProyek(string $idSupir, string $idProyek): ?PenugasanModel
    {
        return PenugasanModel::where('id_supir', $idSupir)
            ->where('id_proyek', $idProyek)
            ->whereIn('status', ['pending', 'aktif'])
            ->whereNull('dihapus_pada')
            ->first();
    }

    public function adaKonflikAktorPadaTanggal(string $kolomAktor, string $idAktor, string $tanggalTugas, ?string $excludeId = null): bool
    {
        if (!in_array($kolomAktor, ['id_armada_vendor', 'id_supir_vendor', 'id_supir'], true)) {
            return false;
        }

        $query = PenugasanModel::active()
            ->where($kolomAktor, $idAktor)
            ->where('tanggal_tugas', $tanggalTugas)
            ->whereIn('status', ['pending', 'aktif']);

        if ($excludeId !== null) {
            $query->where('id_penugasan', '!=', $excludeId);
        }

        return $query->exists();
    }

    public function create(array $data): PenugasanModel
    {
        return PenugasanModel::create($data);
    }

    public function update(PenugasanModel $model, array $data): PenugasanModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(PenugasanModel $model): void
    {
        $model->softDelete();
    }

    public function syncTitikDrop(string $idPenugasan, array $lokasiList): void
    {
        DB::table('titik_drop_penugasan')
            ->where('id_penugasan', $idPenugasan)
            ->whereNull('dihapus_pada')
            ->update(RecordHelper::stampDelete());

        foreach (array_values($lokasiList) as $i => $lokasi) {
            DB::table('titik_drop_penugasan')->insert(RecordHelper::stampCreate([
                'id_penugasan' => $idPenugasan,
                'urutan'       => $i + 1,
                'lokasi'       => trim((string) $lokasi),
            ], 'id_titik_drop'));
        }
    }

    public function titikDropUntukBanyak(array $idPenugasan): array
    {
        if ($idPenugasan === []) {
            return [];
        }

        return DB::table('titik_drop_penugasan')
            ->whereIn('id_penugasan', $idPenugasan)
            ->whereNull('dihapus_pada')
            ->orderBy('urutan')
            ->get(['id_penugasan', 'lokasi'])
            ->groupBy('id_penugasan')
            ->map(fn ($g) => $g->pluck('lokasi')->all())
            ->all();
    }
}
