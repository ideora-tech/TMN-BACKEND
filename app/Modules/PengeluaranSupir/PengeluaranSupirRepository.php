<?php

declare(strict_types=1);

namespace App\Modules\PengeluaranSupir;

use App\Modules\PengeluaranSupir\Contracts\PengeluaranSupirRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PengeluaranSupirRepository implements PengeluaranSupirRepositoryInterface
{
    public function paginateBySupir(string $idSupir, int $page, int $limit, ?array $statusPengajuan = null): LengthAwarePaginator
    {
        return $this->dasar()
            ->where('ps.id_supir', $idSupir)
            ->when($statusPengajuan !== null, fn (Builder $q) => $q->whereIn('pp.status', $statusPengajuan))
            ->orderByDesc('ps.dibuat_pada')
            ->orderByDesc('ps.nomor')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?object
    {
        return $this->dasar()->where('ps.id_pengeluaran_supir', $id)->first();
    }

    public function fotoUntuk(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $hasil = [];
        $baris = DB::table('pengeluaran_supir_foto')
            ->whereIn('id_pengeluaran_supir', $ids)
            ->whereNull('dihapus_pada')
            ->orderBy('urutan')
            ->get(['id_pengeluaran_supir', 'url_foto']);
        foreach ($baris as $satu) {
            $hasil[(string) $satu->id_pengeluaran_supir][] = (string) $satu->url_foto;
        }

        return $hasil;
    }

    public function kunciSupir(string $idSupir): void
    {
        DB::table('supir')->where('id_supir', $idSupir)->lockForUpdate()->first(['id_supir']);
    }

    public function kunci(string $id): void
    {
        DB::table('pengeluaran_supir')->where('id_pengeluaran_supir', $id)->lockForUpdate()->first(['id_pengeluaran_supir']);
    }

    public function adaKembaran(string $idSupir, string $jenis, ?string $idArmada, ?string $idArmadaVendor, string $tanggal, float $nominal, string $sejak): bool
    {
        return DB::table('pengeluaran_supir')
            ->whereNull('dihapus_pada')
            ->where('id_supir', $idSupir)
            ->where('jenis', $jenis)
            ->where('tanggal', $tanggal)
            ->where('nominal', $nominal)
            ->where('dibuat_pada', '>=', $sejak)
            ->when($idArmada !== null, fn (Builder $q) => $q->where('id_armada', $idArmada), fn (Builder $q) => $q->whereNull('id_armada'))
            ->when($idArmadaVendor !== null, fn (Builder $q) => $q->where('id_armada_vendor', $idArmadaVendor), fn (Builder $q) => $q->whereNull('id_armada_vendor'))
            ->exists();
    }

    public function jumlahSejak(string $idSupir, string $sejak): int
    {
        return DB::table('pengeluaran_supir')
            ->where('id_supir', $idSupir)
            ->where('dibuat_pada', '>=', $sejak)
            ->count();
    }

    public function nomorBerikutnya(string $idPerusahaan): string
    {
        $prefix   = 'BSP-' . now()->format('Ym') . '-';
        $terakhir = DB::table('pengeluaran_supir')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('nomor', 'like', $prefix . '%')
            ->lockForUpdate()
            ->max('nomor');
        $urut = $terakhir ? ((int) substr((string) $terakhir, -4)) + 1 : 1;

        return $prefix . str_pad((string) $urut, 4, '0', STR_PAD_LEFT);
    }

    public function create(array $data, array $urlFoto): object
    {
        $data = RecordHelper::stampCreate($data, 'id_pengeluaran_supir');
        DB::table('pengeluaran_supir')->insert($data);

        foreach (array_values($urlFoto) as $i => $url) {
            DB::table('pengeluaran_supir_foto')->insert(RecordHelper::stampCreate([
                'id_foto'              => (string) Str::uuid(),
                'id_pengeluaran_supir' => $data['id_pengeluaran_supir'],
                'url_foto'             => $url,
                'urutan'               => $i + 1,
            ], 'id_foto'));
        }

        return $this->findById($data['id_pengeluaran_supir']);
    }

    public function delete(object $record): void
    {
        DB::table('pengeluaran_supir_foto')
            ->where('id_pengeluaran_supir', $record->id_pengeluaran_supir)
            ->whereNull('dihapus_pada')
            ->update(RecordHelper::stampDelete());

        DB::table('pengeluaran_supir')
            ->where('id_pengeluaran_supir', $record->id_pengeluaran_supir)
            ->update(RecordHelper::stampDelete());
    }

    public function unitInternal(string $idSupir, string $idPerusahaan, ?string $idArmadaDefault, string $dari, string $sampai): array
    {
        $dariPenugasan = $this->penugasanSupir($idSupir, $dari, $sampai)
            ->whereNotNull('id_armada')
            ->pluck('id_armada');

        $dariPengganti = DB::table('jadwal_shift')
            ->whereNull('dihapus_pada')
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $asli) => $asli->where('id_supir', $idSupir)->whereNull('id_supir_pengganti'))
                ->orWhere('id_supir_pengganti', $idSupir))
            ->whereDate('tanggal', '>=', $dari)
            ->whereDate('tanggal', '<=', $sampai)
            ->whereNotNull('id_armada_override')
            ->pluck('id_armada_override');

        $ids = $dariPenugasan->merge($dariPengganti)->push($idArmadaDefault)->filter()->unique()->values()->all();
        if ($ids === []) {
            return [];
        }

        return DB::table('armada')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->whereIn('id_armada', $ids)
            ->orderBy('nopol')
            ->get(['id_armada', 'nopol', 'merk', 'model'])
            ->all();
    }

    public function unitVendor(string $idSupir, string $idPerusahaan, string $dari, string $sampai): array
    {
        $ids = $this->penugasanSupir($idSupir, $dari, $sampai)
            ->whereNotNull('id_armada_vendor')
            ->pluck('id_armada_vendor')
            ->unique()
            ->values()
            ->all();
        if ($ids === []) {
            return [];
        }

        return DB::table('armada_vendor as av')
            ->join('vendor as v', function (JoinClause $join) use ($idPerusahaan) {
                $join->on('v.id_vendor', '=', 'av.id_vendor')
                    ->where('v.id_perusahaan', $idPerusahaan)
                    ->whereNull('v.dihapus_pada');
            })
            ->whereNull('av.dihapus_pada')
            ->whereIn('av.id_armada_vendor', $ids)
            ->orderBy('av.nopol')
            ->get(['av.id_armada_vendor', 'av.nopol', 'av.merk', 'v.nama_vendor'])
            ->all();
    }

    public function rekeningTerakhir(string $idSupir): ?object
    {
        return DB::table('pengeluaran_supir')
            ->whereNull('dihapus_pada')
            ->where('id_supir', $idSupir)
            ->whereNotNull('nomor_rekening')
            ->orderByDesc('dibuat_pada')
            ->first(['nama_bank', 'nomor_rekening', 'atas_nama_rekening']);
    }

    public function rekeningKaryawan(string $idKaryawan, string $idPerusahaan): ?object
    {
        return DB::table('karyawan')
            ->whereNull('dihapus_pada')
            ->where('id_karyawan', $idKaryawan)
            ->where('id_perusahaan', $idPerusahaan)
            ->first(['nama_bank', 'nomor_rekening', 'atas_nama_rekening']);
    }

    private function penugasanSupir(string $idSupir, string $dari, string $sampai): Builder
    {
        return DB::table('penugasan')
            ->whereNull('dihapus_pada')
            ->where('id_supir', $idSupir)
            ->whereDate('tanggal_tugas', '>=', $dari)
            ->whereDate('tanggal_tugas', '<=', $sampai)
            ->where('status', '!=', 'batal');
    }

    private function dasar(): Builder
    {
        return DB::table('pengeluaran_supir as ps')
            ->leftJoin('pengajuan_pengeluaran as pp', function (JoinClause $join) {
                $join->on('pp.id_pengajuan', '=', 'ps.id_pengajuan')->whereNull('pp.dihapus_pada');
            })
            ->leftJoin('supir as s', 's.id_supir', '=', 'ps.id_supir')
            ->whereNull('ps.dihapus_pada')
            ->select([
                'ps.*',
                's.nama as nama_supir',
                'pp.nomor_pengajuan',
                'pp.status as status_pengajuan',
                'pp.alasan_ditolak',
                'pp.tanggal_transfer',
                'pp.url_bukti as url_bukti_bayar',
            ]);
    }
}
