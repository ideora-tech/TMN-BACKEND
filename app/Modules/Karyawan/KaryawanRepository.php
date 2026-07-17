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
        'id_karyawan', 'id_perusahaan', 'id_jabatan', 'id_lokasi', 'nik', 'nama_karyawan',
        'email', 'telepon', 'jenis_kelamin', 'tanggal_lahir', 'tanggal_masuk',
        'status_kepegawaian', 'gaji_pokok', 'aktif',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        $paginator = DB::table('karyawan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
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

    public function exitHistory(string $idKaryawan): array
    {
        return DB::table('karyawan_exit')
            ->where('id_karyawan', $idKaryawan)
            ->orderBy('tanggal_efektif', 'desc')
            ->get()
            ->all();
    }

    /**
     * Tempel nama jabatan & lokasi via raw query builder (join manual),
     * bukan Eloquent relationship.
     */
    private function attachJabatanLokasi(Collection $records): void
    {
        $idJabatanList = $records->pluck('id_jabatan')->filter()->unique()->values()->all();
        $idLokasiList  = $records->pluck('id_lokasi')->filter()->unique()->values()->all();

        $namaJabatanById = empty($idJabatanList)
            ? collect()
            : DB::table('jabatan')->whereIn('id_jabatan', $idJabatanList)->pluck('nama_jabatan', 'id_jabatan');

        $namaLokasiById = empty($idLokasiList)
            ? collect()
            : DB::table('lokasi_kantor')->whereIn('id_lokasi', $idLokasiList)->pluck('nama_lokasi', 'id_lokasi');

        foreach ($records as $record) {
            $record->jabatan_nama = $namaJabatanById[$record->id_jabatan] ?? null;
            $record->lokasi_nama  = $namaLokasiById[$record->id_lokasi] ?? null;
        }
    }
}
