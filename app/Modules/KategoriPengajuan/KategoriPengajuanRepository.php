<?php

declare(strict_types=1);

namespace App\Modules\KategoriPengajuan;

use App\Modules\KategoriPengajuan\Contracts\KategoriPengajuanRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class KategoriPengajuanRepository implements KategoriPengajuanRepositoryInterface
{
    private const COLUMNS = [
        'id_kategori_pengajuan', 'id_perusahaan', 'kode', 'nama', 'sistem', 'aktif', 'urutan',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh',
    ];

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search = null, ?bool $aktif = null): LengthAwarePaginator
    {
        return DB::table('kategori_pengajuan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->when($search, fn ($q, $v) => $q->where(function ($sub) use ($v) {
                $sub->where('nama', 'like', '%' . $v . '%')->orWhere('kode', 'like', '%' . $v . '%');
            }))
            ->when($aktif !== null, fn ($q) => $q->where('aktif', $aktif ? 1 : 0))
            ->orderBy('urutan')
            ->orderBy('nama')
            ->paginate($limit, self::COLUMNS, 'page', $page);
    }

    public function semuaByPerusahaan(string $idPerusahaan, bool $hanyaAktif = false): Collection
    {
        return DB::table('kategori_pengajuan')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->when($hanyaAktif, fn ($q) => $q->where('aktif', 1))
            ->orderBy('urutan')
            ->orderBy('nama')
            ->get();
    }

    public function findById(string $id): ?object
    {
        return DB::table('kategori_pengajuan')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_kategori_pengajuan', $id)
            ->first();
    }

    public function findByKode(string $idPerusahaan, string $kode): ?object
    {
        return DB::table('kategori_pengajuan')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('kode', $kode)
            ->first();
    }

    public function findByNama(string $idPerusahaan, string $nama, ?string $kecualiId = null): ?object
    {
        return DB::table('kategori_pengajuan')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->whereRaw('LOWER(nama) = ?', [mb_strtolower(trim($nama))])
            ->when($kecualiId, fn ($q, $v) => $q->where('id_kategori_pengajuan', '!=', $v))
            ->first();
    }

    public function kodeTerpakai(string $idPerusahaan): array
    {
        // Termasuk baris terhapus: kode yang pernah dipakai pengajuan tidak boleh berpindah arti.
        return DB::table('kategori_pengajuan')
            ->where('id_perusahaan', $idPerusahaan)
            ->pluck('kode')
            ->map(fn ($kode) => (string) $kode)
            ->all();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_kategori_pengajuan');
        DB::table('kategori_pengajuan')->insert($data);

        return $this->findById($data['id_kategori_pengajuan']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('kategori_pengajuan')
            ->where('id_kategori_pengajuan', $record->id_kategori_pengajuan)
            ->update(RecordHelper::stampUpdate($data));

        return $this->findById($record->id_kategori_pengajuan);
    }

    public function delete(object $record): void
    {
        DB::table('kategori_pengajuan')
            ->where('id_kategori_pengajuan', $record->id_kategori_pengajuan)
            ->update(RecordHelper::stampDelete());
    }

    public function jumlahPemakaian(string $idPerusahaan, string $kode): int
    {
        return DB::table('pengajuan_pengeluaran')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('kategori', $kode)
            ->count();
    }
}
