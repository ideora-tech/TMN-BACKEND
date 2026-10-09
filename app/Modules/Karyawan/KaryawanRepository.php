<?php

declare(strict_types=1);

namespace App\Modules\Karyawan;

use App\Modules\Karyawan\Contracts\KaryawanRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class KaryawanRepository implements KaryawanRepositoryInterface
{
    private const COLUMNS = [
        'id_karyawan', 'id_perusahaan', 'id_jabatan', 'id_lokasi', 'nik', 'nik_ktp', 'nama_karyawan',
        'email', 'telepon', 'jenis_kelamin', 'tempat_lahir', 'tanggal_lahir',
        'alamat_ktp', 'alamat_domisili', 'status_pernikahan', 'jumlah_tanggungan', 'status_ptkp',
        'npwp', 'nama_bank', 'nomor_rekening', 'atas_nama_rekening',
        'ikut_bpjs_kesehatan', 'no_bpjs_kesehatan', 'ikut_bpjs_ketenagakerjaan', 'no_bpjs_ketenagakerjaan',
        'override_persen_bpjs_kesehatan', 'override_persen_bpjs_jht', 'override_persen_bpjs_jp', 'override_plafon_bpjs_kesehatan',
        'override_tunjangan_jabatan',
        'kontak_darurat_nama', 'kontak_darurat_telepon', 'kontak_darurat_hubungan', 'pendidikan_terakhir',
        'tanggal_masuk', 'status_kepegawaian', 'gaji_pokok', 'aktif',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $status = null, ?string $search = null): LengthAwarePaginator
    {
        $query = DB::table('karyawan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan);

        if ($status !== null && $status !== '') {
            $query->where('status_kepegawaian', $status);
        }

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('nama_karyawan', 'like', "%{$search}%")
                  ->orWhere('nik', 'like', "%{$search}%");
            });
        }

        $paginator = $query
            ->orderBy('nama_karyawan')
            ->paginate($limit, self::COLUMNS, 'page', $page);

        $this->attachJabatanLokasi($paginator->getCollection());

        return $paginator;
    }

    public function findById(string $id): ?object
    {
        $record = DB::table('karyawan')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_karyawan', $id)
            ->first();

        if ($record !== null) {
            $this->attachJabatanLokasi(collect([$record]));
        }

        return $record;
    }

    public function findByNik(string $nik): ?object
    {
        return DB::table('karyawan')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('nik', $nik)
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_karyawan');
        DB::table('karyawan')->insert($data);
        return $this->findById($data['id_karyawan']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('karyawan')
            ->where('id_karyawan', $record->id_karyawan)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_karyawan);
    }

    public function delete(object $record): void
    {
        DB::table('karyawan')
            ->where('id_karyawan', $record->id_karyawan)
            ->update(RecordHelper::stampDelete());
    }

    public function dipakaiRelasiAktif(string $idKaryawan): bool
    {
        foreach (['pengguna', 'supir', 'penugasan', 'kontrak_karyawan', 'absensi', 'payroll_slip', 'pengajuan_cuti', 'karyawan_exit', 'kasbon'] as $tabel) {
            $dipakai = DB::table($tabel)
                ->whereNull('dihapus_pada')
                ->where('id_karyawan', $idKaryawan)
                ->exists();
            if ($dipakai) {
                return true;
            }
        }

        return false;
    }

    public function exitHistory(string $idKaryawan): array
    {
        return DB::table('karyawan_exit')
            ->where('id_karyawan', $idKaryawan)
            ->orderBy('tanggal_efektif', 'desc')
            ->get()
            ->all();
    }

    public function kunci(string $idKaryawan): void
    {
        DB::table('karyawan')->where('id_karyawan', $idKaryawan)->lockForUpdate()->value('id_karyawan');
    }

    public function insertRiwayatJabatan(array $data): void
    {
        $data['urutan'] = (int) DB::table('riwayat_jabatan')
            ->where('id_karyawan', $data['id_karyawan'])
            ->max('urutan') + 1;

        DB::table('riwayat_jabatan')->insert(RecordHelper::stampCreate($data, 'id_riwayat'));
    }

    public function updateRiwayatJabatan(string $idRiwayat, array $data): void
    {
        DB::table('riwayat_jabatan')
            ->where('id_riwayat', $idRiwayat)
            ->update(RecordHelper::stampUpdate($data));
    }

    public function riwayatJabatan(string $idKaryawan): array
    {
        return DB::table('riwayat_jabatan as r')
            ->leftJoin('pengguna as p', 'p.id_pengguna', '=', 'r.dibuat_oleh')
            ->whereNull('r.dihapus_pada')
            ->where('r.id_karyawan', $idKaryawan)
            ->orderByRaw('CASE WHEN r.urutan = 0 THEN 1 ELSE 0 END')
            ->orderBy('r.urutan')
            ->orderBy('r.dibuat_pada')
            ->get([
                'r.id_riwayat', 'r.id_jabatan_lama', 'r.id_jabatan_baru',
                'r.nama_jabatan_lama', 'r.nama_jabatan_baru', 'r.nama_departemen_lama', 'r.nama_departemen_baru',
                'r.tanggal_efektif', 'r.jenis', 'r.nomor_sk', 'r.keterangan', 'r.dibuat_pada',
                'p.username as dicatat_oleh',
            ])
            ->all();
    }

    public function infoJabatan(string $idJabatan): ?object
    {
        return DB::table('jabatan as j')
            ->leftJoin('departemen as d', 'd.id_departemen', '=', 'j.id_departemen')
            ->where('j.id_jabatan', $idJabatan)
            ->first(['j.id_jabatan', 'j.nama_jabatan', 'd.nama_departemen']);
    }

    /**
     * Tempel nama jabatan & lokasi via raw query builder (join manual),
     * bukan Eloquent relationship.
     */
    private function attachJabatanLokasi(Collection $records): void
    {
        $idJabatanList = $records->pluck('id_jabatan')->filter()->unique()->values()->all();
        $idLokasiList  = $records->pluck('id_lokasi')->filter()->unique()->values()->all();

        $jabatanById = empty($idJabatanList)
            ? collect()
            : DB::table('jabatan')->whereIn('id_jabatan', $idJabatanList)
                ->select('id_jabatan', 'nama_jabatan', 'tunjangan_jabatan')
                ->get()->keyBy('id_jabatan');

        $namaLokasiById = empty($idLokasiList)
            ? collect()
            : DB::table('lokasi_kantor')->whereIn('id_lokasi', $idLokasiList)->pluck('nama_lokasi', 'id_lokasi');

        foreach ($records as $record) {
            $jabatan = $jabatanById[$record->id_jabatan] ?? null;
            $record->jabatan_nama = $jabatan->nama_jabatan ?? null;
            $record->jabatan_tunjangan = $jabatan !== null ? (float) $jabatan->tunjangan_jabatan : null;
            $record->lokasi_nama  = $namaLokasiById[$record->id_lokasi] ?? null;
        }
    }
}
