<?php

declare(strict_types=1);

namespace App\Modules\ArusKas;

use App\Modules\ArusKas\Contracts\ArusKasRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ArusKasRepository implements ArusKasRepositoryInterface
{
    public function listPengajuanByPerusahaan(string $idPerusahaan, ?string $status = null): Collection
    {
        return PengajuanPengeluaranModel::active()
            ->select('pengajuan_pengeluaran.*')
            ->addSelect(['id_armada_perawatan' => DB::table('perawatan_armada')
                ->select('id_armada')
                ->whereColumn('perawatan_armada.id_perawatan', 'pengajuan_pengeluaran.id_perawatan')
                ->limit(1)])
            ->where('id_perusahaan', $idPerusahaan)
            ->when($status, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('tanggal_pengajuan')
            ->orderByDesc('nomor_pengajuan')
            ->get();
    }

    public function findPengajuanById(string $id): ?PengajuanPengeluaranModel
    {
        return PengajuanPengeluaranModel::active()->find($id);
    }

    public function listMenungguApprovalSaya(string $idPerusahaan, string $idPengguna): Collection
    {
        return PengajuanPengeluaranModel::active()
            ->select('pengajuan_pengeluaran.*')
            ->addSelect(['id_armada_perawatan' => DB::table('perawatan_armada')
                ->select('id_armada')
                ->whereColumn('perawatan_armada.id_perawatan', 'pengajuan_pengeluaran.id_perawatan')
                ->limit(1)])
            ->where('id_perusahaan', $idPerusahaan)
            ->where('status', 'menunggu_approval')
            ->whereExists(fn ($q) => $q->from('pengajuan_approval as pa')
                ->whereColumn('pa.id_pengajuan', 'pengajuan_pengeluaran.id_pengajuan')
                ->where('pa.id_pengguna', $idPengguna)
                ->where('pa.status', 'menunggu')
                ->whereNull('pa.dihapus_pada'))
            ->orderBy('dibuat_pada')
            ->get();
    }

    public function createPengajuan(array $data): PengajuanPengeluaranModel
    {
        return PengajuanPengeluaranModel::create($data);
    }

    public function updatePengajuan(PengajuanPengeluaranModel $model, array $data): PengajuanPengeluaranModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function deletePengajuan(PengajuanPengeluaranModel $model): void
    {
        $model->softDelete();
    }

    public function nomorPengajuanBerikutnya(string $idPerusahaan): string
    {
        $prefix = 'PP-' . now()->format('Ym') . '-';
        $terakhir = PengajuanPengeluaranModel::where('id_perusahaan', $idPerusahaan)
            ->where('nomor_pengajuan', 'like', $prefix . '%')
            ->lockForUpdate()
            ->max('nomor_pengajuan');
        $urut = $terakhir ? ((int) substr($terakhir, -4)) + 1 : 1;
        return $prefix . str_pad((string) $urut, 4, '0', STR_PAD_LEFT);
    }

    public function findPemasukanById(string $id): ?PemasukanModel
    {
        return PemasukanModel::active()->find($id);
    }

    public function createPemasukan(array $data): PemasukanModel
    {
        return PemasukanModel::create($data);
    }

    public function updatePemasukan(PemasukanModel $model, array $data): PemasukanModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function deletePemasukan(PemasukanModel $model): void
    {
        $model->softDelete();
    }

    public function nomorPemasukanBerikutnya(string $idPerusahaan): string
    {
        $prefix = 'PM-' . now()->format('Ym') . '-';
        $terakhir = PemasukanModel::where('id_perusahaan', $idPerusahaan)
            ->where('nomor_pemasukan', 'like', $prefix . '%')
            ->lockForUpdate()
            ->max('nomor_pemasukan');
        $urut = $terakhir ? ((int) substr($terakhir, -4)) + 1 : 1;
        return $prefix . str_pad((string) $urut, 4, '0', STR_PAD_LEFT);
    }

    public function findPengajuanByTrip(string $idTrip): ?PengajuanPengeluaranModel
    {
        return PengajuanPengeluaranModel::active()->where('id_trip', $idTrip)->first();
    }

    public function namaPengguna(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('pengguna')
            ->whereIn('id_pengguna', $ids)
            ->pluck('username', 'id_pengguna')
            ->all();
    }

    public function dataTripUntukPengajuan(string $idTrip): ?object
    {
        $label = DB::connection()->getDriverName() === 'sqlite'
            ? "COALESCE(r.nama_rute, jk.rute, 'Trip') || CASE WHEN COALESCE(a.nopol, av.nopol) IS NOT NULL THEN ' - ' || COALESCE(a.nopol, av.nopol) ELSE '' END"
            : "CONCAT(COALESCE(r.nama_rute, jk.rute, 'Trip'), IF(COALESCE(a.nopol, av.nopol) IS NOT NULL, CONCAT(' - ', COALESCE(a.nopol, av.nopol)), ''))";

        return DB::table('trip as t')
            ->join('jadwal_keberangkatan as jk', 't.id_jadwal', '=', 'jk.id_jadwal')
            ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
            ->join('proyek as pr', 'p.id_proyek', '=', 'pr.id_proyek')
            ->leftJoin('rute as r', 'jk.id_rute', '=', 'r.id_rute')
            ->leftJoin('supir as s', 'p.id_supir', '=', 's.id_supir')
            ->leftJoin('supir_vendor as sv', 'p.id_supir_vendor', '=', 'sv.id_supir_vendor')
            ->leftJoin('armada as a', 'p.id_armada', '=', 'a.id_armada')
            ->leftJoin('armada_vendor as av', 'p.id_armada_vendor', '=', 'av.id_armada_vendor')
            ->where('t.id_trip', $idTrip)
            ->whereNull('t.dihapus_pada')
            ->whereNull('jk.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->whereNull('pr.dihapus_pada')
            ->selectRaw("
                pr.id_perusahaan as id_perusahaan,
                COALESCE(s.nama, sv.nama) as penerima,
                {$label} as keterangan
            ")
            ->first();
    }

    public function findPengajuanByPerawatan(string $idPerawatan): ?PengajuanPengeluaranModel
    {
        return PengajuanPengeluaranModel::active()->where('id_perawatan', $idPerawatan)->first();
    }

    public function dataPerawatanUntukPengajuan(string $idPerawatan): ?object
    {
        return DB::table('perawatan_armada as p')
            ->join('armada as a', 'a.id_armada', '=', 'p.id_armada')
            ->where('p.id_perawatan', $idPerawatan)
            ->whereNull('p.dihapus_pada')
            ->whereNull('a.dihapus_pada')
            ->select([
                'a.id_perusahaan as id_perusahaan',
                'a.nopol as nopol',
                'p.jenis_perawatan as jenis_perawatan',
            ])
            ->first();
    }

    public function findPengajuanByPembelian(string $idPembelian): ?PengajuanPengeluaranModel
    {
        return PengajuanPengeluaranModel::active()->where('id_pembelian', $idPembelian)->first();
    }

    public function dataPembelianUntukPengajuan(string $idPembelian): ?object
    {
        return DB::table('pembelian_sparepart as p')
            ->join('supplier as s', 's.id_supplier', '=', 'p.id_supplier')
            ->where('p.id_pembelian', $idPembelian)
            ->whereNull('p.dihapus_pada')
            ->whereNull('s.dihapus_pada')
            ->select([
                'p.id_perusahaan as id_perusahaan',
                'p.id_perawatan as id_perawatan',
                'p.nomor_pengajuan as nomor_ps',
                's.nama as nama_supplier',
            ])
            ->first();
    }

    public function statusPembelian(string $idPembelian): ?string
    {
        $status = DB::table('pembelian_sparepart')
            ->whereNull('dihapus_pada')
            ->where('id_pembelian', $idPembelian)
            ->value('status');
        return $status !== null ? (string) $status : null;
    }

    public function sinkronPembelianSetujui(string $idPembelian): void
    {
        DB::table('pembelian_sparepart')
            ->whereNull('dihapus_pada')
            ->where('id_pembelian', $idPembelian)
            ->whereNotIn('status', ['dibeli', 'lunas'])
            ->update(RecordHelper::stampUpdate([
                'status'                 => 'disetujui_finance',
                'disetujui_manager_oleh' => auth()->id(),
                'disetujui_manager_pada' => now(),
                'disetujui_finance_oleh' => auth()->id(),
                'disetujui_finance_pada' => now(),
            ]));
    }

    public function sinkronPembelianTolak(string $idPembelian, string $alasan): void
    {
        DB::table('pembelian_sparepart')
            ->whereNull('dihapus_pada')
            ->where('id_pembelian', $idPembelian)
            ->whereNotIn('status', ['dibeli', 'lunas'])
            ->update(RecordHelper::stampUpdate([
                'status'         => 'ditolak',
                'alasan_ditolak' => $alasan,
            ]));
    }

    public function sinkronPembelianLunas(string $idPembelian, string $tanggalPembayaran): void
    {
        DB::table('pembelian_sparepart')
            ->whereNull('dihapus_pada')
            ->where('id_pembelian', $idPembelian)
            ->update(RecordHelper::stampUpdate([
                'status'             => 'lunas',
                'tanggal_pembayaran' => $tanggalPembayaran,
            ]));
    }

    public function sinkronPembelianUangMuka(string $idPembelian, string $tanggalTransfer): void
    {
        DB::table('pembelian_sparepart')
            ->whereNull('dihapus_pada')
            ->where('id_pembelian', $idPembelian)
            ->update(RecordHelper::stampUpdate([
                'tanggal_pembayaran' => $tanggalTransfer,
            ]));
    }

    public function findPengajuanByPeriode(string $idPeriode): ?PengajuanPengeluaranModel
    {
        return PengajuanPengeluaranModel::active()->where('id_periode', $idPeriode)->first();
    }

    public function dataPeriodeUntukPengajuan(string $idPeriode): ?object
    {
        $periode = DB::table('payroll_periode')
            ->whereNull('dihapus_pada')
            ->where('id_periode', $idPeriode)
            ->first();
        if ($periode === null) {
            return null;
        }

        $totalGajiBersih = (float) DB::table('payroll_slip')
            ->whereNull('dihapus_pada')
            ->where('id_periode', $idPeriode)
            ->sum('gaji_bersih');

        return (object) [
            'id_perusahaan'     => $periode->id_perusahaan,
            'nama'              => $periode->nama,
            'tanggal_mulai'     => $periode->tanggal_mulai,
            'tanggal_selesai'   => $periode->tanggal_selesai,
            'total_gaji_bersih' => $totalGajiBersih,
        ];
    }

    public function listPemasukanGabungan(string $idPerusahaan, string $dari, string $sampai): Collection
    {
        $tanggal = 'COALESCE(faktur.tanggal_faktur, DATE(faktur.dibuat_pada))';

        $invoice = DB::table('faktur')
            ->whereNull('faktur.dihapus_pada')
            ->where('faktur.id_perusahaan', $idPerusahaan)
            ->where('faktur.status', '!=', 'batal')
            ->whereRaw("{$tanggal} BETWEEN ? AND ?", [$dari, $sampai])
            ->selectRaw("
                'invoice' as jenis,
                faktur.id_faktur as id,
                faktur.nomor_faktur as nomor,
                NULL as kategori,
                {$tanggal} as tanggal,
                faktur.total as nominal,
                NULL as sumber_dana,
                NULL as keterangan,
                NULL as url_bukti
            ");

        $manual = DB::table('pemasukan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->whereBetween('tanggal', [$dari, $sampai])
            ->selectRaw("
                'manual' as jenis,
                id_pemasukan as id,
                nomor_pemasukan as nomor,
                kategori as kategori,
                tanggal as tanggal,
                nominal as nominal,
                sumber_dana as sumber_dana,
                keterangan as keterangan,
                url_bukti as url_bukti
            ");

        return $invoice->unionAll($manual)->orderByDesc('tanggal')->orderByDesc('nomor')->get();
    }

    public function listApprover(string $idPerusahaan): array
    {
        return DB::table('approver_keuangan as ak')
            ->leftJoin('jabatan as j', function ($join) use ($idPerusahaan) {
                $join->on('j.id_jabatan', '=', 'ak.id_jabatan')
                    ->where('j.id_perusahaan', $idPerusahaan);
            })
            ->leftJoin('pengguna as p', function ($join) use ($idPerusahaan) {
                $join->on('p.id_pengguna', '=', 'ak.id_pengguna')
                    ->where('p.id_perusahaan', $idPerusahaan);
            })
            ->where('ak.id_perusahaan', $idPerusahaan)
            ->whereNull('ak.dihapus_pada')
            ->orderBy('ak.dibuat_pada')
            ->selectRaw('ak.*, COALESCE(j.nama_jabatan, p.username) as nama')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    public function insertApprover(array $data): void
    {
        DB::table('approver_keuangan')->insert(RecordHelper::stampCreate($data, 'id_approver'));
    }

    public function softDeleteApprover(string $id, string $idPerusahaan): bool
    {
        $jumlah = DB::table('approver_keuangan')
            ->where('id_approver', $id)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->update(RecordHelper::stampDelete());

        return $jumlah > 0;
    }

    public function adaApproverAktif(string $idPerusahaan, string $tipe, ?string $idRef): bool
    {
        $kolom = $tipe === 'jabatan' ? 'id_jabatan' : 'id_pengguna';

        return DB::table('approver_keuangan')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('tipe', $tipe)
            ->where($kolom, $idRef)
            ->where('aktif', 1)
            ->whereNull('dihapus_pada')
            ->exists();
    }

    public function getPengaturan(string $idPerusahaan, string $kunci): ?string
    {
        $nilai = DB::table('pengaturan')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('kunci', $kunci)
            ->whereNull('dihapus_pada')
            ->value('nilai');

        return $nilai !== null ? (string) $nilai : null;
    }

    public function setPengaturan(string $idPerusahaan, string $kunci, string $nilai): void
    {
        $existing = DB::table('pengaturan')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('kunci', $kunci)
            ->whereNull('dihapus_pada')
            ->first();

        if ($existing === null) {
            DB::table('pengaturan')->insert(RecordHelper::stampCreate([
                'id_perusahaan' => $idPerusahaan,
                'kunci'         => $kunci,
                'nilai'         => $nilai,
            ], 'id_pengaturan'));
            return;
        }

        DB::table('pengaturan')
            ->where('id_pengaturan', $existing->id_pengaturan)
            ->update(RecordHelper::stampUpdate(['nilai' => $nilai]));
    }

    public function resolusiApprover(string $idPerusahaan): array
    {
        $dariPengguna = DB::table('approver_keuangan as ak')
            ->join('pengguna as p', 'p.id_pengguna', '=', 'ak.id_pengguna')
            ->where('ak.id_perusahaan', $idPerusahaan)
            ->where('ak.tipe', 'pengguna')
            ->where('ak.aktif', 1)
            ->whereNull('ak.dihapus_pada')
            ->where('p.id_perusahaan', $idPerusahaan)
            ->where('p.aktif', 1)
            ->whereNull('p.dihapus_pada')
            ->pluck('p.id_pengguna');

        $dariJabatan = DB::table('approver_keuangan as ak')
            ->join('karyawan as k', 'k.id_jabatan', '=', 'ak.id_jabatan')
            ->join('pengguna as p', 'p.id_karyawan', '=', 'k.id_karyawan')
            ->where('ak.id_perusahaan', $idPerusahaan)
            ->where('ak.tipe', 'jabatan')
            ->where('ak.aktif', 1)
            ->whereNull('ak.dihapus_pada')
            ->where('k.id_perusahaan', $idPerusahaan)
            ->where('k.aktif', 1)
            ->whereNull('k.dihapus_pada')
            ->where('p.id_perusahaan', $idPerusahaan)
            ->where('p.aktif', 1)
            ->whereNull('p.dihapus_pada')
            ->pluck('p.id_pengguna');

        return $dariPengguna->merge($dariJabatan)->unique()->values()->all();
    }

    public function jabatanMilik(string $idJabatan, string $idPerusahaan): bool
    {
        return DB::table('jabatan')
            ->where('id_jabatan', $idJabatan)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->exists();
    }

    public function penggunaMilik(string $idPengguna, string $idPerusahaan): bool
    {
        return DB::table('pengguna')
            ->where('id_pengguna', $idPengguna)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->exists();
    }

    public function rekap(string $idPerusahaan, string $dari, string $sampai): Collection
    {
        $queries = [
            $this->queryFaktur($idPerusahaan, $dari, $sampai),
            $this->queryPengajuanPengeluaran($idPerusahaan, $dari, $sampai),
            $this->queryPembayaranVendor($idPerusahaan, $dari, $sampai),
            $this->queryPemasukanManual($idPerusahaan, $dari, $sampai),
        ];

        $utama = array_shift($queries);
        foreach ($queries as $query) {
            $utama->unionAll($query);
        }

        return $utama->orderByDesc('tanggal')->get();
    }

    private function queryFaktur(string $idPerusahaan, string $dari, string $sampai): Builder
    {
        $tanggal = 'COALESCE(faktur.tanggal_faktur, DATE(faktur.dibuat_pada))';

        return DB::table('faktur')
            ->whereNull('faktur.dihapus_pada')
            ->where('faktur.id_perusahaan', $idPerusahaan)
            ->where('faktur.status', '!=', 'batal')
            ->whereRaw("{$tanggal} BETWEEN ? AND ?", [$dari, $sampai])
            ->selectRaw("
                {$tanggal} as tanggal,
                'masuk' as arah,
                'faktur' as sumber,
                NULL as kategori,
                faktur.total as nominal,
                faktur.id_faktur as id_referensi,
                faktur.nomor_faktur as label,
                NULL as keterangan,
                NULL as url_bukti
            ");
    }

    private function queryPengajuanPengeluaran(string $idPerusahaan, string $dari, string $sampai): Builder
    {
        return DB::table('pengajuan_pengeluaran')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('status', 'ditransfer')
            ->whereBetween('tanggal_transfer', [$dari, $sampai])
            ->selectRaw("
                tanggal_transfer as tanggal,
                'keluar' as arah,
                'pengajuan_pengeluaran' as sumber,
                kategori as kategori,
                nominal as nominal,
                id_pengajuan as id_referensi,
                nomor_pengajuan as label,
                keterangan as keterangan,
                url_bukti as url_bukti
            ");
    }

    private function queryPembayaranVendor(string $idPerusahaan, string $dari, string $sampai): Builder
    {
        return DB::table('pembayaran_vendor')
            ->join('invoice_vendor', 'invoice_vendor.id_invoice_vendor', '=', 'pembayaran_vendor.id_invoice_vendor')
            ->whereNull('pembayaran_vendor.dihapus_pada')
            ->whereNull('invoice_vendor.dihapus_pada')
            ->where('invoice_vendor.id_perusahaan', $idPerusahaan)
            ->whereBetween('pembayaran_vendor.tanggal_bayar', [$dari, $sampai])
            ->selectRaw("
                pembayaran_vendor.tanggal_bayar as tanggal,
                'keluar' as arah,
                'pembayaran_vendor' as sumber,
                NULL as kategori,
                pembayaran_vendor.nominal as nominal,
                invoice_vendor.id_invoice_vendor as id_referensi,
                invoice_vendor.nomor_invoice as label,
                pembayaran_vendor.catatan as keterangan,
                pembayaran_vendor.url_bukti as url_bukti
            ");
    }

    private function queryPemasukanManual(string $idPerusahaan, string $dari, string $sampai): Builder
    {
        return DB::table('pemasukan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->whereBetween('tanggal', [$dari, $sampai])
            ->selectRaw("
                tanggal as tanggal,
                'masuk' as arah,
                'pemasukan_manual' as sumber,
                kategori as kategori,
                nominal as nominal,
                id_pemasukan as id_referensi,
                nomor_pemasukan as label,
                keterangan as keterangan,
                url_bukti as url_bukti
            ");
    }

    public function saldoKasSebelum(string $idPerusahaan, string $sebelumTanggal): float
    {
        $tanggalFaktur = 'COALESCE(faktur.tanggal_faktur, DATE(faktur.dibuat_pada))';

        $masukFaktur = (float) DB::table('faktur')
            ->whereNull('faktur.dihapus_pada')
            ->where('faktur.id_perusahaan', $idPerusahaan)
            ->where('faktur.status', '!=', 'batal')
            ->whereRaw("{$tanggalFaktur} < ?", [$sebelumTanggal])
            ->sum('faktur.total');

        $masukPemasukan = (float) DB::table('pemasukan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('tanggal', '<', $sebelumTanggal)
            ->sum('nominal');

        $keluarPengajuan = (float) DB::table('pengajuan_pengeluaran')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('status', 'ditransfer')
            ->where('tanggal_transfer', '<', $sebelumTanggal)
            ->sum('nominal');

        $keluarVendor = (float) DB::table('pembayaran_vendor')
            ->join('invoice_vendor', 'invoice_vendor.id_invoice_vendor', '=', 'pembayaran_vendor.id_invoice_vendor')
            ->whereNull('pembayaran_vendor.dihapus_pada')
            ->whereNull('invoice_vendor.dihapus_pada')
            ->where('invoice_vendor.id_perusahaan', $idPerusahaan)
            ->where('pembayaran_vendor.tanggal_bayar', '<', $sebelumTanggal)
            ->sum('pembayaran_vendor.nominal');

        return $masukFaktur + $masukPemasukan - $keluarPengajuan - $keluarVendor;
    }

    public function namaPerusahaan(string $idPerusahaan): ?string
    {
        $nama = DB::table('perusahaan')->whereNull('dihapus_pada')->where('id_perusahaan', $idPerusahaan)->value('nama');
        return $nama !== null ? (string) $nama : null;
    }

    public function insertApprovalRows(string $idPengajuan, array $idPenggunaList): void
    {
        if ($idPenggunaList === []) {
            return;
        }

        $rows = array_map(fn ($idPengguna) => RecordHelper::stampCreate([
            'id_pengajuan' => $idPengajuan,
            'id_pengguna'  => $idPengguna,
            'status'       => 'menunggu',
        ], 'id_approval'), $idPenggunaList);

        DB::table('pengajuan_approval')->insert($rows);
    }

    public function voidApprovalRows(string $idPengajuan): void
    {
        DB::table('pengajuan_approval')
            ->where('id_pengajuan', $idPengajuan)
            ->whereNull('dihapus_pada')
            ->update(RecordHelper::stampDelete());
    }

    public function listApproval(string $idPengajuan): array
    {
        return DB::table('pengajuan_approval as pa')
            ->leftJoin('pengguna as p', 'p.id_pengguna', '=', 'pa.id_pengguna')
            ->where('pa.id_pengajuan', $idPengajuan)
            ->whereNull('pa.dihapus_pada')
            ->orderBy('pa.dibuat_pada')
            ->selectRaw('pa.*, p.username as nama')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    public function listApprovalBanyak(array $idPengajuanList): array
    {
        if ($idPengajuanList === []) {
            return [];
        }

        return DB::table('pengajuan_approval as pa')
            ->leftJoin('pengguna as p', 'p.id_pengguna', '=', 'pa.id_pengguna')
            ->whereIn('pa.id_pengajuan', $idPengajuanList)
            ->whereNull('pa.dihapus_pada')
            ->orderBy('pa.dibuat_pada')
            ->selectRaw('pa.*, p.username as nama')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->groupBy('id_pengajuan')
            ->map(fn ($rows) => $rows->values()->all())
            ->all();
    }

    public function findApprovalMenunggu(string $idPengajuan, string $idPengguna): ?object
    {
        return DB::table('pengajuan_approval')
            ->where('id_pengajuan', $idPengajuan)
            ->where('id_pengguna', $idPengguna)
            ->where('status', 'menunggu')
            ->whereNull('dihapus_pada')
            ->first();
    }

    public function findPengajuanForUpdate(string $id): ?PengajuanPengeluaranModel
    {
        return PengajuanPengeluaranModel::active()->lockForUpdate()->find($id);
    }

    public function updateApprovalRowJikaMenunggu(string $idApproval, array $data): int
    {
        return DB::table('pengajuan_approval')
            ->where('id_approval', $idApproval)
            ->where('status', 'menunggu')
            ->whereNull('dihapus_pada')
            ->update(RecordHelper::stampUpdate($data));
    }

    public function hitungApprovalMenunggu(string $idPengajuan): int
    {
        return DB::table('pengajuan_approval')
            ->where('id_pengajuan', $idPengajuan)
            ->where('status', 'menunggu')
            ->whereNull('dihapus_pada')
            ->lockForUpdate()
            ->count();
    }

    public function tarifUangJalanSupir(string $idProyek, string $idSupir): ?object
    {
        return DB::table('penugasan as p')
            ->join('supir as s', 's.id_supir', '=', 'p.id_supir')
            ->join('proyek as pr', 'pr.id_proyek', '=', 'p.id_proyek')
            ->leftJoin('armada as a', 'a.id_armada', '=', 'p.id_armada')
            ->join('proyek_rute as prt', function ($join) {
                $join->on('prt.id_proyek', '=', 'p.id_proyek')
                     ->on('prt.id_rute', '=', 'p.id_rute');
            })
            ->whereNull('p.dihapus_pada')
            ->whereNull('prt.dihapus_pada')
            ->where('p.id_proyek', $idProyek)
            ->where('p.id_supir', $idSupir)
            ->whereIn('p.status', ['pending', 'aktif'])
            ->whereNotNull('prt.uang_jalan')
            ->orderByRaw('CASE WHEN a.id_jenis_kendaraan IS NOT NULL AND prt.id_jenis_kendaraan = a.id_jenis_kendaraan THEN 0 ELSE 1 END')
            ->orderBy('prt.dibuat_pada')
            ->select('prt.uang_jalan', 's.nama as nama_supir', 'pr.nama_proyek', 'pr.id_perusahaan')
            ->first();
    }

    public function namaSupir(string $idSupir): ?string
    {
        $nama = DB::table('supir')->where('id_supir', $idSupir)->value('nama');
        return $nama !== null ? (string) $nama : null;
    }

    public function hitungHariJadwalPengajuan(string $idPengajuan): object
    {
        return DB::table('jadwal_shift')
            ->whereNull('dihapus_pada')
            ->where('id_pengajuan', $idPengajuan)
            ->selectRaw('COUNT(*) as jumlah, MIN(tanggal) as dari, MAX(tanggal) as sampai')
            ->first();
    }

    public function unlinkJadwalPengajuan(string $idPengajuan): void
    {
        DB::table('jadwal_shift')
            ->where('id_pengajuan', $idPengajuan)
            ->update(['id_pengajuan' => null]);
    }

    public function findPengajuanPeriodeUntukTrip(string $idTrip): ?PengajuanPengeluaranModel
    {
        $trip = DB::table('trip as t')
            ->join('jadwal_keberangkatan as jk', 't.id_jadwal', '=', 'jk.id_jadwal')
            ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
            ->whereNull('t.dihapus_pada')
            ->whereNull('jk.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->where('t.id_trip', $idTrip)
            ->selectRaw('p.id_supir, p.id_proyek, DATE(COALESCE(t.waktu_checkin, t.dibuat_pada)) as tanggal')
            ->first();

        if ($trip === null || $trip->id_supir === null) {
            return null;
        }

        $idPengajuan = DB::table('jadwal_shift')
            ->whereNull('dihapus_pada')
            ->where('id_supir', $trip->id_supir)
            ->where('id_proyek', $trip->id_proyek)
            ->where('tanggal', $trip->tanggal)
            ->whereNotNull('id_pengajuan')
            ->value('id_pengajuan');

        return $idPengajuan !== null ? $this->findPengajuanById((string) $idPengajuan) : null;
    }
}
