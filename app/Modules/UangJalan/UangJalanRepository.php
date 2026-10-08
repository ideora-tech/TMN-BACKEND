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
        $query = $this->dasar()->where('uj.id_perusahaan', $idPerusahaan);

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

        if ($idProyek !== null && $idProyek !== '') {
            $query->where('uj.id_proyek', $idProyek);
        }

        return $query
            ->orderByDesc('uj.tanggal')
            ->orderByDesc('uj.nomor_uang_jalan')
            ->paginate($limit, ['*'], 'page', $page);
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
        $kolom = ['estimasi_tol', 'estimasi_bbm', 'estimasi_biaya_lain', 'uang_jalan'];
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
