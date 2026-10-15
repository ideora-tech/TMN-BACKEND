<?php

declare(strict_types=1);

namespace App\Modules\UangJalan;

use App\Modules\UangJalan\Contracts\UangJalanRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class UangJalanRepository implements UangJalanRepositoryInterface
{
    private const KUNCI_DRIVER = 'COALESCE(uj.id_supir, uj.id_supir_vendor, uj.nama_driver)';

    public function paginateByPerusahaan(
        string $idPerusahaan,
        int $page,
        int $limit,
        ?string $search = null,
        ?string $status = null,
        ?string $dari = null,
        ?string $sampai = null,
        ?string $idProyek = null,
    ): LengthAwarePaginator {
        return $this->saring($this->dasar()->where('uj.id_perusahaan', $idPerusahaan), $search, $status, $dari, $sampai, $idProyek)
            ->orderByDesc('uj.tanggal')
            ->orderByDesc('uj.nomor_uang_jalan')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function paginateKelompok(
        string $idPerusahaan,
        int $page,
        int $limit,
        array $daftarStatus,
        ?string $search = null,
        ?string $status = null,
        ?string $dari = null,
        ?string $sampai = null,
        ?string $idProyek = null,
    ): LengthAwarePaginator {
        $daftarStatus = array_values($daftarStatus);

        $query = $this->saring($this->dasar()->where('uj.id_perusahaan', $idPerusahaan), $search, $status, $dari, $sampai, $idProyek)
            ->select(DB::raw("COALESCE(uj.id_proyek, '') as kunci_proyek"))
            ->selectRaw('COUNT(*) as jumlah')
            ->selectRaw('COUNT(DISTINCT ' . self::KUNCI_DRIVER . ') as jumlah_driver')
            ->selectRaw('SUM(uj.jumlah_trip) as total_trip')
            ->selectRaw('SUM(uj.nominal) as total_nominal')
            ->selectRaw('MIN(uj.tanggal) as tanggal_awal')
            ->selectRaw('MAX(uj.tanggal) as tanggal_akhir')
            ->selectRaw('MAX(uj.nomor_uang_jalan) as nomor_terakhir')
            ->selectRaw('MAX(uj.id_uang_jalan) as id_contoh')
            ->selectRaw('MAX(uj.kode_proyek) as kode_proyek')
            ->selectRaw('MAX(uj.nama_proyek) as nama_proyek');

        foreach ($daftarStatus as $i => $kode) {
            $query->selectRaw("SUM(CASE WHEN pp.status = ? THEN 1 ELSE 0 END) as jumlah_status_{$i}", [$kode]);
        }

        $paginator = $query
            ->groupByRaw('kunci_proyek')
            ->orderByDesc('tanggal_akhir')
            ->orderByDesc('nomor_terakhir')
            ->orderBy('kunci_proyek')
            ->paginate($limit, ['*'], 'page', $page);

        $idTunggal = [];
        $idProyekList = [];
        foreach ($paginator->items() as $baris) {
            if ((int) $baris->jumlah === 1) {
                $idTunggal[] = $baris->id_contoh;
            }
            if ($baris->kunci_proyek !== '') {
                $idProyekList[] = $baris->kunci_proyek;
            }
        }

        $proyek = $idProyekList === []
            ? collect()
            : DB::table('proyek')
                ->where('id_perusahaan', $idPerusahaan)
                ->whereIn('id_proyek', $idProyekList)
                ->get(['id_proyek', 'kode_proyek', 'nama_proyek'])
                ->keyBy('id_proyek');

        $tunggal = $idTunggal === []
            ? collect()
            : $this->dasar()->whereIn('uj.id_uang_jalan', $idTunggal)->get()->keyBy('id_uang_jalan');

        foreach ($paginator->items() as $baris) {
            $baris->status = [];
            foreach ($daftarStatus as $i => $kode) {
                $jumlah = (int) $baris->{"jumlah_status_{$i}"};
                if ($jumlah > 0) {
                    $baris->status[$kode] = $jumlah;
                }
            }
            $baris->tunggal = (int) $baris->jumlah === 1 ? ($tunggal[$baris->id_contoh] ?? null) : null;

            $master = $proyek[$baris->kunci_proyek] ?? null;
            if ($master !== null) {
                $baris->kode_proyek = $master->kode_proyek;
                $baris->nama_proyek = $master->nama_proyek;
            }
        }

        return $paginator;
    }

    public function findById(string $id): ?object
    {
        return $this->dasar()->where('uj.id_uang_jalan', $id)->first();
    }

    public function findSupir(string $id, string $idPerusahaan): ?object
    {
        return DB::table('supir')
            ->where('id_supir', $id)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->first(['id_supir', 'nama']);
    }

    public function findArmada(string $id, string $idPerusahaan): ?object
    {
        return DB::table('armada')
            ->where('id_armada', $id)
            ->where('id_perusahaan', $idPerusahaan)
            ->where('kepemilikan', 'internal')
            ->whereNull('dihapus_pada')
            ->first(['id_armada', 'nopol']);
    }

    public function findVendor(string $id, string $idPerusahaan): ?object
    {
        return DB::table('vendor')
            ->where('id_vendor', $id)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->first(['id_vendor', 'nama_vendor']);
    }

    public function findSupirVendor(string $id, string $idVendor): ?object
    {
        return DB::table('supir_vendor')
            ->where('id_supir_vendor', $id)
            ->where('id_vendor', $idVendor)
            ->whereNull('dihapus_pada')
            ->first(['id_supir_vendor', 'nama']);
    }

    public function findArmadaVendor(string $id, string $idVendor): ?object
    {
        return DB::table('armada_vendor')
            ->where('id_armada_vendor', $id)
            ->where('id_vendor', $idVendor)
            ->whereNull('dihapus_pada')
            ->first(['id_armada_vendor', 'nopol']);
    }

    public function findRute(string $id, string $idPerusahaan): ?object
    {
        return DB::table('rute')
            ->where('id_rute', $id)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->first(['id_rute', 'nama_rute']);
    }

    public function findProyek(string $id, string $idPerusahaan): ?object
    {
        return DB::table('proyek')
            ->where('id_proyek', $id)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->first(['id_proyek', 'kode_proyek', 'nama_proyek']);
    }

    public function findRuteProyek(string $id, string $idProyek): ?object
    {
        return DB::table('proyek_rute as pru')
            ->join('rute as r', function (JoinClause $join) {
                $join->on('r.id_rute', '=', 'pru.id_rute')->whereNull('r.dihapus_pada');
            })
            ->where('pru.id_proyek', $idProyek)
            ->where('pru.id_rute', $id)
            ->whereNull('pru.dihapus_pada')
            ->first(['r.id_rute', 'r.nama_rute']);
    }

    public function findPenugasan(string $id, string $idProyek): ?object
    {
        return DB::table('penugasan')
            ->where('id_penugasan', $id)
            ->where('id_proyek', $idProyek)
            ->whereNull('dihapus_pada')
            ->first(['id_penugasan']);
    }

    public function opsiInternal(string $idPerusahaan): array
    {
        $supir = DB::table('supir as s')
            ->leftJoin('karyawan as k', function (JoinClause $join) {
                $join->on('k.id_karyawan', '=', 's.id_karyawan')
                    ->on('k.id_perusahaan', '=', 's.id_perusahaan')
                    ->whereNull('k.dihapus_pada');
            })
            ->where('s.id_perusahaan', $idPerusahaan)
            ->where('s.status', 'aktif')
            ->whereNull('s.dihapus_pada')
            ->orderBy('s.nama')
            ->get(['s.id_supir', 's.nama', 's.id_armada_default', 'k.nama_bank', 'k.nomor_rekening'])
            ->all();

        $armada = DB::table('armada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('kepemilikan', 'internal')
            ->where('aktif', 1)
            ->whereNull('dihapus_pada')
            ->orderBy('nopol')
            ->get(['id_armada', 'nopol', 'merk'])
            ->all();

        $vendor = DB::table('vendor')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('aktif', 1)
            ->whereNull('dihapus_pada')
            ->orderBy('nama_vendor')
            ->get(['id_vendor', 'nama_vendor'])
            ->all();

        $rute = DB::table('rute')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('aktif', 1)
            ->whereNull('dihapus_pada')
            ->orderBy('nama_rute')
            ->get(['id_rute', 'nama_rute'])
            ->all();

        return compact('supir', 'armada', 'vendor', 'rute');
    }

    public function opsiVendor(string $idVendor): array
    {
        $supirVendor = DB::table('supir_vendor')
            ->where('id_vendor', $idVendor)
            ->where('aktif', 1)
            ->whereNull('dihapus_pada')
            ->orderBy('nama')
            ->get(['id_supir_vendor', 'nama'])
            ->all();

        $armadaVendor = DB::table('armada_vendor')
            ->where('id_vendor', $idVendor)
            ->where('aktif', 1)
            ->whereNull('dihapus_pada')
            ->orderBy('nopol')
            ->get(['id_armada_vendor', 'nopol', 'merk', 'id_supir_vendor_default'])
            ->all();

        $rekening = DB::table('rekening_vendor')
            ->where('id_vendor', $idVendor)
            ->whereNull('dihapus_pada')
            ->orderBy('nama_bank')
            ->get(['nama_bank', 'nomor_rekening', 'atas_nama'])
            ->all();

        return ['supir_vendor' => $supirVendor, 'armada_vendor' => $armadaVendor, 'rekening' => $rekening];
    }

    public function opsiProyek(string $idPerusahaan): array
    {
        return DB::table('proyek')
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->orderBy('nama_proyek')
            ->get(['id_proyek', 'kode_proyek', 'nama_proyek'])
            ->all();
    }

    public function jenisKendaraanArmada(string $id, string $idPerusahaan): ?string
    {
        $jenis = DB::table('armada')
            ->where('id_armada', $id)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->value('id_jenis_kendaraan');

        return $jenis !== null ? (string) $jenis : null;
    }

    public function jenisKendaraanArmadaVendor(string $id, string $idPerusahaan): ?string
    {
        $jenis = DB::table('armada_vendor as av')
            ->join('vendor as v', function (JoinClause $join) use ($idPerusahaan) {
                $join->on('v.id_vendor', '=', 'av.id_vendor')
                    ->where('v.id_perusahaan', $idPerusahaan)
                    ->whereNull('v.dihapus_pada');
            })
            ->where('av.id_armada_vendor', $id)
            ->whereNull('av.dihapus_pada')
            ->value('av.id_jenis_kendaraan');

        return $jenis !== null ? (string) $jenis : null;
    }

    /**
     * Rate card proyek + rute. Baris dengan jenis kendaraan yang sama diutamakan,
     * kalau tidak ada dipakai baris pertama yang punya nilai — aturan yang sama
     * dengan ProyekRuteRepository::tarifUangJalanRute() dipakai Penugasan.
     */
    public function rateCardRute(string $idProyek, string $idRute, ?string $idJenisKendaraan): ?object
    {
        $kolom = ['id_proyek_rute', 'uang_jalan'];
        $dasar = fn () => DB::table('proyek_rute')
            ->where('id_proyek', $idProyek)
            ->where('id_rute', $idRute)
            ->whereNull('dihapus_pada')
            ->whereNotNull('uang_jalan');

        if ($idJenisKendaraan !== null) {
            $baris = $dasar()->where('id_jenis_kendaraan', $idJenisKendaraan)->orderBy('dibuat_pada')->first($kolom);
            if ($baris !== null) {
                return $baris;
            }
        }

        return $dasar()->orderBy('dibuat_pada')->first($kolom);
    }

    public function opsiRuteProyek(string $idProyek): array
    {
        return DB::table('proyek_rute as pru')
            ->join('rute as r', function (JoinClause $join) {
                $join->on('r.id_rute', '=', 'pru.id_rute')->whereNull('r.dihapus_pada');
            })
            ->where('pru.id_proyek', $idProyek)
            ->whereNull('pru.dihapus_pada')
            ->orderBy('r.nama_rute')
            ->get(['r.id_rute', 'r.nama_rute'])
            ->unique('id_rute')
            ->values()
            ->all();
    }

    /**
     * Daftar penugasan terjadwal (non-batal) dalam satu proyek — dipakai form
     * Uang Jalan untuk auto-isi driver/unit/rute/tanggal secara opsional.
     * `sumber` dipakai FE untuk menyaring sesuai tipe driver yang dipilih
     * ('internal'/'vendor') tanpa perlu request ulang per toggle.
     */
    public function opsiPenugasan(string $idProyek): array
    {
        return DB::table('penugasan as p')
            ->leftJoin('supir as s', 's.id_supir', '=', 'p.id_supir')
            ->leftJoin('supir_vendor as sv', 'sv.id_supir_vendor', '=', 'p.id_supir_vendor')
            ->leftJoin('armada as a', 'a.id_armada', '=', 'p.id_armada')
            ->leftJoin('armada_vendor as av', 'av.id_armada_vendor', '=', 'p.id_armada_vendor')
            ->leftJoin('rute as r', 'r.id_rute', '=', 'p.id_rute')
            ->where('p.id_proyek', $idProyek)
            ->where('p.status', '!=', 'batal')
            ->whereNull('p.dihapus_pada')
            ->orderByDesc('p.tanggal_tugas')
            ->orderByDesc('p.dibuat_pada')
            ->limit(500)
            ->get([
                'p.id_penugasan', 'p.tanggal_tugas', 'p.status', 'p.sumber',
                'p.id_supir', 'p.id_supir_vendor', 'p.id_armada', 'p.id_armada_vendor', 'p.id_rute',
                DB::raw('COALESCE(s.nama, sv.nama) as nama_driver'),
                DB::raw('COALESCE(a.nopol, av.nopol) as nopol'),
                DB::raw('COALESCE(sv.id_vendor, av.id_vendor) as id_vendor'),
                'r.nama_rute',
            ])
            ->all();
    }

    public function nomorBerikutnya(string $idPerusahaan): string
    {
        $prefix   = 'UJ-' . now()->format('Ym') . '-';
        $terakhir = DB::table('uang_jalan')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('nomor_uang_jalan', 'like', $prefix . '%')
            ->lockForUpdate()
            ->max('nomor_uang_jalan');
        $urut = $terakhir ? ((int) substr((string) $terakhir, -4)) + 1 : 1;

        return $prefix . str_pad((string) $urut, 4, '0', STR_PAD_LEFT);
    }

    public function rincianRateCard(string $idProyekRute): array
    {
        return DB::table('proyek_rute_komponen as prk')
            ->join('komponen_uang_jalan as k', 'k.id_komponen_uang_jalan', '=', 'prk.id_komponen_uang_jalan')
            ->whereNull('prk.dihapus_pada')
            ->whereNull('k.dihapus_pada')
            ->where('prk.id_proyek_rute', $idProyekRute)
            ->where('prk.nominal', '>', 0)
            ->orderBy('k.urutan')
            ->orderBy('k.nama')
            ->get(['prk.id_komponen_uang_jalan', 'k.nama', 'k.aktif', 'prk.nominal'])
            ->map(fn ($r) => [
                'id_komponen_uang_jalan' => (string) $r->id_komponen_uang_jalan,
                'nama_komponen'          => (string) $r->nama,
                'aktif'                  => (int) $r->aktif === 1,
                'nominal'                => (float) $r->nominal,
            ])
            ->all();
    }

    public function idKomponenRateCard(string $idProyek, string $idRute): array
    {
        return DB::table('proyek_rute_komponen as prk')
            ->join('proyek_rute as pr', 'pr.id_proyek_rute', '=', 'prk.id_proyek_rute')
            ->whereNull('prk.dihapus_pada')
            ->whereNull('pr.dihapus_pada')
            ->where('pr.id_proyek', $idProyek)
            ->where('pr.id_rute', $idRute)
            ->distinct()
            ->pluck('prk.id_komponen_uang_jalan')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    public function rincianUntuk(string $idUangJalan): array
    {
        return DB::table('uang_jalan_rincian as r')
            ->leftJoin('komponen_uang_jalan as k', function ($join) {
                $join->on('k.id_komponen_uang_jalan', '=', 'r.id_komponen_uang_jalan')->whereNull('k.dihapus_pada');
            })
            ->whereNull('r.dihapus_pada')
            ->where('r.id_uang_jalan', $idUangJalan)
            ->orderBy('r.urutan')
            ->orderBy('r.nama_komponen')
            ->get(['r.id_komponen_uang_jalan', 'r.nama_komponen', 'r.nominal', 'k.aktif'])
            ->map(fn ($r) => [
                'id_komponen_uang_jalan' => $r->id_komponen_uang_jalan !== null ? (string) $r->id_komponen_uang_jalan : null,
                'nama_komponen'          => (string) $r->nama_komponen,
                'aktif'                  => (int) ($r->aktif ?? 0) === 1,
                'nominal'                => (float) $r->nominal,
            ])
            ->all();
    }

    public function simpanRincian(string $idUangJalan, string $idPerusahaan, array $rincian): void
    {
        DB::table('uang_jalan_rincian')
            ->whereNull('dihapus_pada')
            ->where('id_uang_jalan', $idUangJalan)
            ->update(RecordHelper::stampDelete());

        foreach (array_values($rincian) as $i => $r) {
            DB::table('uang_jalan_rincian')->insert(RecordHelper::stampCreate([
                'id_perusahaan'          => $idPerusahaan,
                'id_uang_jalan'          => $idUangJalan,
                'id_komponen_uang_jalan' => $r['id_komponen_uang_jalan'],
                'nama_komponen'          => $r['nama_komponen'],
                'urutan'                 => $i + 1,
                'nominal'                => $r['nominal'],
            ], 'id_uang_jalan_rincian'));
        }
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_uang_jalan');
        DB::table('uang_jalan')->insert($data);

        return $this->findById($data['id_uang_jalan']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('uang_jalan')
            ->where('id_uang_jalan', $record->id_uang_jalan)
            ->update(RecordHelper::stampUpdate($data));

        return $this->findById($record->id_uang_jalan);
    }

    public function delete(object $record): void
    {
        DB::table('uang_jalan')
            ->where('id_uang_jalan', $record->id_uang_jalan)
            ->update(RecordHelper::stampDelete());
    }

    private function saring(Builder $query, ?string $search, ?string $status, ?string $dari, ?string $sampai, ?string $idProyek): Builder
    {
        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('uj.nomor_uang_jalan', 'like', "%{$search}%")
                    ->orWhere('uj.nama_driver', 'like', "%{$search}%")
                    ->orWhere('uj.nama_vendor', 'like', "%{$search}%")
                    ->orWhere('uj.nopol', 'like', "%{$search}%")
                    ->orWhere('uj.rute', 'like', "%{$search}%")
                    ->orWhere('uj.kode_proyek', 'like', "%{$search}%")
                    ->orWhere('uj.nama_proyek', 'like', "%{$search}%");
            });
        }

        if ($status !== null && $status !== '') {
            $query->where('pp.status', $status);
        }

        if ($dari !== null && $dari !== '') {
            $query->where('uj.tanggal', '>=', $dari);
        }

        if ($sampai !== null && $sampai !== '') {
            $query->where('uj.tanggal', '<=', $sampai);
        }

        if ($idProyek === self::TANPA_PROYEK) {
            $query->whereNull('uj.id_proyek');
        } elseif ($idProyek !== null && $idProyek !== '') {
            $query->where('uj.id_proyek', $idProyek);
        }

        return $query;
    }

    private function dasar(): Builder
    {
        return DB::table('uang_jalan as uj')
            ->leftJoin('pengajuan_pengeluaran as pp', function (JoinClause $join) {
                $join->on('pp.id_pengajuan', '=', 'uj.id_pengajuan')->whereNull('pp.dihapus_pada');
            })
            ->whereNull('uj.dihapus_pada')
            ->select([
                'uj.*',
                'pp.nomor_pengajuan',
                'pp.status as status_pengajuan',
                'pp.alasan_ditolak',
                'pp.tanggal_transfer',
            ]);
    }
}
