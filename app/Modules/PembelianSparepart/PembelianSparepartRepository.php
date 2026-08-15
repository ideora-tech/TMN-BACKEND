<?php
declare(strict_types=1);

namespace App\Modules\PembelianSparepart;

use App\Modules\PembelianSparepart\Contracts\PembelianSparepartRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PembelianSparepartRepository implements PembelianSparepartRepositoryInterface
{
    private function base()
    {
        return DB::table('pembelian_sparepart as p')
            ->leftJoin('supplier as s', 's.id_supplier', '=', 'p.id_supplier')
            ->leftJoin('perawatan_armada as pa', 'pa.id_perawatan', '=', 'p.id_perawatan')
            ->leftJoin('armada as a', 'a.id_armada', '=', 'pa.id_armada')
            ->whereNull('p.dihapus_pada')
            ->select('p.*', 's.nama as nama_supplier', 'a.nopol as nopol_armada');
    }

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, array $filter = []): LengthAwarePaginator
    {
        return $this->base()
            ->where('p.id_perusahaan', $idPerusahaan)
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('p.status', $v))
            ->when($filter['id_supplier'] ?? null, fn ($q, $v) => $q->where('p.id_supplier', $v))
            ->when($filter['dari'] ?? null, fn ($q, $v) => $q->where('p.tanggal_pengajuan', '>=', $v))
            ->when($filter['sampai'] ?? null, fn ($q, $v) => $q->where('p.tanggal_pengajuan', '<=', $v))
            ->when($filter['search'] ?? null, fn ($q, $v) => $q->where('p.nomor_pengajuan', 'like', "%{$v}%"))
            ->orderByDesc('p.tanggal_pengajuan')->orderByDesc('p.nomor_pengajuan')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?object
    {
        return $this->base()->where('p.id_pembelian', $id)->first();
    }

    public function listItems(string $idPembelian): array
    {
        return DB::table('pembelian_sparepart_item')
            ->whereNull('dihapus_pada')
            ->where('id_pembelian', $idPembelian)
            ->orderBy('dibuat_pada')
            ->get()->all();
    }

    public function listBukti(string $idPembelian): array
    {
        return DB::table('pembelian_sparepart_bukti')
            ->whereNull('dihapus_pada')
            ->where('id_pembelian', $idPembelian)
            ->orderBy('dibuat_pada')
            ->get(['id_bukti', 'url_file', 'nama_asli'])->all();
    }

    public function nomorBerikutnya(string $idPerusahaan): string
    {
        $prefix = 'PS-' . now()->format('Ym') . '-';
        $terakhir = DB::table('pembelian_sparepart')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('nomor_pengajuan', 'like', $prefix . '%')
            ->lockForUpdate()
            ->max('nomor_pengajuan');
        $urut = $terakhir ? ((int) substr($terakhir, -4)) + 1 : 1;
        return $prefix . str_pad((string) $urut, 4, '0', STR_PAD_LEFT);
    }

    public function createWithItems(array $header, array $items): object
    {
        $header = RecordHelper::stampCreate($header, 'id_pembelian');
        DB::table('pembelian_sparepart')->insert($header);
        $this->insertItems($header['id_pembelian'], $items);
        return $this->findById($header['id_pembelian']);
    }

    public function updateWithItems(object $record, array $header, array $items): object
    {
        DB::table('pembelian_sparepart')->where('id_pembelian', $record->id_pembelian)
            ->update(RecordHelper::stampUpdate($header));
        DB::table('pembelian_sparepart_item')->where('id_pembelian', $record->id_pembelian)->delete();
        $this->insertItems($record->id_pembelian, $items);
        return $this->findById($record->id_pembelian);
    }

    private function insertItems(string $idPembelian, array $items): void
    {
        foreach ($items as $item) {
            DB::table('pembelian_sparepart_item')->insert(RecordHelper::stampCreate(
                array_merge($item, ['id_pembelian' => $idPembelian]),
                'id_item'
            ));
        }
    }

    public function updateHeader(object $record, array $data): object
    {
        DB::table('pembelian_sparepart')->where('id_pembelian', $record->id_pembelian)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_pembelian);
    }

    public function softDelete(object $record): void
    {
        DB::table('pembelian_sparepart')->where('id_pembelian', $record->id_pembelian)
            ->update(RecordHelper::stampDelete());
    }

    public function sparepartMilik(string $idPerusahaan, array $ids): array
    {
        return DB::table('sparepart')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->whereIn('id_sparepart', $ids)
            ->get(['id_sparepart', 'nama', 'harga_standar'])
            ->keyBy('id_sparepart')->all();
    }

    public function supplierMilik(string $idPerusahaan, string $idSupplier): bool
    {
        return DB::table('supplier')->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)->where('id_supplier', $idSupplier)->exists();
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

    public function insertBukti(array $data): void
    {
        DB::table('pembelian_sparepart_bukti')->insert(RecordHelper::stampCreate($data, 'id_bukti'));
    }

    public function findBukti(string $idPembelian, string $idBukti): ?object
    {
        return DB::table('pembelian_sparepart_bukti')
            ->whereNull('dihapus_pada')
            ->where('id_pembelian', $idPembelian)
            ->where('id_bukti', $idBukti)->first();
    }

    public function softDeleteBukti(string $idBukti): void
    {
        DB::table('pembelian_sparepart_bukti')->where('id_bukti', $idBukti)
            ->update(RecordHelper::stampDelete());
    }

    public function gantiHargaAktualItems(string $idPembelian, array $hargaPerItem): void
    {
        foreach ($hargaPerItem as $idItem => $harga) {
            DB::table('pembelian_sparepart_item')
                ->where('id_pembelian', $idPembelian)->where('id_item', $idItem)
                ->update(RecordHelper::stampUpdate(['harga_aktual' => $harga]));
        }
    }

    public function tambahStokDanMutasi(object $header, array $items): void
    {
        foreach ($items as $item) {
            $sparepart = DB::table('sparepart')->where('id_sparepart', $item->id_sparepart)
                ->lockForUpdate()->first();
            if ($sparepart === null) {
                continue;
            }
            DB::table('sparepart')->where('id_sparepart', $item->id_sparepart)
                ->update(['stok' => (int) $sparepart->stok + (int) $item->qty]);
            DB::table('sparepart_mutasi')->insert(RecordHelper::stampCreate([
                'id_sparepart' => $item->id_sparepart,
                'jenis'        => 'masuk',
                'qty'          => (int) $item->qty,
                'harga'        => $item->harga_aktual,
                'id_perawatan' => $header->id_perawatan,
                'id_pembelian' => $header->id_pembelian,
                'keterangan'   => 'Pembelian ' . $header->nomor_pengajuan,
                'tanggal'      => $header->tanggal_pembelian,
            ], 'id_mutasi'));
        }
    }

    public function laporan(string $idPerusahaan, ?string $dari, ?string $sampai): array
    {
        $base = fn () => DB::table('pembelian_sparepart as p')
            ->whereNull('p.dihapus_pada')
            ->where('p.id_perusahaan', $idPerusahaan)
            ->whereIn('p.status', ['dibeli', 'lunas'])
            ->when($dari, fn ($q, $v) => $q->where('p.tanggal_pembelian', '>=', $v))
            ->when($sampai, fn ($q, $v) => $q->where('p.tanggal_pembelian', '<=', $v));

        $bulanExpr = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', p.tanggal_pembelian)"
            : "DATE_FORMAT(p.tanggal_pembelian, '%Y-%m')";
        $perBulan = $base()
            ->selectRaw("{$bulanExpr} as bulan, SUM(p.total_estimasi) as total_estimasi, SUM(p.total_aktual) as total_aktual, COUNT(*) as jumlah")
            ->groupBy('bulan')->orderBy('bulan')->get()->all();

        $perKategori = $base()
            ->join('pembelian_sparepart_item as i', function ($j) {
                $j->on('i.id_pembelian', '=', 'p.id_pembelian')->whereNull('i.dihapus_pada');
            })
            ->join('sparepart as sp', 'sp.id_sparepart', '=', 'i.id_sparepart')
            ->leftJoin('kategori_sparepart as k', 'k.id_kategori_sparepart', '=', 'sp.id_kategori_sparepart')
            ->selectRaw("COALESCE(k.nama, 'Tanpa Kategori') as kategori, SUM(i.qty * i.harga_aktual) as total_aktual")
            ->groupBy('kategori')->orderByDesc('total_aktual')->get()->all();

        $perArmada = $base()
            ->whereNotNull('p.id_perawatan')
            ->join('perawatan_armada as pa', 'pa.id_perawatan', '=', 'p.id_perawatan')
            ->join('armada as a', 'a.id_armada', '=', 'pa.id_armada')
            ->selectRaw('a.nopol, SUM(p.total_aktual) as total_aktual, COUNT(*) as jumlah')
            ->groupBy('a.nopol')->orderByDesc('total_aktual')->get()->all();

        $ringkasan = $base()
            ->selectRaw('COALESCE(SUM(p.total_estimasi),0) as total_estimasi, COALESCE(SUM(p.total_aktual),0) as total_aktual, COUNT(*) as jumlah')
            ->first();

        return [
            'ringkasan'   => [
                'total_estimasi' => (float) $ringkasan->total_estimasi,
                'total_aktual'   => (float) $ringkasan->total_aktual,
                'selisih'        => (float) $ringkasan->total_aktual - (float) $ringkasan->total_estimasi,
                'jumlah'         => (int) $ringkasan->jumlah,
            ],
            'per_bulan'   => $perBulan,
            'per_kategori' => $perKategori,
            'per_armada'  => $perArmada,
        ];
    }

    public function getPerusahaan(string $idPerusahaan): ?object
    {
        return DB::table('perusahaan')->where('id_perusahaan', $idPerusahaan)->first();
    }

    public function dataPembayaranPengajuan(string $idPembelian): ?object
    {
        return DB::table('pengajuan_pengeluaran')
            ->whereNull('dihapus_pada')
            ->where('id_pembelian', $idPembelian)
            ->where('status', 'ditransfer')
            ->select(['nominal as nominal_ditransfer', 'tanggal_transfer', 'url_bukti'])
            ->first();
    }
}
