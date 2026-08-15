<?php

declare(strict_types=1);

namespace App\Modules\Payroll;

use App\Modules\Payroll\Contracts\PayrollRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PayrollRepository implements PayrollRepositoryInterface
{
    public function getPengaturan(string $idPerusahaan): ?object
    {
        return DB::table('pengaturan_payroll')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->first();
    }

    public function upsertPengaturan(string $idPerusahaan, array $data): object
    {
        $existing = $this->getPengaturan($idPerusahaan);

        if ($existing !== null) {
            DB::table('pengaturan_payroll')
                ->where('id_pengaturan', $existing->id_pengaturan)
                ->update(RecordHelper::stampUpdate($data));
        } else {
            DB::table('pengaturan_payroll')->insert(RecordHelper::stampCreate(
                array_merge($data, ['id_perusahaan' => $idPerusahaan]),
                'id_pengaturan'
            ));
        }

        return $this->getPengaturan($idPerusahaan);
    }

    public function paginatePeriode(string $idPerusahaan, int $page, int $limit, ?string $search = null, ?string $status = null): LengthAwarePaginator
    {
        $slipAgg = DB::table('payroll_slip')
            ->whereNull('dihapus_pada')
            ->groupBy('id_periode')
            ->selectRaw('id_periode, COUNT(*) as jumlah_slip, COALESCE(SUM(gaji_bersih), 0) as total_gaji_bersih');

        return DB::table('payroll_periode as p')
            ->leftJoinSub($slipAgg, 's', 's.id_periode', '=', 'p.id_periode')
            ->whereNull('p.dihapus_pada')
            ->where('p.id_perusahaan', $idPerusahaan)
            ->when($search, fn ($q, $v) => $q->where('p.nama', 'like', "%{$v}%"))
            ->when($status, fn ($q, $v) => $q->where('p.status', $v))
            ->select('p.*', DB::raw('COALESCE(s.jumlah_slip, 0) as jumlah_slip'), DB::raw('COALESCE(s.total_gaji_bersih, 0) as total_gaji_bersih'))
            ->orderByDesc('p.tanggal_mulai')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findPeriodeById(string $id): ?object
    {
        return DB::table('payroll_periode')
            ->whereNull('dihapus_pada')
            ->where('id_periode', $id)
            ->first();
    }

    public function adaPeriodeTumpangTindih(string $idPerusahaan, string $mulai, string $selesai, ?string $excludeId = null): bool
    {
        return DB::table('payroll_periode')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->whereDate('tanggal_mulai', '<=', $selesai)
            ->whereDate('tanggal_selesai', '>=', $mulai)
            ->when($excludeId, fn ($q, $v) => $q->where('id_periode', '!=', $v))
            ->exists();
    }

    public function createPeriode(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_periode');
        DB::table('payroll_periode')->insert($data);
        return $this->findPeriodeById($data['id_periode']);
    }

    public function updatePeriode(object $record, array $data): object
    {
        DB::table('payroll_periode')
            ->where('id_periode', $record->id_periode)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findPeriodeById($record->id_periode);
    }

    public function deletePeriode(object $record): void
    {
        DB::table('payroll_periode')
            ->where('id_periode', $record->id_periode)
            ->update(RecordHelper::stampDelete());
    }

    public function slipByPeriode(string $idPeriode): array
    {
        return DB::table('payroll_slip as s')
            ->join('karyawan as k', 's.id_karyawan', '=', 'k.id_karyawan')
            ->whereNull('s.dihapus_pada')
            ->where('s.id_periode', $idPeriode)
            ->select('s.*', 'k.nik as karyawan_nik', 'k.nama_karyawan')
            ->orderBy('k.nama_karyawan')
            ->get()
            ->all();
    }

    public function findSlipById(string $id): ?object
    {
        return DB::table('payroll_slip as s')
            ->join('karyawan as k', 's.id_karyawan', '=', 'k.id_karyawan')
            ->leftJoin('jabatan as j', 'k.id_jabatan', '=', 'j.id_jabatan')
            ->whereNull('s.dihapus_pada')
            ->where('s.id_slip', $id)
            ->select('s.*', 'k.nik as karyawan_nik', 'k.nama_karyawan', 'k.nama_bank', 'k.nomor_rekening', 'j.nama_jabatan')
            ->first();
    }

    public function findSlipByPeriodeKaryawan(string $idPeriode, string $idKaryawan): ?object
    {
        return DB::table('payroll_slip')
            ->whereNull('dihapus_pada')
            ->where('id_periode', $idPeriode)
            ->where('id_karyawan', $idKaryawan)
            ->first();
    }

    public function semuaKaryawan(string $idPerusahaan): array
    {
        return DB::table('karyawan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->select('id_karyawan', 'nama_karyawan')
            ->get()
            ->all();
    }

    public function karyawanUntukTemplate(string $idPerusahaan): array
    {
        return DB::table('karyawan as k')
            ->leftJoin('jabatan as j', 'k.id_jabatan', '=', 'j.id_jabatan')
            ->whereNull('k.dihapus_pada')
            ->where('k.id_perusahaan', $idPerusahaan)
            ->where('k.aktif', 1)
            ->select(
                'k.id_karyawan', 'k.nama_karyawan', 'k.status_kepegawaian',
                'k.nama_bank', 'k.nomor_rekening', 'k.gaji_pokok', 'j.nama_jabatan',
            )
            ->orderBy('k.nama_karyawan')
            ->get()
            ->all();
    }

    public function getPerusahaan(string $idPerusahaan): ?object
    {
        return DB::table('perusahaan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->first();
    }

    public function createSlip(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_slip');
        DB::table('payroll_slip')->insert($data);
        return $this->findSlipById($data['id_slip']);
    }

    public function updateSlip(object $record, array $data): object
    {
        DB::table('payroll_slip')
            ->where('id_slip', $record->id_slip)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findSlipById($record->id_slip);
    }

    public function hapusSlipByPeriode(string $idPeriode): void
    {
        DB::table('payroll_slip')
            ->whereNull('dihapus_pada')
            ->where('id_periode', $idPeriode)
            ->update(RecordHelper::stampDelete());
    }

    public function tanggalExitTerakhir(string $idPerusahaan): array
    {
        return DB::table('karyawan_exit')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->groupBy('id_karyawan')
            ->selectRaw('id_karyawan, MAX(tanggal_efektif) as tanggal_efektif')
            ->pluck('tanggal_efektif', 'id_karyawan')
            ->all();
    }

    public function ringkasanPeriode(string $idPeriode): object
    {
        $row = DB::table('payroll_slip')
            ->whereNull('dihapus_pada')
            ->where('id_periode', $idPeriode)
            ->selectRaw('COUNT(*) as jumlah_slip')
            ->selectRaw('COALESCE(SUM(total_bruto), 0) as total_bruto')
            ->selectRaw('COALESCE(SUM(total_potongan), 0) as total_potongan')
            ->selectRaw('COALESCE(SUM(gaji_bersih), 0) as total_gaji_bersih')
            ->first();

        return (object) [
            'jumlah_slip'       => (int) ($row->jumlah_slip ?? 0),
            'total_bruto'       => (float) ($row->total_bruto ?? 0),
            'total_potongan'    => (float) ($row->total_potongan ?? 0),
            'total_gaji_bersih' => (float) ($row->total_gaji_bersih ?? 0),
        ];
    }
}
