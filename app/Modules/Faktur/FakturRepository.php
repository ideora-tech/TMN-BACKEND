<?php

declare(strict_types=1);

namespace App\Modules\Faktur;

use App\Modules\Faktur\Contracts\FakturRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class FakturRepository implements FakturRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search = null, ?string $status = null): LengthAwarePaginator
    {
        $query = FakturModel::active()
            ->leftJoin('klien', function ($join) {
                $join->on('klien.id_klien', '=', 'faktur.id_klien')
                    ->whereNull('klien.dihapus_pada');
            })
            ->leftJoin('proyek', function ($join) {
                $join->on('proyek.id_proyek', '=', 'faktur.id_proyek')
                    ->whereNull('proyek.dihapus_pada');
            })
            ->where('faktur.id_perusahaan', $idPerusahaan)
            ->select('faktur.*', 'klien.nama_klien', 'proyek.nama_proyek')
            ->orderBy('faktur.dibuat_pada', 'desc');

        if ($status !== null && $status !== '') {
            $query->where('faktur.status', $status);
        }

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('faktur.nomor_faktur', 'like', "%{$search}%")
                  ->orWhere('klien.nama_klien', 'like', "%{$search}%");
            });
        }

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    public function paginateByKlien(string $idKlien, string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        return FakturModel::active()
            ->leftJoin('proyek', function ($join) {
                $join->on('proyek.id_proyek', '=', 'faktur.id_proyek')
                    ->whereNull('proyek.dihapus_pada');
            })
            ->where('faktur.id_klien', $idKlien)
            ->where('faktur.id_perusahaan', $idPerusahaan)
            ->select('faktur.*', 'proyek.nama_proyek')
            ->orderBy('faktur.dibuat_pada', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?FakturModel
    {
        $record = FakturModel::active()->with('items')->find($id);
        if ($record !== null) {
            $record->pajak = $this->pajakUntukSatu($id);
            $record->syncOriginalAttribute('pajak');
        }
        return $record;
    }

    public function findForUpdate(string $id): ?FakturModel
    {
        return FakturModel::active()->lockForUpdate()->find($id);
    }

    public function namaKlien(string $idKlien, string $idPerusahaan): ?string
    {
        return DB::table('klien')
            ->where('id_klien', $idKlien)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->value('nama_klien');
    }

    public function namaProyek(string $idProyek, string $idPerusahaan): ?string
    {
        return DB::table('proyek')
            ->where('id_proyek', $idProyek)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->value('nama_proyek');
    }

    public function infoPenawaran(string $idPenawaran, string $idPerusahaan): ?object
    {
        return DB::table('penawaran')
            ->where('id_penawaran', $idPenawaran)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->first(['id_penawaran', 'nomor_penawaran', 'nilai_penawaran', 'tipe_harga', 'status']);
    }

    public function getPerusahaan(string $idPerusahaan): ?object
    {
        return DB::table('perusahaan')->where('id_perusahaan', $idPerusahaan)->first();
    }

    public function findByNomor(string $nomor, string $idPerusahaan): ?FakturModel
    {
        return FakturModel::active()
            ->where('nomor_faktur', $nomor)
            ->where('id_perusahaan', $idPerusahaan)
            ->first();
    }

    public function nomorBerikutnya(string $idPerusahaan): string
    {
        $prefix = 'FK-' . now()->format('Ym') . '-';
        $terakhir = DB::table('faktur')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('nomor_faktur', 'like', $prefix . '%')
            ->lockForUpdate()
            ->max('nomor_faktur');
        $urut = $terakhir ? ((int) substr($terakhir, -4)) + 1 : 1;

        return $prefix . str_pad((string) $urut, 4, '0', STR_PAD_LEFT);
    }

    public function create(array $data): FakturModel
    {
        return FakturModel::create($data);
    }

    public function update(FakturModel $model, array $data): FakturModel
    {
        $model->update($data);
        $fresh = $model->fresh(['items']);
        $fresh->pajak = $this->pajakUntukSatu((string) $fresh->id_faktur);
        $fresh->syncOriginalAttribute('pajak');
        return $fresh;
    }

    public function delete(FakturModel $model): void
    {
        $model->softDelete();
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

    public function insertStatusLog(string $idFaktur, string $status, ?string $keterangan = null): void
    {
        DB::table('faktur_status_log')->insert(RecordHelper::stampCreate([
            'id_faktur'  => $idFaktur,
            'status'     => $status,
            'keterangan' => $keterangan,
        ], 'id_log'));
    }

    public function listStatusLog(string $idFaktur): array
    {
        return DB::table('faktur_status_log as l')
            ->leftJoin('pengguna as u', 'u.id_pengguna', '=', 'l.dibuat_oleh')
            ->where('l.id_faktur', $idFaktur)
            ->whereNull('l.dihapus_pada')
            ->orderBy('l.dibuat_pada')
            ->get(['l.status', 'l.keterangan', 'l.dibuat_pada as waktu', 'u.username as oleh'])
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    public function tripTerkait(string $idFaktur): array
    {
        return DB::table('faktur_trip as ft')
            ->join('trip as t', 't.id_trip', '=', 'ft.id_trip')
            ->join('jadwal_keberangkatan as jk', 'jk.id_jadwal', '=', 't.id_jadwal')
            ->join('penugasan as p', 'p.id_penugasan', '=', 'jk.id_penugasan')
            ->leftJoin('rute as r', 'r.id_rute', '=', 'jk.id_rute')
            ->leftJoin('armada as a', 'a.id_armada', '=', 'p.id_armada')
            ->leftJoin('armada_vendor as av', 'av.id_armada_vendor', '=', 'p.id_armada_vendor')
            ->leftJoin('supir as s', 's.id_supir', '=', 'p.id_supir')
            ->leftJoin('supir_vendor as sv', 'sv.id_supir_vendor', '=', 'p.id_supir_vendor')
            ->where('ft.id_faktur', $idFaktur)
            ->whereNull('ft.dihapus_pada')
            ->whereNull('t.dihapus_pada')
            ->orderBy('jk.waktu_berangkat')
            ->select(
                't.id_trip',
                DB::raw('COALESCE(r.nama_rute, jk.rute) as rute'),
                DB::raw('COALESCE(a.nopol, av.nopol) as armada_nopol'),
                DB::raw('COALESCE(s.nama, sv.nama) as supir_nama'),
                'jk.waktu_berangkat',
                't.waktu_checkin',
                't.waktu_checkout',
                't.status'
            )
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    public function pajakUntukSatu(string $idFaktur): array
    {
        return $this->pajakUntukBanyak([$idFaktur])[$idFaktur] ?? [];
    }

    public function pajakUntukBanyak(array $idFakturList): array
    {
        if ($idFakturList === []) {
            return [];
        }

        $rows = DB::table('faktur_pajak')
            ->whereIn('id_faktur', $idFakturList)
            ->whereNull('dihapus_pada')
            ->orderBy('urutan')
            ->get(['id_faktur', 'nama', 'persen']);

        return $rows
            ->groupBy('id_faktur')
            ->map(fn ($grup) => $grup->map(fn ($item) => [
                'nama'   => $item->nama,
                'persen' => (float) $item->persen,
            ])->values()->all())
            ->all();
    }

    public function replacePajak(string $idFaktur, array $pajakRows): void
    {
        DB::table('faktur_pajak')
            ->where('id_faktur', $idFaktur)
            ->whereNull('dihapus_pada')
            ->update(RecordHelper::stampDelete());

        foreach (array_values($pajakRows) as $i => $row) {
            DB::table('faktur_pajak')->insert(RecordHelper::stampCreate([
                'id_faktur' => $idFaktur,
                'nama'      => $row['nama'],
                'persen'    => $row['persen'],
                'urutan'    => $i + 1,
            ], 'id_faktur_pajak'));
        }
    }

    public function listPembayaran(string $idFaktur): array
    {
        return DB::table('pembayaran_faktur as pf')
            ->leftJoin('pengguna as u', 'u.id_pengguna', '=', 'pf.dibuat_oleh')
            ->whereNull('pf.dihapus_pada')
            ->where('pf.id_faktur', $idFaktur)
            ->orderBy('pf.tanggal_bayar')
            ->orderBy('pf.dibuat_pada')
            ->get([
                'pf.id_pembayaran_faktur', 'pf.tanggal_bayar', 'pf.nominal', 'pf.potongan', 'pf.keterangan_potongan',
                'pf.no_referensi', 'pf.url_bukti', 'pf.catatan', 'pf.dibuat_pada', 'u.username as dicatat_oleh',
            ])
            ->all();
    }

    public function insertPembayaran(array $data): string
    {
        $data = RecordHelper::stampCreate($data, 'id_pembayaran_faktur');
        DB::table('pembayaran_faktur')->insert($data);
        return (string) $data['id_pembayaran_faktur'];
    }

    public function findPembayaran(string $idFaktur, string $idPembayaran): ?object
    {
        return DB::table('pembayaran_faktur')
            ->whereNull('dihapus_pada')
            ->where('id_faktur', $idFaktur)
            ->where('id_pembayaran_faktur', $idPembayaran)
            ->first();
    }

    public function softDeletePembayaran(string $idPembayaran): void
    {
        DB::table('pembayaran_faktur')->where('id_pembayaran_faktur', $idPembayaran)->update(RecordHelper::stampDelete());
    }

    public function totalPembayaran(string $idFaktur): float
    {
        return round((float) DB::table('pembayaran_faktur')
            ->whereNull('dihapus_pada')
            ->where('id_faktur', $idFaktur)
            ->sum(DB::raw('nominal + potongan')), 2);
    }

    public function tanggalBayarTerakhir(string $idFaktur): ?string
    {
        $tanggal = DB::table('pembayaran_faktur')
            ->whereNull('dihapus_pada')
            ->where('id_faktur', $idFaktur)
            ->max('tanggal_bayar');
        return $tanggal !== null ? substr((string) $tanggal, 0, 10) : null;
    }

    public function pembayaranUntukBanyak(array $idFakturList): array
    {
        if ($idFakturList === []) {
            return [];
        }

        return DB::table('pembayaran_faktur')
            ->whereNull('dihapus_pada')
            ->whereIn('id_faktur', $idFakturList)
            ->groupBy('id_faktur')
            ->selectRaw('id_faktur, SUM(nominal) as diterima, SUM(potongan) as potongan')
            ->get()
            ->mapWithKeys(fn ($r) => [(string) $r->id_faktur => ['diterima' => (float) $r->diterima, 'potongan' => (float) $r->potongan]])
            ->all();
    }

    public function outstanding(string $idPerusahaan): array
    {
        $bayar = DB::table('pembayaran_faktur as pf')
            ->join('faktur as f', 'f.id_faktur', '=', 'pf.id_faktur')
            ->whereNull('pf.dihapus_pada')
            ->whereNull('f.dihapus_pada')
            ->where('f.id_perusahaan', $idPerusahaan)
            ->where('f.status', 'terkirim')
            ->groupBy('pf.id_faktur')
            ->selectRaw('pf.id_faktur, SUM(pf.nominal + pf.potongan) as terbayar');

        return DB::table('faktur')
            ->leftJoin('klien', function ($join) use ($idPerusahaan) {
                $join->on('klien.id_klien', '=', 'faktur.id_klien')
                    ->where('klien.id_perusahaan', $idPerusahaan)
                    ->whereNull('klien.dihapus_pada');
            })
            ->leftJoin('proyek', function ($join) use ($idPerusahaan) {
                $join->on('proyek.id_proyek', '=', 'faktur.id_proyek')
                    ->where('proyek.id_perusahaan', $idPerusahaan)
                    ->whereNull('proyek.dihapus_pada');
            })
            ->leftJoinSub($bayar, 'bayar', 'bayar.id_faktur', '=', 'faktur.id_faktur')
            ->whereNull('faktur.dihapus_pada')
            ->where('faktur.id_perusahaan', $idPerusahaan)
            ->where('faktur.status', 'terkirim')
            ->get([
                'faktur.id_faktur', 'faktur.nomor_faktur', 'faktur.id_klien', 'klien.nama_klien', 'faktur.id_proyek', 'proyek.nama_proyek',
                'faktur.tanggal_faktur', 'faktur.jatuh_tempo', 'faktur.total', DB::raw('COALESCE(bayar.terbayar, 0) as terbayar'),
            ])
            ->all();
    }

    public function diterimaAntara(string $idPerusahaan, string $dari, string $sampai): float
    {
        return round((float) DB::table('pembayaran_faktur as pf')
            ->join('faktur', 'faktur.id_faktur', '=', 'pf.id_faktur')
            ->whereNull('pf.dihapus_pada')
            ->whereNull('faktur.dihapus_pada')
            ->where('faktur.id_perusahaan', $idPerusahaan)
            ->whereBetween('pf.tanggal_bayar', [$dari, $sampai])
            ->sum('pf.nominal'), 2);
    }
}
