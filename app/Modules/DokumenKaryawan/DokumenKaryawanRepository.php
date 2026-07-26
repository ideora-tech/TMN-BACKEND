<?php

declare(strict_types=1);

namespace App\Modules\DokumenKaryawan;

use App\Modules\DokumenKaryawan\Contracts\DokumenKaryawanRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class DokumenKaryawanRepository implements DokumenKaryawanRepositoryInterface
{
    private const COLUMNS = [
        'dokumen_karyawan.id_dokumen_karyawan', 'dokumen_karyawan.id_karyawan', 'dokumen_karyawan.jenis_dokumen',
        'dokumen_karyawan.nomor', 'dokumen_karyawan.berlaku_sampai', 'dokumen_karyawan.url_file',
        'dokumen_karyawan.dibuat_pada', 'dokumen_karyawan.dibuat_oleh',
        'dokumen_karyawan.diubah_pada', 'dokumen_karyawan.diubah_oleh',
        'dokumen_karyawan.dihapus_pada', 'dokumen_karyawan.dihapus_oleh',
    ];

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idKaryawan = null, ?string $jenisDokumen = null, ?string $search = null): LengthAwarePaginator
    {
        return DB::table('dokumen_karyawan')
            ->join('karyawan', 'karyawan.id_karyawan', '=', 'dokumen_karyawan.id_karyawan')
            ->where('karyawan.id_perusahaan', $idPerusahaan)
            ->whereNull('dokumen_karyawan.dihapus_pada')
            ->whereNull('karyawan.dihapus_pada')
            ->when($idKaryawan, fn ($q, $v) => $q->where('dokumen_karyawan.id_karyawan', $v))
            ->when($jenisDokumen, fn ($q, $v) => $q->where('dokumen_karyawan.jenis_dokumen', $v))
            ->when($search, fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('dokumen_karyawan.nomor', 'like', "%{$search}%")
                   ->orWhere('karyawan.nama_karyawan', 'like', "%{$search}%")
                   ->orWhere('karyawan.nik', 'like', "%{$search}%");
            }))
            ->orderByRaw('dokumen_karyawan.berlaku_sampai is null')
            ->orderBy('dokumen_karyawan.berlaku_sampai')
            ->select(array_merge(self::COLUMNS, ['karyawan.nama_karyawan as karyawan_nama', 'karyawan.nik as karyawan_nik']))
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findAllByKaryawan(string $idKaryawan): array
    {
        return DB::table('dokumen_karyawan')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_karyawan', $idKaryawan)
            ->orderBy('jenis_dokumen')
            ->orderByDesc('dibuat_pada')
            ->get()
            ->all();
    }

    public function findById(string $id): ?object
    {
        return DB::table('dokumen_karyawan')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_dokumen_karyawan', $id)
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_dokumen_karyawan');
        DB::table('dokumen_karyawan')->insert($data);
        return $this->findById($data['id_dokumen_karyawan']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('dokumen_karyawan')
            ->where('id_dokumen_karyawan', $record->id_dokumen_karyawan)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_dokumen_karyawan);
    }

    public function delete(object $record): void
    {
        DB::table('dokumen_karyawan')
            ->where('id_dokumen_karyawan', $record->id_dokumen_karyawan)
            ->update(RecordHelper::stampDelete());
    }
}
