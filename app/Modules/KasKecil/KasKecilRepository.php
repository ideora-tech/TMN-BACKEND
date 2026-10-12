<?php

declare(strict_types=1);

namespace App\Modules\KasKecil;

use App\Modules\KasKecil\Contracts\KasKecilRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class KasKecilRepository implements KasKecilRepositoryInterface
{
    private const STATUS_MENUNGGU_APPROVAL = ['diajukan', 'menunggu_approval'];
    private const STATUS_MENUNGGU_TRANSFER = ['disetujui', 'dicek', 'siap_transfer'];

    private const NETTO = "CASE WHEN arah = 'masuk' THEN nominal ELSE -nominal END";

    public function kunciPerusahaan(string $idPerusahaan): void
    {
        DB::table('perusahaan')
            ->where('id_perusahaan', $idPerusahaan)
            ->lockForUpdate()
            ->first(['id_perusahaan']);
    }

    public function daftarRekeningPerusahaan(string $idPerusahaan): array
    {
        return DB::table('rekening_perusahaan')
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->orderByDesc('utama')
            ->orderBy('nama_bank')
            ->orderBy('nomor_rekening')
            ->get(['id_rekening_perusahaan', 'nama_bank', 'nomor_rekening', 'atas_nama_rekening', 'utama'])
            ->all();
    }

    public function saldo(string $idPerusahaan): float
    {
        return round((float) $this->aktif($idPerusahaan)->sum(DB::raw(self::NETTO)), 2);
    }

    public function saldoSebelum(string $idPerusahaan, string $tanggal, bool $kunci = false): float
    {
        $query = $this->aktif($idPerusahaan)->where('tanggal', '<', $tanggal);
        if ($kunci) {
            $query->lockForUpdate();
        }

        return round((float) $query->sum(DB::raw(self::NETTO)), 2);
    }

    public function mutasiSejak(string $idPerusahaan, string $dari): array
    {
        return $this->aktif($idPerusahaan)
            ->where('tanggal', '>=', $dari)
            ->orderBy('tanggal')
            ->orderByRaw("CASE WHEN arah = 'masuk' THEN 0 ELSE 1 END")
            ->orderBy('dibuat_pada')
            ->orderBy('nomor_transaksi')
            ->lockForUpdate()
            ->get(['tanggal', 'arah', 'nominal'])
            ->all();
    }

    public function transaksiRentang(string $idPerusahaan, string $dari, string $sampai): array
    {
        return $this->dasarTransaksi()
            ->where('t.id_perusahaan', $idPerusahaan)
            ->whereBetween('t.tanggal', [$dari, $sampai])
            ->orderBy('t.tanggal')
            ->orderByRaw("CASE WHEN t.arah = 'masuk' THEN 0 ELSE 1 END")
            ->orderBy('t.dibuat_pada')
            ->orderBy('t.nomor_transaksi')
            ->get()
            ->all();
    }

    public function totalRentang(string $idPerusahaan, string $dari, string $sampai): object
    {
        return $this->aktif($idPerusahaan)
            ->whereBetween('tanggal', [$dari, $sampai])
            ->selectRaw("
                COALESCE(SUM(CASE WHEN arah = 'masuk' THEN nominal ELSE 0 END), 0) as masuk,
                COALESCE(SUM(CASE WHEN arah = 'keluar' THEN nominal ELSE 0 END), 0) as keluar
            ")
            ->first();
    }

    public function adaTransaksi(string $idPerusahaan): bool
    {
        return $this->aktif($idPerusahaan)->exists();
    }

    public function findSaldoAwal(string $idPerusahaan): ?object
    {
        return $this->aktif($idPerusahaan)
            ->where('jenis', KasKecilService::JENIS_SALDO_AWAL)
            ->first();
    }

    public function findTransaksi(string $id): ?object
    {
        return $this->dasarTransaksi()->where('t.id_transaksi', $id)->first();
    }

    public function nomorTransaksiBerikutnya(string $idPerusahaan): string
    {
        $prefix   = 'KK-' . now()->format('Ym') . '-';
        $terakhir = DB::table('kas_kecil_transaksi')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('nomor_transaksi', 'like', $prefix . '%')
            ->lockForUpdate()
            ->max('nomor_transaksi');
        $urut = $terakhir ? ((int) substr((string) $terakhir, -4)) + 1 : 1;

        return $prefix . str_pad((string) $urut, 4, '0', STR_PAD_LEFT);
    }

    public function createTransaksi(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_transaksi');
        DB::table('kas_kecil_transaksi')->insert($data);

        return $this->findTransaksi($data['id_transaksi']);
    }

    public function updateTransaksi(object $record, array $data): object
    {
        DB::table('kas_kecil_transaksi')
            ->where('id_transaksi', $record->id_transaksi)
            ->update(RecordHelper::stampUpdate($data));

        return $this->findTransaksi($record->id_transaksi);
    }

    public function deleteTransaksi(object $record): void
    {
        DB::table('kas_kecil_transaksi')
            ->where('id_transaksi', $record->id_transaksi)
            ->update(RecordHelper::stampDelete());
    }

    public function penerimaanPengisianAda(string $idPengisian): bool
    {
        return DB::table('kas_kecil_transaksi')
            ->where('id_pengisian', $idPengisian)
            ->lockForUpdate()
            ->exists();
    }

    public function paginatePengisian(
        string $idPerusahaan,
        int $page,
        int $limit,
        ?string $search = null,
        ?string $status = null,
    ): LengthAwarePaginator {
        $query = $this->dasarPengisian()->where('k.id_perusahaan', $idPerusahaan);

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('k.nomor_pengisian', 'like', "%{$search}%")
                    ->orWhere('k.keterangan', 'like', "%{$search}%");
            });
        }

        match ($status) {
            KasKecilService::STATUS_MENUNGGU_APPROVAL => $query->where(
                fn ($q) => $q->whereIn('pp.status', self::STATUS_MENUNGGU_APPROVAL)->orWhereNull('pp.status')
            ),
            KasKecilService::STATUS_MENUNGGU_TRANSFER => $query->whereIn('pp.status', self::STATUS_MENUNGGU_TRANSFER),
            KasKecilService::STATUS_DITOLAK           => $query->where('pp.status', 'ditolak'),
            KasKecilService::STATUS_SELESAI           => $query->where('pp.status', 'ditransfer'),
            default                                   => null,
        };

        return $query
            ->orderByDesc('k.tanggal')
            ->orderByDesc('k.nomor_pengisian')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findPengisian(string $id): ?object
    {
        return $this->dasarPengisian()->where('k.id_pengisian', $id)->first();
    }

    public function kunciPengisian(string $id): void
    {
        DB::table('kas_kecil_pengisian')
            ->where('id_pengisian', $id)
            ->lockForUpdate()
            ->first(['id_pengisian']);
    }

    public function nomorPengisianBerikutnya(string $idPerusahaan): string
    {
        $prefix   = 'PKK-' . now()->format('Ym') . '-';
        $terakhir = DB::table('kas_kecil_pengisian')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('nomor_pengisian', 'like', $prefix . '%')
            ->lockForUpdate()
            ->max('nomor_pengisian');
        $urut = $terakhir ? ((int) substr((string) $terakhir, -4)) + 1 : 1;

        return $prefix . str_pad((string) $urut, 4, '0', STR_PAD_LEFT);
    }

    public function createPengisian(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_pengisian');
        DB::table('kas_kecil_pengisian')->insert($data);

        return $this->findPengisian($data['id_pengisian']);
    }

    public function updatePengisian(object $record, array $data): object
    {
        DB::table('kas_kecil_pengisian')
            ->where('id_pengisian', $record->id_pengisian)
            ->update(RecordHelper::stampUpdate($data));

        return $this->findPengisian($record->id_pengisian);
    }

    public function deletePengisian(object $record): void
    {
        DB::table('kas_kecil_pengisian')
            ->where('id_pengisian', $record->id_pengisian)
            ->update(RecordHelper::stampDelete());
    }

    public function pengisianDiproses(string $idPerusahaan): object
    {
        return DB::table('kas_kecil_pengisian as k')
            ->join('pengajuan_pengeluaran as pp', function (JoinClause $join) {
                $join->on('pp.id_pengajuan', '=', 'k.id_pengajuan')->whereNull('pp.dihapus_pada');
            })
            ->where('k.id_perusahaan', $idPerusahaan)
            ->whereNull('k.dihapus_pada')
            ->whereIn('pp.status', [...self::STATUS_MENUNGGU_APPROVAL, ...self::STATUS_MENUNGGU_TRANSFER])
            ->selectRaw('COUNT(*) as jumlah, COALESCE(SUM(k.nominal), 0) as nominal')
            ->first();
    }

    private function aktif(string $idPerusahaan): Builder
    {
        return DB::table('kas_kecil_transaksi')
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada');
    }

    private function dasarTransaksi(): Builder
    {
        return DB::table('kas_kecil_transaksi as t')
            ->leftJoin('pengguna as u', 'u.id_pengguna', '=', 't.dibuat_oleh')
            ->leftJoin('kas_kecil_pengisian as p', 'p.id_pengisian', '=', 't.id_pengisian')
            ->whereNull('t.dihapus_pada')
            ->select(['t.*', 'u.username as dicatat_oleh', 'p.nomor_pengisian']);
    }

    private function dasarPengisian(): Builder
    {
        return DB::table('kas_kecil_pengisian as k')
            ->leftJoin('pengajuan_pengeluaran as pp', function (JoinClause $join) {
                $join->on('pp.id_pengajuan', '=', 'k.id_pengajuan')->whereNull('pp.dihapus_pada');
            })
            ->leftJoin('pengguna as u', 'u.id_pengguna', '=', 'k.dibuat_oleh')
            ->whereNull('k.dihapus_pada')
            ->select([
                'k.*',
                'pp.nomor_pengajuan',
                'pp.status as status_pengajuan',
                'pp.alasan_ditolak',
                'pp.tanggal_transfer',
                'pp.url_bukti as url_bukti_transfer',
                'u.username as dibuat_oleh_nama',
            ]);
    }
}
