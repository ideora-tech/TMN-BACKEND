<?php

declare(strict_types=1);

namespace App\Modules\Kasbon;

use App\Modules\Kasbon\Contracts\KasbonRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class KasbonRepository implements KasbonRepositoryInterface
{
    private const STATUS_MENUNGGU_APPROVAL  = ['diajukan', 'menunggu_approval'];
    private const STATUS_MENUNGGU_PENCAIRAN = ['disetujui', 'dicek', 'siap_transfer'];

    public function paginateByPerusahaan(
        string $idPerusahaan,
        int $page,
        int $limit,
        ?string $search = null,
        ?string $status = null,
        ?string $idKaryawan = null,
    ): LengthAwarePaginator {
        return $this->tersaring($idPerusahaan, $search, $status, $idKaryawan)
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function semuaByPerusahaan(string $idPerusahaan, ?string $search = null, ?string $status = null, ?string $idKaryawan = null): array
    {
        return $this->tersaring($idPerusahaan, $search, $status, $idKaryawan)->get()->all();
    }

    public function findById(string $id): ?object
    {
        return $this->dasar($this->terbayarPerKasbon()->where('kp.id_kasbon', $id))
            ->where('k.id_kasbon', $id)
            ->first();
    }

    public function kunci(array $idKasbon): void
    {
        if ($idKasbon === []) {
            return;
        }

        DB::table('kasbon')
            ->whereIn('id_kasbon', $idKasbon)
            ->orderBy('id_kasbon')
            ->lockForUpdate()
            ->get(['id_kasbon']);
    }

    public function hitungMenunggu(string $idPerusahaan): int
    {
        return DB::table('kasbon as k')
            ->leftJoin('pengajuan_pengeluaran as pp', function (JoinClause $join) {
                $join->on('pp.id_pengajuan', '=', 'k.id_pengajuan')->whereNull('pp.dihapus_pada');
            })
            ->where('k.id_perusahaan', $idPerusahaan)
            ->where('k.saldo_awal', 0)
            ->whereNull('k.dihapus_pada')
            ->whereIn('pp.status', [...self::STATUS_MENUNGGU_APPROVAL, ...self::STATUS_MENUNGGU_PENCAIRAN])
            ->count();
    }

    public function nomorBerikutnya(string $idPerusahaan): string
    {
        $prefix   = 'KSB-' . now()->format('Ym') . '-';
        $terakhir = DB::table('kasbon')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('nomor_kasbon', 'like', $prefix . '%')
            ->lockForUpdate()
            ->max('nomor_kasbon');
        $urut = $terakhir ? ((int) substr((string) $terakhir, -4)) + 1 : 1;

        return $prefix . str_pad((string) $urut, 4, '0', STR_PAD_LEFT);
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_kasbon');
        DB::table('kasbon')->insert($data);

        return $this->findById($data['id_kasbon']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('kasbon')
            ->where('id_kasbon', $record->id_kasbon)
            ->update(RecordHelper::stampUpdate($data));

        return $this->findById($record->id_kasbon);
    }

    public function delete(object $record): void
    {
        DB::table('kasbon')
            ->where('id_kasbon', $record->id_kasbon)
            ->update(RecordHelper::stampDelete());
    }

    public function findKaryawan(string $id, string $idPerusahaan): ?object
    {
        return DB::table('karyawan')
            ->where('id_karyawan', $id)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->first(['id_karyawan', 'nama_karyawan', 'nik', 'aktif']);
    }

    public function opsiKaryawan(string $idPerusahaan): array
    {
        return DB::table('karyawan as kr')
            ->leftJoin('jabatan as j', 'j.id_jabatan', '=', 'kr.id_jabatan')
            ->where('kr.id_perusahaan', $idPerusahaan)
            ->whereNull('kr.dihapus_pada')
            ->orderByDesc('kr.aktif')
            ->orderBy('kr.nama_karyawan')
            ->get(['kr.id_karyawan', 'kr.nama_karyawan', 'kr.nik', 'kr.aktif', 'kr.nama_bank', 'kr.nomor_rekening', 'j.nama_jabatan'])
            ->all();
    }

    public function pembayaranByKasbon(string $idKasbon): array
    {
        return DB::table('kasbon_pembayaran as kp')
            ->leftJoin('payroll_periode as p', 'p.id_periode', '=', 'kp.id_periode')
            ->where('kp.id_kasbon', $idKasbon)
            ->whereNull('kp.dihapus_pada')
            ->orderBy('kp.tanggal')
            ->orderBy('kp.dibuat_pada')
            ->get([
                'kp.id_kasbon_pembayaran', 'kp.tanggal', 'kp.nominal', 'kp.sumber',
                'kp.id_periode', 'kp.id_pemasukan', 'kp.keterangan', 'p.nama as nama_periode',
            ])
            ->all();
    }

    public function findPembayaran(string $idPembayaran, string $idKasbon): ?object
    {
        return DB::table('kasbon_pembayaran')
            ->where('id_kasbon_pembayaran', $idPembayaran)
            ->where('id_kasbon', $idKasbon)
            ->whereNull('dihapus_pada')
            ->first();
    }

    public function createPembayaran(array $data): void
    {
        DB::table('kasbon_pembayaran')->insert(RecordHelper::stampCreate($data, 'id_kasbon_pembayaran'));
    }

    public function deletePembayaran(object $record): void
    {
        DB::table('kasbon_pembayaran')
            ->where('id_kasbon_pembayaran', $record->id_kasbon_pembayaran)
            ->update(RecordHelper::stampDelete());
    }

    public function hapusPembayaranPayroll(string $idPeriode): void
    {
        DB::table('kasbon_pembayaran')
            ->where('id_periode', $idPeriode)
            ->where('sumber', 'payroll')
            ->whereNull('dihapus_pada')
            ->update(RecordHelper::stampDelete());
    }

    public function createRiwayatCicilan(array $data): void
    {
        DB::table('kasbon_riwayat_cicilan')->insert(RecordHelper::stampCreate($data, 'id_riwayat_cicilan'));
    }

    public function riwayatCicilanByKasbon(string $idKasbon): array
    {
        return DB::table('kasbon_riwayat_cicilan as r')
            ->leftJoin('pengguna as u', 'u.id_pengguna', '=', 'r.dibuat_oleh')
            ->where('r.id_kasbon', $idKasbon)
            ->whereNull('r.dihapus_pada')
            ->orderByDesc('r.dibuat_pada')
            ->get([
                'r.id_riwayat_cicilan', 'r.cicilan_lama', 'r.cicilan_baru', 'r.mulai_potong_lama',
                'r.mulai_potong_baru', 'r.alasan', 'r.dibuat_pada', 'u.username as oleh',
            ])
            ->all();
    }

    public function berjalanByPerusahaan(string $idPerusahaan, ?array $idKaryawan = null, bool $kunci = false): array
    {
        if ($idKaryawan === []) {
            return [];
        }

        $terbayar = $this->terbayarPerKasbon()
            ->join('kasbon as kb', 'kb.id_kasbon', '=', 'kp.id_kasbon')
            ->where('kb.id_perusahaan', $idPerusahaan);

        $rows = DB::table('kasbon as k')
            ->leftJoin('pengajuan_pengeluaran as pp', function (JoinClause $join) {
                $join->on('pp.id_pengajuan', '=', 'k.id_pengajuan')->whereNull('pp.dihapus_pada');
            })
            ->leftJoin('karyawan as kr', 'kr.id_karyawan', '=', 'k.id_karyawan')
            ->leftJoinSub($terbayar, 'tb', 'tb.id_kasbon', '=', 'k.id_kasbon')
            ->where('k.id_perusahaan', $idPerusahaan)
            ->whereNull('k.dihapus_pada')
            ->where(fn ($q) => $q->where('k.saldo_awal', 1)->orWhere('pp.status', 'ditransfer'))
            ->whereRaw('k.nominal > COALESCE(tb.total, 0)')
            ->when($idKaryawan !== null, fn ($q) => $q->whereIn('k.id_karyawan', $idKaryawan))
            ->orderBy('k.tanggal')
            ->orderBy('k.dibuat_pada')
            ->orderBy('k.nomor_kasbon')
            ->get([
                'k.id_kasbon', 'k.id_karyawan', 'k.nomor_kasbon', 'k.nominal', 'k.cicilan_per_periode',
                'k.mulai_potong', 'kr.aktif as karyawan_aktif', DB::raw('COALESCE(tb.total, 0) as terbayar'),
            ]);

        if ($kunci) {
            return $this->kunciDanBacaUlang($rows->all(), $idKaryawan);
        }

        foreach ($rows as $row) {
            $row->terbayar = (float) $row->terbayar;
            $row->sisa     = round((float) $row->nominal - $row->terbayar, 2);
        }

        return $rows->all();
    }

    /**
     * @param list<object> $rows
     * @param list<string>|null $idKaryawan
     * @return list<object>
     */
    private function kunciDanBacaUlang(array $rows, ?array $idKaryawan): array
    {
        if ($rows === []) {
            return [];
        }

        $idKasbon = array_map(static fn (object $r) => (string) $r->id_kasbon, $rows);

        $terkunci = DB::table('kasbon')
            ->whereIn('id_kasbon', $idKasbon)
            ->whereNull('dihapus_pada')
            ->orderBy('id_kasbon')
            ->lockForUpdate()
            ->get(['id_kasbon', 'id_karyawan', 'nominal', 'cicilan_per_periode', 'mulai_potong'])
            ->keyBy('id_kasbon');

        $terbayar = [];
        $pembayaran = DB::table('kasbon_pembayaran')
            ->whereIn('id_kasbon', $idKasbon)
            ->whereNull('dihapus_pada')
            ->lockForUpdate()
            ->get(['id_kasbon', 'nominal']);
        foreach ($pembayaran as $baris) {
            $terbayar[$baris->id_kasbon] = ($terbayar[$baris->id_kasbon] ?? 0) + (float) $baris->nominal;
        }

        $hasil = [];
        foreach ($rows as $row) {
            $terkini = $terkunci->get($row->id_kasbon);
            if ($terkini === null || ($idKaryawan !== null && !in_array($terkini->id_karyawan, $idKaryawan, true))) {
                continue;
            }

            $row->id_karyawan         = $terkini->id_karyawan;
            $row->nominal             = $terkini->nominal;
            $row->cicilan_per_periode = $terkini->cicilan_per_periode;
            $row->mulai_potong        = $terkini->mulai_potong;
            $row->terbayar            = round((float) ($terbayar[$row->id_kasbon] ?? 0), 2);
            $row->sisa                = round((float) $row->nominal - $row->terbayar, 2);
            if ($row->sisa > 0) {
                $hasil[] = $row;
            }
        }

        return $hasil;
    }

    private function tersaring(string $idPerusahaan, ?string $search, ?string $status, ?string $idKaryawan): Builder
    {
        $terbayar = $this->terbayarPerKasbon()
            ->join('kasbon as kb', 'kb.id_kasbon', '=', 'kp.id_kasbon')
            ->where('kb.id_perusahaan', $idPerusahaan);

        $query = $this->dasar($terbayar)->where('k.id_perusahaan', $idPerusahaan);

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('k.nomor_kasbon', 'like', "%{$search}%")
                    ->orWhere('kr.nama_karyawan', 'like', "%{$search}%")
                    ->orWhere('kr.nik', 'like', "%{$search}%");
            });
        }

        if ($idKaryawan !== null && $idKaryawan !== '') {
            $query->where('k.id_karyawan', $idKaryawan);
        }

        $dicairkan = fn ($q) => $q->where('k.saldo_awal', 1)->orWhere('pp.status', 'ditransfer');

        match ($status) {
            'menunggu_approval' => $query->where('k.saldo_awal', 0)
                ->where(fn ($q) => $q->whereIn('pp.status', self::STATUS_MENUNGGU_APPROVAL)->orWhereNull('pp.status')),
            'menunggu_pencairan' => $query->where('k.saldo_awal', 0)->whereIn('pp.status', self::STATUS_MENUNGGU_PENCAIRAN),
            'ditolak'  => $query->where('k.saldo_awal', 0)->where('pp.status', 'ditolak'),
            'berjalan' => $query->where($dicairkan)->whereRaw('k.nominal > COALESCE(tb.total, 0)'),
            'lunas'    => $query->where($dicairkan)->whereRaw('k.nominal <= COALESCE(tb.total, 0)'),
            default    => null,
        };

        return $query->orderByDesc('k.tanggal')->orderByDesc('k.nomor_kasbon');
    }

    private function terbayarPerKasbon(): Builder
    {
        return DB::table('kasbon_pembayaran as kp')
            ->whereNull('kp.dihapus_pada')
            ->groupBy('kp.id_kasbon')
            ->selectRaw('kp.id_kasbon, SUM(kp.nominal) as total');
    }

    private function dasar(Builder $terbayar): Builder
    {
        return DB::table('kasbon as k')
            ->leftJoin('pengajuan_pengeluaran as pp', function (JoinClause $join) {
                $join->on('pp.id_pengajuan', '=', 'k.id_pengajuan')->whereNull('pp.dihapus_pada');
            })
            ->leftJoin('karyawan as kr', function (JoinClause $join) {
                $join->on('kr.id_karyawan', '=', 'k.id_karyawan')->on('kr.id_perusahaan', '=', 'k.id_perusahaan');
            })
            ->leftJoin('jabatan as j', 'j.id_jabatan', '=', 'kr.id_jabatan')
            ->leftJoinSub($terbayar, 'tb', 'tb.id_kasbon', '=', 'k.id_kasbon')
            ->whereNull('k.dihapus_pada')
            ->select([
                'k.*',
                'kr.nama_karyawan',
                'kr.nik',
                'kr.aktif as karyawan_aktif',
                'j.nama_jabatan',
                'pp.nomor_pengajuan',
                'pp.status as status_pengajuan',
                'pp.alasan_ditolak',
                'pp.tanggal_transfer',
                DB::raw('COALESCE(tb.total, 0) as terbayar'),
            ]);
    }
}
