<?php

declare(strict_types=1);

namespace App\Modules\PermintaanPembelian;

use App\Modules\PermintaanPembelian\Contracts\PermintaanPembelianRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PermintaanPembelianRepository implements PermintaanPembelianRepositoryInterface
{
    private function base()
    {
        return DB::table('permintaan_pembelian as p')
            ->leftJoin('supplier as s', function ($j) {
                $j->on('s.id_supplier', '=', 'p.id_supplier')->whereNull('s.dihapus_pada');
            })
            ->leftJoin('departemen as d', function ($j) {
                $j->on('d.id_departemen', '=', 'p.id_departemen')->whereNull('d.dihapus_pada');
            })
            ->leftJoin('pengguna as u', 'u.id_pengguna', '=', 'p.id_pengaju')
            ->leftJoin('perawatan_armada as pa', function ($j) {
                $j->on('pa.id_perawatan', '=', 'p.id_perawatan')->whereNull('pa.dihapus_pada');
            })
            ->leftJoin('armada as ar', 'ar.id_armada', '=', 'pa.id_armada')
            ->whereNull('p.dihapus_pada')
            ->select([
                'p.*', 's.nama as nama_supplier', 'd.nama_departemen', 'u.username as username_pengaju',
                'ar.nopol as nopol_perawatan', 'pa.tanggal as tanggal_perawatan',
            ]);
    }

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, array $filter): LengthAwarePaginator
    {
        $q = $this->base()->where('p.id_perusahaan', $idPerusahaan);
        if (($filter['status'] ?? '') !== '') {
            $q->where('p.status', $filter['status']);
        }
        if (($filter['tipe'] ?? '') !== '') {
            $q->where('p.tipe', $filter['tipe']);
        }
        if (($filter['id_departemen'] ?? '') !== '') {
            $q->where('p.id_departemen', $filter['id_departemen']);
        }
        if (($filter['id_pengaju'] ?? '') !== '') {
            $q->where('p.id_pengaju', $filter['id_pengaju']);
        }
        if (($filter['dari'] ?? '') !== '') {
            $q->where('p.tanggal_permintaan', '>=', $filter['dari']);
        }
        if (($filter['sampai'] ?? '') !== '') {
            $q->where('p.tanggal_permintaan', '<=', $filter['sampai']);
        }
        if (($filter['search'] ?? '') !== '') {
            $s = $filter['search'];
            $q->where(function ($w) use ($s) {
                $w->where('p.nomor_permintaan', 'like', "%{$s}%")->orWhere('p.judul', 'like', "%{$s}%");
            });
        }
        return $q->orderByDesc('p.tanggal_permintaan')->orderByDesc('p.nomor_permintaan')->paginate($limit, ['*'], 'page', $page);
    }

    public function ringkasanStatus(string $idPerusahaan): array
    {
        return DB::table('permintaan_pembelian')->whereNull('dihapus_pada')->where('id_perusahaan', $idPerusahaan)
            ->select('status', DB::raw('COUNT(*) as jumlah'))->groupBy('status')->pluck('jumlah', 'status')
            ->map(fn ($v) => (int) $v)->all();
    }

    public function listMenungguDiproses(string $idPerusahaan, int $limit): array
    {
        return $this->base()
            ->where('p.id_perusahaan', $idPerusahaan)
            ->whereIn('p.status', ['disetujui', 'diproses'])
            ->orderBy('p.tanggal_permintaan')
            ->orderBy('p.dibuat_pada')
            ->limit($limit)
            ->get()
            ->all();
    }

    public function findById(string $id): ?object
    {
        return $this->base()->where('p.id_permintaan', $id)->first();
    }

    public function findByIdForUpdate(string $id): ?object
    {
        return DB::table('permintaan_pembelian')->whereNull('dihapus_pada')->where('id_permintaan', $id)->lockForUpdate()->first();
    }

    public function createWithItems(array $header, array $items): object
    {
        $header = RecordHelper::stampCreate($header, 'id_permintaan');
        DB::table('permintaan_pembelian')->insert($header);
        foreach ($items as $item) {
            $item['id_permintaan'] = $header['id_permintaan'];
            DB::table('permintaan_pembelian_item')->insert(RecordHelper::stampCreate($item, 'id_item'));
        }
        return $this->findById($header['id_permintaan']);
    }

    public function updateWithItems(object $record, array $header, array $items): void
    {
        DB::table('permintaan_pembelian')->where('id_permintaan', $record->id_permintaan)->update(RecordHelper::stampUpdate($header));
        DB::table('permintaan_pembelian_item')->where('id_permintaan', $record->id_permintaan)->whereNull('dihapus_pada')->update(RecordHelper::stampDelete());
        foreach ($items as $item) {
            $item['id_permintaan'] = $record->id_permintaan;
            DB::table('permintaan_pembelian_item')->insert(RecordHelper::stampCreate($item, 'id_item'));
        }
    }

    public function updateHeader(object $record, array $data): void
    {
        DB::table('permintaan_pembelian')->where('id_permintaan', $record->id_permintaan)->update(RecordHelper::stampUpdate($data));
    }

    public function listItems(string $idPermintaan): array
    {
        $items = DB::table('permintaan_pembelian_item as i')
            ->leftJoin('barang as b', function ($j) {
                $j->on('b.id_barang', '=', 'i.id_barang')->whereNull('b.dihapus_pada');
            })
            ->leftJoin('sparepart as sp', function ($j) {
                $j->on('sp.id_sparepart', '=', 'i.id_sparepart')->whereNull('sp.dihapus_pada');
            })
            ->leftJoin('jenis_kendaraan as jk', function ($j) {
                $j->on('jk.id_jenis_kendaraan', '=', 'i.id_jenis_kendaraan')->whereNull('jk.dihapus_pada');
            })
            ->whereNull('i.dihapus_pada')
            ->where('i.id_permintaan', $idPermintaan)
            ->select([
                'i.*',
                'b.kode as kode_barang', 'b.nama as nama_barang', 'b.stok as stok_barang',
                'sp.kode as kode_sparepart', 'sp.nama as nama_sparepart', 'sp.stok as stok_sparepart',
                'jk.nama_jenis as nama_jenis_kendaraan',
            ])
            ->orderBy('i.dibuat_pada')
            ->get()->all();

        $idItems = array_map(fn ($i) => (string) $i->id_item, $items);
        $armadaPerItem = [];
        if ($idItems !== []) {
            $rows = DB::table('armada')->whereNull('dihapus_pada')
                ->whereIn('id_permintaan_pembelian_item', $idItems)
                ->orderBy('dibuat_pada')
                ->get(['id_armada', 'nopol', 'id_permintaan_pembelian_item']);
            foreach ($rows as $row) {
                $armadaPerItem[(string) $row->id_permintaan_pembelian_item][] = [
                    'id_armada' => (string) $row->id_armada,
                    'nopol'     => (string) $row->nopol,
                ];
            }
        }
        foreach ($items as $item) {
            $item->armada_terdaftar = $armadaPerItem[(string) $item->id_item] ?? [];
        }
        return $items;
    }

    public function updateItem(string $idItem, array $data): void
    {
        DB::table('permintaan_pembelian_item')->where('id_item', $idItem)->update(RecordHelper::stampUpdate($data));
    }

    public function jenisKendaraanMilik(string $idPerusahaan, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        return DB::table('jenis_kendaraan')->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)->whereIn('id_jenis_kendaraan', $ids)
            ->get(['id_jenis_kendaraan', 'nama_jenis'])
            ->keyBy('id_jenis_kendaraan')->all();
    }

    public function insertTermin(array $data): string
    {
        $data = RecordHelper::stampCreate($data, 'id_termin');
        DB::table('permintaan_pembelian_termin')->insert($data);
        return (string) $data['id_termin'];
    }

    public function listTermin(string $idPermintaan): array
    {
        return DB::table('permintaan_pembelian_termin')->whereNull('dihapus_pada')
            ->where('id_permintaan', $idPermintaan)->orderBy('urutan')->get()->all();
    }

    public function setPengajuanTermin(string $idTermin, string $idPengajuan): void
    {
        DB::table('permintaan_pembelian_termin')->where('id_termin', $idTermin)
            ->update(RecordHelper::stampUpdate(['id_pengajuan' => $idPengajuan]));
    }

    public function adaTerminMenunggu(string $idPermintaan): bool
    {
        return DB::table('permintaan_pembelian_termin')->whereNull('dihapus_pada')
            ->where('id_permintaan', $idPermintaan)->where('status', 'menunggu')->exists();
    }

    public function findItemById(string $idItem): ?object
    {
        return DB::table('permintaan_pembelian_item as i')
            ->join('permintaan_pembelian as p', 'p.id_permintaan', '=', 'i.id_permintaan')
            ->whereNull('i.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->where('i.id_item', $idItem)
            ->select(['i.*', 'p.id_perusahaan', 'p.nomor_permintaan', 'p.tipe', 'p.status as status_permintaan'])
            ->first();
    }

    public function tambahQtyDiterima(string $idItem): bool
    {
        return DB::table('permintaan_pembelian_item')
            ->whereNull('dihapus_pada')
            ->where('id_item', $idItem)
            ->whereRaw('COALESCE(qty_diterima, 0) < qty')
            ->update(RecordHelper::stampUpdate(['qty_diterima' => DB::raw('COALESCE(qty_diterima, 0) + 1')])) > 0;
    }

    public function kurangiQtyDiterima(string $idItem): void
    {
        DB::table('permintaan_pembelian_item')
            ->whereNull('dihapus_pada')
            ->where('id_item', $idItem)
            ->where('qty_diterima', '>', 0)
            ->update(RecordHelper::stampUpdate(['qty_diterima' => DB::raw('qty_diterima - 1')]));
    }

    public function semuaItemLengkap(string $idPermintaan): bool
    {
        return !DB::table('permintaan_pembelian_item')
            ->whereNull('dihapus_pada')
            ->where('id_permintaan', $idPermintaan)
            ->whereRaw('COALESCE(qty_diterima, 0) < qty')
            ->exists();
    }

    public function tanggalTransferTerminTerakhir(string $idPermintaan): ?string
    {
        $tanggal = DB::table('permintaan_pembelian_termin')
            ->whereNull('dihapus_pada')
            ->where('id_permintaan', $idPermintaan)
            ->where('status', 'ditransfer')
            ->max('tanggal_transfer');
        return $tanggal !== null ? (string) $tanggal : null;
    }

    public function softDelete(object $record): void
    {
        DB::table('permintaan_pembelian')->where('id_permintaan', $record->id_permintaan)->update(RecordHelper::stampDelete());
    }

    public function insertBukti(array $data): void
    {
        DB::table('permintaan_pembelian_bukti')->insert(RecordHelper::stampCreate($data, 'id_bukti'));
    }

    public function listBukti(string $idPermintaan): array
    {
        return DB::table('permintaan_pembelian_bukti')->whereNull('dihapus_pada')
            ->where('id_permintaan', $idPermintaan)->orderBy('dibuat_pada')->get()->all();
    }

    public function listBuktiMentah(string $idPermintaan): array
    {
        return array_map(fn ($b) => [
            'url_file'  => (string) $b->url_file,
            'nama_asli' => (string) $b->nama_asli,
        ], $this->listBukti($idPermintaan));
    }

    public function findBukti(string $idPermintaan, string $idBukti): ?object
    {
        return DB::table('permintaan_pembelian_bukti')->whereNull('dihapus_pada')
            ->where('id_permintaan', $idPermintaan)->where('id_bukti', $idBukti)->first();
    }

    public function softDeleteBukti(string $idBukti): void
    {
        DB::table('permintaan_pembelian_bukti')->where('id_bukti', $idBukti)->update(RecordHelper::stampDelete());
    }

    public function supplierMilik(string $idPerusahaan, string $idSupplier): ?object
    {
        return DB::table('supplier')->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)->where('id_supplier', $idSupplier)->first();
    }

    public function departemenMilik(string $idPerusahaan, string $idDepartemen): bool
    {
        return DB::table('departemen')->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)->where('id_departemen', $idDepartemen)->exists();
    }

    public function perawatanMilik(string $idPerusahaan, string $idPerawatan): bool
    {
        return DB::table('perawatan_armada as pa')
            ->join('armada as a', 'a.id_armada', '=', 'pa.id_armada')
            ->whereNull('pa.dihapus_pada')
            ->whereNull('a.dihapus_pada')
            ->where('a.id_perusahaan', $idPerusahaan)
            ->where('pa.id_perawatan', $idPerawatan)->exists();
    }

    public function sparepartMilik(string $idPerusahaan, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        return DB::table('sparepart')->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)->whereIn('id_sparepart', $ids)
            ->get(['id_sparepart', 'kode', 'nama', 'satuan', 'harga_standar'])
            ->keyBy('id_sparepart')->all();
    }

    public function pembelianSparepartDariPermintaan(string $idPermintaan): ?object
    {
        return DB::table('pembelian_sparepart')->whereNull('dihapus_pada')
            ->where('id_permintaan_pembelian', $idPermintaan)
            ->orderByDesc('dibuat_pada')
            ->first(['id_pembelian', 'nomor_pengajuan', 'status']);
    }

    public function getPerusahaan(string $idPerusahaan): ?object
    {
        return DB::table('perusahaan')->where('id_perusahaan', $idPerusahaan)->first();
    }

    private function baseLaporan()
    {
        return DB::table('permintaan_pembelian as p')
            ->leftJoin('supplier as s', function ($j) {
                $j->on('s.id_supplier', '=', 'p.id_supplier')->whereNull('s.dihapus_pada');
            })
            ->leftJoin('departemen as d', function ($j) {
                $j->on('d.id_departemen', '=', 'p.id_departemen')->whereNull('d.dihapus_pada');
            })
            ->leftJoin('pengguna as u', 'u.id_pengguna', '=', 'p.id_pengaju')
            ->leftJoin('perawatan_armada as pa', 'pa.id_perawatan', '=', 'p.id_perawatan')
            ->leftJoin('armada as ar', 'ar.id_armada', '=', 'pa.id_armada')
            ->whereNull('p.dihapus_pada')
            ->select([
                'p.*', 's.nama as nama_supplier', 'd.nama_departemen', 'u.username as username_pengaju',
                'ar.nopol as nopol_perawatan', 'pa.tanggal as tanggal_perawatan',
            ]);
    }

    public function laporanPermintaan(string $idPerusahaan, ?string $dari, ?string $sampai, ?string $tipe): array
    {
        return $this->baseLaporan()
            ->where('p.id_perusahaan', $idPerusahaan)
            ->whereIn('p.status', ['dibeli', 'diterima', 'selesai'])
            ->whereNotNull('p.total_aktual')
            ->when($tipe, fn ($q, $v) => $q->where('p.tipe', $v))
            ->when($dari, fn ($q, $v) => $q->where('p.tanggal_pembelian', '>=', $v))
            ->when($sampai, fn ($q, $v) => $q->where('p.tanggal_pembelian', '<=', $v))
            ->get()->all();
    }

    public function itemsUntukLaporan(array $idPermintaanList): array
    {
        if ($idPermintaanList === []) {
            return [];
        }
        return DB::table('permintaan_pembelian_item as i')
            ->leftJoin('barang as b', function ($j) {
                $j->on('b.id_barang', '=', 'i.id_barang')->whereNull('b.dihapus_pada');
            })
            ->leftJoin('kategori_barang as kb', 'kb.id_kategori_barang', '=', 'b.id_kategori_barang')
            ->leftJoin('sparepart as sp', function ($j) {
                $j->on('sp.id_sparepart', '=', 'i.id_sparepart')->whereNull('sp.dihapus_pada');
            })
            ->leftJoin('kategori_sparepart as ks', 'ks.id_kategori_sparepart', '=', 'sp.id_kategori_sparepart')
            ->leftJoin('jenis_kendaraan as jk', function ($j) {
                $j->on('jk.id_jenis_kendaraan', '=', 'i.id_jenis_kendaraan')->whereNull('jk.dihapus_pada');
            })
            ->whereNull('i.dihapus_pada')
            ->whereIn('i.id_permintaan', $idPermintaanList)
            ->select([
                'i.id_permintaan', 'i.jenis', 'i.qty', 'i.qty_diterima', 'i.harga_aktual',
                'kb.nama as kategori_barang', 'ks.nama as kategori_sparepart', 'jk.nama_jenis as nama_jenis_kendaraan',
            ])
            ->get()->all();
    }

    public function terminDitransferUntukPermintaan(array $idPermintaanList): array
    {
        if ($idPermintaanList === []) {
            return [];
        }
        return DB::table('permintaan_pembelian_termin')
            ->whereNull('dihapus_pada')
            ->whereIn('id_permintaan', $idPermintaanList)
            ->where('status', 'ditransfer')
            ->select(['id_permintaan', 'nominal'])
            ->get()->all();
    }

    public function menungguDiprosesLaporan(string $idPerusahaan, ?string $tipe): array
    {
        return $this->base()
            ->where('p.id_perusahaan', $idPerusahaan)
            ->whereIn('p.status', ['disetujui', 'diproses'])
            ->when($tipe, fn ($q, $v) => $q->where('p.tipe', $v))
            ->get()->all();
    }
}
