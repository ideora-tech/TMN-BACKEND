<?php

declare(strict_types=1);

namespace App\Modules\Barang;

use App\Modules\Barang\Contracts\BarangRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class BarangRepository implements BarangRepositoryInterface
{
    private function base()
    {
        return DB::table('barang as b')
            ->leftJoin('kategori_barang as k', function ($join) {
                $join->on('k.id_kategori_barang', '=', 'b.id_kategori_barang')->whereNull('k.dihapus_pada');
            })
            ->whereNull('b.dihapus_pada')
            ->select(['b.*', 'k.nama as nama_kategori']);
    }

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search, ?string $idKategori, bool $stokMenipis): LengthAwarePaginator
    {
        $q = $this->base()->where('b.id_perusahaan', $idPerusahaan);
        if ($search !== null && $search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('b.nama', 'like', "%{$search}%")->orWhere('b.kode', 'like', "%{$search}%");
            });
        }
        if ($idKategori !== null && $idKategori !== '') {
            $q->where('b.id_kategori_barang', $idKategori);
        }
        if ($stokMenipis) {
            $q->where('b.stok_minimum', '>', 0)->whereColumn('b.stok', '<', 'b.stok_minimum');
        }
        return $q->orderBy('b.nama')->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?object
    {
        return $this->base()->where('b.id_barang', $id)->first();
    }

    public function findByIdForUpdate(string $id): ?object
    {
        return DB::table('barang')->whereNull('dihapus_pada')->where('id_barang', $id)->lockForUpdate()->first();
    }

    public function findByKode(string $idPerusahaan, string $kode): ?object
    {
        return DB::table('barang')->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)->where('kode', $kode)->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_barang');
        DB::table('barang')->insert($data);
        return $this->findById($data['id_barang']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('barang')->where('id_barang', $record->id_barang)->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_barang);
    }

    public function softDelete(object $record): void
    {
        DB::table('barang')->where('id_barang', $record->id_barang)->update(RecordHelper::stampDelete());
    }

    public function dipakaiRelasiLain(string $idBarang): bool
    {
        $adaMutasi = DB::table('barang_mutasi')->whereNull('dihapus_pada')->where('id_barang', $idBarang)->exists();
        $adaItem = DB::table('permintaan_pembelian_item')->whereNull('dihapus_pada')->where('id_barang', $idBarang)->exists();
        return $adaMutasi || $adaItem;
    }

    public function hitungMenipis(string $idPerusahaan): int
    {
        return DB::table('barang')->whereNull('dihapus_pada')->where('id_perusahaan', $idPerusahaan)
            ->where('stok_minimum', '>', 0)->whereColumn('stok', '<', 'stok_minimum')->count();
    }

    public function setStok(string $id, int $stokBaru): void
    {
        DB::table('barang')->where('id_barang', $id)->update(RecordHelper::stampUpdate(['stok' => $stokBaru]));
    }

    public function setHargaStandar(string $id, float $harga): void
    {
        DB::table('barang')->where('id_barang', $id)->update(RecordHelper::stampUpdate(['harga_standar' => $harga]));
    }

    public function insertMutasi(array $data): void
    {
        DB::table('barang_mutasi')->insert(RecordHelper::stampCreate($data, 'id_mutasi'));
    }

    public function paginateMutasi(string $idBarang, int $page, int $limit): LengthAwarePaginator
    {
        return DB::table('barang_mutasi as m')
            ->leftJoin('permintaan_pembelian as p', 'p.id_permintaan', '=', 'm.id_permintaan_pembelian')
            ->whereNull('m.dihapus_pada')
            ->where('m.id_barang', $idBarang)
            ->select(['m.*', 'p.nomor_permintaan'])
            ->orderByDesc('m.tanggal')->orderByDesc('m.dibuat_pada')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function listKategori(string $idPerusahaan, bool $hanyaAktif): array
    {
        $q = DB::table('kategori_barang')->whereNull('dihapus_pada')->where('id_perusahaan', $idPerusahaan);
        if ($hanyaAktif) {
            $q->where('aktif', 1);
        }
        return $q->orderBy('nama')->get()->all();
    }

    public function findKategori(string $id): ?object
    {
        return DB::table('kategori_barang')->whereNull('dihapus_pada')->where('id_kategori_barang', $id)->first();
    }

    public function findKategoriByNama(string $idPerusahaan, string $nama, ?string $excludeId = null): ?object
    {
        $q = DB::table('kategori_barang')->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)->whereRaw('LOWER(nama) = ?', [mb_strtolower($nama)]);
        if ($excludeId !== null) {
            $q->where('id_kategori_barang', '!=', $excludeId);
        }
        return $q->first();
    }

    public function createKategori(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_kategori_barang');
        DB::table('kategori_barang')->insert($data);
        return $this->findKategori($data['id_kategori_barang']);
    }

    public function updateKategori(object $record, array $data): object
    {
        DB::table('kategori_barang')->where('id_kategori_barang', $record->id_kategori_barang)->update(RecordHelper::stampUpdate($data));
        return $this->findKategori($record->id_kategori_barang);
    }

    public function softDeleteKategori(object $record): void
    {
        DB::table('kategori_barang')->where('id_kategori_barang', $record->id_kategori_barang)->update(RecordHelper::stampDelete());
    }

    public function kategoriDipakai(string $idKategori): bool
    {
        return DB::table('barang')->whereNull('dihapus_pada')->where('id_kategori_barang', $idKategori)->exists();
    }
}
