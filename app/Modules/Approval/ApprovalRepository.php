<?php

declare(strict_types=1);

namespace App\Modules\Approval;

use App\Modules\Approval\Contracts\ApprovalRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ApprovalRepository implements ApprovalRepositoryInterface
{
    public function listEventType(string $idPerusahaan): Collection
    {
        return ApprovalEventTypeModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('nama')
            ->get();
    }

    public function findEventTypeOrFail(string $id, string $idPerusahaan): ApprovalEventTypeModel
    {
        $record = ApprovalEventTypeModel::active()
            ->where('id_event_type', $id)
            ->where('id_perusahaan', $idPerusahaan)
            ->first();

        if ($record === null) {
            abort(404, 'Event type approval tidak ditemukan');
        }

        return $record;
    }

    public function findEventTypeAktifByKode(string $kode, string $idPerusahaan): ?ApprovalEventTypeModel
    {
        return ApprovalEventTypeModel::active()
            ->where('kode', $kode)
            ->where('id_perusahaan', $idPerusahaan)
            ->where('aktif', 1)
            ->first();
    }

    public function createEventType(array $data): ApprovalEventTypeModel
    {
        return ApprovalEventTypeModel::create($data);
    }

    public function updateEventType(ApprovalEventTypeModel $model, array $data): ApprovalEventTypeModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function listConfigApprover(string $idEventType): array
    {
        return DB::table('approval_config_approver as ac')
            ->leftJoin('jabatan as j', 'j.id_jabatan', '=', 'ac.id_jabatan')
            ->leftJoin('pengguna as p', 'p.id_pengguna', '=', 'ac.id_pengguna')
            ->where('ac.id_event_type', $idEventType)
            ->whereNull('ac.dihapus_pada')
            ->orderBy('ac.dibuat_pada')
            ->selectRaw('ac.*, COALESCE(j.nama_jabatan, p.username) as nama')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    public function insertConfigApprover(array $data): string
    {
        $stamped = RecordHelper::stampCreate($data, 'id_config');
        DB::table('approval_config_approver')->insert($stamped);
        return $stamped['id_config'];
    }

    public function deleteConfigApprover(string $id, string $idEventType): bool
    {
        $jumlah = DB::table('approval_config_approver')
            ->where('id_config', $id)
            ->where('id_event_type', $idEventType)
            ->whereNull('dihapus_pada')
            ->update(RecordHelper::stampDelete());

        return $jumlah > 0;
    }

    public function resolvePinned(string $idEventType, string $idPerusahaan): array
    {
        $dariPengguna = DB::table('approval_config_approver as ac')
            ->join('pengguna as p', 'p.id_pengguna', '=', 'ac.id_pengguna')
            ->where('ac.id_event_type', $idEventType)
            ->where('ac.tipe', 'pengguna')
            ->whereNull('ac.dihapus_pada')
            ->where('p.id_perusahaan', $idPerusahaan)
            ->where('p.aktif', 1)
            ->whereNull('p.dihapus_pada')
            ->pluck('p.id_pengguna');

        $dariJabatan = DB::table('approval_config_approver as ac')
            ->join('karyawan as k', 'k.id_jabatan', '=', 'ac.id_jabatan')
            ->join('pengguna as p', 'p.id_karyawan', '=', 'k.id_karyawan')
            ->where('ac.id_event_type', $idEventType)
            ->where('ac.tipe', 'jabatan')
            ->whereNull('ac.dihapus_pada')
            ->where('k.id_perusahaan', $idPerusahaan)
            ->where('k.aktif', 1)
            ->whereNull('k.dihapus_pada')
            ->where('p.id_perusahaan', $idPerusahaan)
            ->where('p.aktif', 1)
            ->whereNull('p.dihapus_pada')
            ->pluck('p.id_pengguna');

        return $dariPengguna->merge($dariJabatan)->unique()->values()->all();
    }

    public function cariJabatanPengguna(string $idPengguna, string $idPerusahaan): ?object
    {
        return DB::table('pengguna as p')
            ->join('karyawan as k', 'k.id_karyawan', '=', 'p.id_karyawan')
            ->where('p.id_pengguna', $idPengguna)
            ->where('p.id_perusahaan', $idPerusahaan)
            ->whereNull('k.dihapus_pada')
            ->select('k.id_jabatan')
            ->first();
    }

    public function cariJabatanInduk(string $idJabatan): ?object
    {
        return DB::table('jabatan')
            ->where('id_jabatan', $idJabatan)
            ->whereNull('dihapus_pada')
            ->select('id_jabatan', 'id_jabatan_induk')
            ->first();
    }

    public function cariUserAktifPemegangJabatan(string $idJabatan, string $idPerusahaan): array
    {
        return DB::table('karyawan as k')
            ->join('pengguna as p', 'p.id_karyawan', '=', 'k.id_karyawan')
            ->where('k.id_jabatan', $idJabatan)
            ->where('k.id_perusahaan', $idPerusahaan)
            ->where('k.aktif', 1)
            ->whereNull('k.dihapus_pada')
            ->where('p.id_perusahaan', $idPerusahaan)
            ->where('p.aktif', 1)
            ->whereNull('p.dihapus_pada')
            ->pluck('p.id_pengguna')
            ->all();
    }

    public function createPengajuan(array $data): ApprovalPengajuanModel
    {
        return ApprovalPengajuanModel::create($data);
    }

    public function insertKeputusanRows(string $idApproval, array $idPenggunaList): void
    {
        $now = now();
        $rows = array_map(fn (string $idPengguna) => RecordHelper::stampCreate([
            'id_approval' => $idApproval,
            'id_pengguna' => $idPengguna,
            'status'      => 'menunggu',
        ], 'id_keputusan'), $idPenggunaList);

        DB::table('approval_keputusan')->insert($rows);
    }

    public function findPengajuanOrFail(string $id): ApprovalPengajuanModel { return ApprovalPengajuanModel::active()->findOrFail($id); }
    public function findKeputusanMenunggu(string $idApproval, string $idPengguna): ?object { return null; }
    public function updateKeputusan(string $idKeputusan, array $data): void {}
    public function hitungKeputusanBelumSetuju(string $idApproval): int { return 0; }
    public function updatePengajuan(ApprovalPengajuanModel $model, array $data): ApprovalPengajuanModel { $model->update($data); return $model->fresh(); }
    public function findPengajuanAktifUntukReferensi(string $idEventType, string $idReferensi): ?ApprovalPengajuanModel { return null; }
    public function progressApproval(string $idApproval): array { return ['disetujui' => 0, 'total' => 0]; }
    public function listMenungguApprovalSaya(string $idPengguna, string $idPerusahaan): Collection { return collect(); }
}
