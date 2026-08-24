<?php

declare(strict_types=1);

namespace App\Modules\Jabatan;

use App\Modules\Jabatan\Contracts\JabatanRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class JabatanRepository implements JabatanRepositoryInterface
{
    private const COLUMNS = [
        'id_jabatan', 'id_perusahaan', 'id_departemen', 'id_jabatan_induk', 'id_peran', 'kode_jabatan', 'nama_jabatan', 'is_supir', 'level',
        'tunjangan_jabatan', 'aktif',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idDepartemen = null, ?string $search = null): LengthAwarePaginator
    {
        return DB::table('jabatan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->when($search, fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('nama_jabatan', 'like', "%{$search}%")
                   ->orWhere('kode_jabatan', 'like', "%{$search}%");
            }))
            ->when($idDepartemen, fn ($q, $v) => $q->where('id_departemen', $v))
            ->orderBy('level')
            ->orderBy('nama_jabatan')
            ->paginate($limit, self::COLUMNS, 'page', $page);
    }

    public function findById(string $id): ?object
    {
        return DB::table('jabatan')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_jabatan', $id)
            ->first();
    }

    public function findAktifMilikPerusahaan(string $id, string $idPerusahaan): ?object
    {
        return DB::table('jabatan')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_jabatan', $id)
            ->where('id_perusahaan', $idPerusahaan)
            ->where('aktif', 1)
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_jabatan');
        DB::table('jabatan')->insert($data);
        return $this->findById($data['id_jabatan']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('jabatan')
            ->where('id_jabatan', $record->id_jabatan)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_jabatan);
    }

    public function delete(object $record): void
    {
        DB::table('jabatan')
            ->where('id_jabatan', $record->id_jabatan)
            ->update(RecordHelper::stampDelete());
    }

    public function strukturOrganisasi(string $idPerusahaan): array
    {
        $rows = DB::table('jabatan as j')
            ->leftJoin('departemen as d', function ($join) {
                $join->on('d.id_departemen', '=', 'j.id_departemen')->whereNull('d.dihapus_pada');
            })
            ->leftJoin('karyawan as k', function ($join) {
                $join->on('k.id_jabatan', '=', 'j.id_jabatan')->whereNull('k.dihapus_pada')->where('k.aktif', 1);
            })
            ->where('j.id_perusahaan', $idPerusahaan)
            ->where('j.aktif', 1)
            ->whereNull('j.dihapus_pada')
            ->orderBy('j.level')
            ->orderBy('j.nama_jabatan')
            ->select(
                'j.id_jabatan', 'j.nama_jabatan', 'j.id_jabatan_induk', 'j.id_departemen', 'd.nama_departemen',
                'k.id_karyawan', 'k.nama_karyawan',
            )
            ->get();

        $jabatanMap = [];
        foreach ($rows as $row) {
            if (!isset($jabatanMap[$row->id_jabatan])) {
                $jabatanMap[$row->id_jabatan] = (object) [
                    'id_jabatan'       => $row->id_jabatan,
                    'nama_jabatan'     => $row->nama_jabatan,
                    'id_jabatan_induk' => $row->id_jabatan_induk,
                    'id_departemen'    => $row->id_departemen,
                    'nama_departemen'  => $row->nama_departemen,
                    'karyawan'         => [],
                ];
            }
            if ($row->id_karyawan !== null) {
                $jabatanMap[$row->id_jabatan]->karyawan[] = [
                    'id_karyawan'   => $row->id_karyawan,
                    'nama_karyawan' => $row->nama_karyawan,
                ];
            }
        }

        // Induk yang tidak aktif/terhapus tidak boleh membuat cabangnya hilang
        // diam-diam — jabatan itu tampil sebagai root sendiri.
        foreach ($jabatanMap as $jabatan) {
            if ($jabatan->id_jabatan_induk !== null && !isset($jabatanMap[$jabatan->id_jabatan_induk])) {
                $jabatan->id_jabatan_induk = null;
            }
        }

        $pohon = $this->buildPohonJabatan(array_values($jabatanMap), null);
        $this->hapusIdJabatanIndukRekursif($pohon);

        return $pohon;
    }

    private function buildPohonJabatan(array $items, ?string $parentId): array
    {
        $anak = [];
        foreach ($items as $item) {
            if ($item->id_jabatan_induk === $parentId) {
                $item->jumlah_karyawan = count($item->karyawan);
                $item->children = $this->buildPohonJabatan($items, $item->id_jabatan);
                $anak[] = $item;
            }
        }
        return $anak;
    }

    /**
     * `id_jabatan_induk` hanya dipakai internal untuk pencocokan anak-induk
     * di buildPohonJabatan(); dibuang di sini (bukan saat pencocokan) supaya
     * tidak mengubah objek yang masih dipindai ulang oleh pemanggilan
     * rekursif lain terhadap $items yang sama (list penuh dipindai ulang di
     * setiap level, bukan list yang menyusut).
     */
    private function hapusIdJabatanIndukRekursif(array $nodes): void
    {
        foreach ($nodes as $node) {
            unset($node->id_jabatan_induk);
            $this->hapusIdJabatanIndukRekursif($node->children);
        }
    }
}
