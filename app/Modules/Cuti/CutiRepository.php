<?php

declare(strict_types=1);

namespace App\Modules\Cuti;

use App\Modules\Cuti\Contracts\CutiRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CutiRepository implements CutiRepositoryInterface
{
    public function findAllJenis(string $idPerusahaan): array
    {
        return DB::table('jenis_cuti')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('nama_jenis')
            ->get()
            ->all();
    }

    public function paginateJenis(string $idPerusahaan, int $page, int $limit, ?string $search = null): LengthAwarePaginator
    {
        return DB::table('jenis_cuti')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->when($search, fn ($q, $v) => $q->where('nama_jenis', 'like', "%{$v}%"))
            ->orderBy('nama_jenis')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findJenisById(string $id): ?object
    {
        return DB::table('jenis_cuti')
            ->whereNull('dihapus_pada')
            ->where('id_jenis_cuti', $id)
            ->first();
    }

    public function createJenis(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_jenis_cuti');
        DB::table('jenis_cuti')->insert($data);
        return $this->findJenisById($data['id_jenis_cuti']);
    }

    public function updateJenis(object $record, array $data): object
    {
        DB::table('jenis_cuti')
            ->where('id_jenis_cuti', $record->id_jenis_cuti)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findJenisById($record->id_jenis_cuti);
    }

    public function deleteJenis(object $record): void
    {
        DB::table('jenis_cuti')
            ->where('id_jenis_cuti', $record->id_jenis_cuti)
            ->update(RecordHelper::stampDelete());
    }

    public function paginatePengajuan(string $idPerusahaan, int $page, int $limit, ?string $status = null, ?string $search = null, ?string $tanggalDari = null, ?string $tanggalSampai = null): LengthAwarePaginator
    {
        return DB::table('pengajuan_cuti as pc')
            ->join('jenis_cuti as jc', 'pc.id_jenis_cuti', '=', 'jc.id_jenis_cuti')
            ->leftJoin('karyawan as k', 'pc.id_karyawan', '=', 'k.id_karyawan')
            ->leftJoin('supir as s', 'pc.id_supir', '=', 's.id_supir')
            ->whereNull('pc.dihapus_pada')
            ->where('pc.id_perusahaan', $idPerusahaan)
            ->when($status, fn ($q, $v) => $q->where('pc.status', $v))
            ->when($tanggalDari, fn ($q, $v) => $q->whereDate('pc.tanggal_selesai', '>=', $v))
            ->when($tanggalSampai, fn ($q, $v) => $q->whereDate('pc.tanggal_mulai', '<=', $v))
            ->when($search, fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('k.nama_karyawan', 'like', "%{$search}%")
                    ->orWhere('s.nama', 'like', "%{$search}%");
            }))
            ->select(
                'pc.*',
                'jc.nama_jenis',
                'jc.mengurangi_saldo',
                DB::raw('COALESCE(k.nama_karyawan, s.nama) as nama_orang'),
            )
            ->orderByDesc('pc.dibuat_pada')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findPengajuanById(string $id): ?object
    {
        return DB::table('pengajuan_cuti as pc')
            ->join('jenis_cuti as jc', 'pc.id_jenis_cuti', '=', 'jc.id_jenis_cuti')
            ->leftJoin('karyawan as k', 'pc.id_karyawan', '=', 'k.id_karyawan')
            ->leftJoin('supir as s', 'pc.id_supir', '=', 's.id_supir')
            ->whereNull('pc.dihapus_pada')
            ->where('pc.id_pengajuan', $id)
            ->select('pc.*', 'jc.nama_jenis', 'jc.mengurangi_saldo', DB::raw('COALESCE(k.nama_karyawan, s.nama) as nama_orang'))
            ->first();
    }

    public function createPengajuan(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_pengajuan');
        DB::table('pengajuan_cuti')->insert($data);
        return $this->findPengajuanById($data['id_pengajuan']);
    }

    public function updatePengajuan(object $record, array $data): object
    {
        DB::table('pengajuan_cuti')
            ->where('id_pengajuan', $record->id_pengajuan)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findPengajuanById($record->id_pengajuan);
    }

    public function adaPengajuanTumpangTindih(?string $idKaryawan, ?string $idSupir, string $tanggalMulai, string $tanggalSelesai, ?string $excludeId = null): bool
    {
        return DB::table('pengajuan_cuti')
            ->whereNull('dihapus_pada')
            ->whereIn('status', ['menunggu', 'disetujui'])
            ->when($idKaryawan, fn ($q, $v) => $q->where('id_karyawan', $v))
            ->when($idSupir, fn ($q, $v) => $q->where('id_supir', $v))
            ->whereDate('tanggal_mulai', '<=', $tanggalSelesai)
            ->whereDate('tanggal_selesai', '>=', $tanggalMulai)
            ->when($excludeId, fn ($q, $v) => $q->where('id_pengajuan', '!=', $v))
            ->exists();
    }

    public function sumLedger(?string $idKaryawan, ?string $idSupir, int $tahun): object
    {
        $row = DB::table('saldo_cuti')
            ->whereNull('dihapus_pada')
            ->where('tahun', $tahun)
            ->when($idKaryawan, fn ($q, $v) => $q->where('id_karyawan', $v))
            ->when($idSupir, fn ($q, $v) => $q->where('id_supir', $v))
            ->selectRaw("COALESCE(SUM(CASE WHEN tipe = 'debit' THEN jumlah_hari ELSE 0 END), 0) as terpakai")
            ->selectRaw("COALESCE(SUM(CASE WHEN tipe = 'penyesuaian' THEN jumlah_hari ELSE 0 END), 0) as penyesuaian")
            ->first();

        return (object) [
            'terpakai'    => (int) ($row->terpakai ?? 0),
            'penyesuaian' => (int) ($row->penyesuaian ?? 0),
        ];
    }

    public function riwayatLedger(?string $idKaryawan, ?string $idSupir, int $tahun): array
    {
        return DB::table('saldo_cuti')
            ->whereNull('dihapus_pada')
            ->where('tahun', $tahun)
            ->when($idKaryawan, fn ($q, $v) => $q->where('id_karyawan', $v))
            ->when($idSupir, fn ($q, $v) => $q->where('id_supir', $v))
            ->orderByDesc('dibuat_pada')
            ->get()
            ->all();
    }

    public function insertLedger(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_saldo_cuti');
        DB::table('saldo_cuti')->insert($data);
        return DB::table('saldo_cuti')->where('id_saldo_cuti', $data['id_saldo_cuti'])->first();
    }

    public function softDeleteDebitByReferensi(string $idPengajuan): void
    {
        DB::table('saldo_cuti')
            ->where('referensi_id', $idPengajuan)
            ->where('tipe', 'debit')
            ->whereNull('dihapus_pada')
            ->update(RecordHelper::stampDelete());
    }

    public function sumLedgerSemua(string $idPerusahaan, int $tahun): array
    {
        return DB::table('saldo_cuti')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('tahun', $tahun)
            ->groupBy('id_karyawan', 'id_supir')
            ->selectRaw('id_karyawan, id_supir')
            ->selectRaw("COALESCE(SUM(CASE WHEN tipe = 'debit' THEN jumlah_hari ELSE 0 END), 0) as terpakai")
            ->selectRaw("COALESCE(SUM(CASE WHEN tipe = 'penyesuaian' THEN jumlah_hari ELSE 0 END), 0) as penyesuaian")
            ->get()
            ->all();
    }

    public function supirSedangCuti(string $idSupir, string $tanggal): bool
    {
        return DB::table('pengajuan_cuti')
            ->whereNull('dihapus_pada')
            ->where('id_supir', $idSupir)
            ->where('status', 'disetujui')
            ->whereDate('tanggal_mulai', '<=', $tanggal)
            ->whereDate('tanggal_selesai', '>=', $tanggal)
            ->exists();
    }

    public function orangCutiPadaTanggal(string $idPerusahaan, string $tanggal): array
    {
        return DB::table('pengajuan_cuti')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('status', 'disetujui')
            ->whereDate('tanggal_mulai', '<=', $tanggal)
            ->whereDate('tanggal_selesai', '>=', $tanggal)
            ->select('id_karyawan', 'id_supir', 'tanggal_mulai', 'tanggal_selesai')
            ->get()
            ->all();
    }
}
