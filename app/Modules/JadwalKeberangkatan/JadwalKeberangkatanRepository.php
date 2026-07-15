<?php

declare(strict_types=1);

namespace App\Modules\JadwalKeberangkatan;

use App\Modules\JadwalKeberangkatan\Contracts\JadwalKeberangkatanRepositoryInterface;
use App\Modules\Penugasan\PenugasanModel;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class JadwalKeberangkatanRepository implements JadwalKeberangkatanRepositoryInterface
{
    public function paginateByPenugasan(string $idPenugasan, int $page, int $limit): LengthAwarePaginator
    {
        return JadwalKeberangkatanModel::active()
            ->where('id_penugasan', $idPenugasan)
            ->leftJoin('trip as t', 't.id_jadwal', '=', 'jadwal_keberangkatan.id_jadwal')
            ->select('jadwal_keberangkatan.*', DB::raw("COALESCE(t.status, 'terjadwal') as status"))
            ->orderBy('jadwal_keberangkatan.waktu_berangkat', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        return JadwalKeberangkatanModel::active()
            ->join('penugasan as p', 'p.id_penugasan', '=', 'jadwal_keberangkatan.id_penugasan')
            ->join('proyek as pr', 'pr.id_proyek', '=', 'p.id_proyek')
            ->leftJoin('trip as t', 't.id_jadwal', '=', 'jadwal_keberangkatan.id_jadwal')
            ->where('pr.id_perusahaan', $idPerusahaan)
            ->whereNull('p.dihapus_pada')
            ->whereNull('pr.dihapus_pada')
            ->select('jadwal_keberangkatan.*', DB::raw("COALESCE(t.status, 'terjadwal') as status"))
            ->orderBy('jadwal_keberangkatan.waktu_berangkat', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?JadwalKeberangkatanModel
    {
        return JadwalKeberangkatanModel::active()->find($id);
    }

    public function findBySupir(string $idSupir, int $page, int $limit): LengthAwarePaginator
    {
        $penugasanIds = PenugasanModel::active()
            ->where('id_karyawan', $idSupir)
            ->pluck('id_penugasan');

        return JadwalKeberangkatanModel::active()
            ->whereIn('id_penugasan', $penugasanIds)
            ->orderBy('waktu_berangkat', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findKandidatBentrok(?string $idArmada, ?string $idSupir, ?string $idArmadaVendor, ?string $idSupirVendor, ?string $excludeJadwalId): array
    {
        if (!$idArmada && !$idSupir && !$idArmadaVendor && !$idSupirVendor) {
            return [];
        }

        return JadwalKeberangkatanModel::active()
            ->join('penugasan as p', 'p.id_penugasan', '=', 'jadwal_keberangkatan.id_penugasan')
            ->leftJoin('trip as t', 't.id_jadwal', '=', 'jadwal_keberangkatan.id_jadwal')
            ->whereNull('p.dihapus_pada')
            ->whereNotNull('jadwal_keberangkatan.waktu_berangkat')
            ->where(function ($q) use ($idArmada, $idSupir, $idArmadaVendor, $idSupirVendor) {
                if ($idArmada) {
                    $q->orWhere('p.id_armada', $idArmada);
                }
                if ($idSupir) {
                    $q->orWhere('p.id_supir', $idSupir);
                }
                if ($idArmadaVendor) {
                    $q->orWhere('p.id_armada_vendor', $idArmadaVendor);
                }
                if ($idSupirVendor) {
                    $q->orWhere('p.id_supir_vendor', $idSupirVendor);
                }
            })
            ->where(function ($q) {
                $q->whereNull('t.status')->orWhereNotIn('t.status', ['selesai', 'dibatalkan']);
            })
            ->when($excludeJadwalId, fn ($q) => $q->where('jadwal_keberangkatan.id_jadwal', '!=', $excludeJadwalId))
            ->select('jadwal_keberangkatan.id_jadwal', 'jadwal_keberangkatan.waktu_berangkat', 'jadwal_keberangkatan.estimasi_tiba')
            ->get()->all();
    }

    public function create(array $data): JadwalKeberangkatanModel
    {
        return JadwalKeberangkatanModel::create($data);
    }

    public function update(JadwalKeberangkatanModel $model, array $data): JadwalKeberangkatanModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(JadwalKeberangkatanModel $model): void
    {
        $model->softDelete();
    }
}
