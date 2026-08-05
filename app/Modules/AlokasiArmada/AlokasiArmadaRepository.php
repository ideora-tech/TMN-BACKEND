<?php

declare(strict_types=1);

namespace App\Modules\AlokasiArmada;

use App\Modules\AlokasiArmada\Contracts\AlokasiArmadaRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AlokasiArmadaRepository implements AlokasiArmadaRepositoryInterface
{
    public function paginate(string $idPerusahaan, int $page, int $limit, ?string $dari = null, ?string $sampai = null, ?string $search = null, ?string $idArmada = null, ?string $idProyek = null): LengthAwarePaginator
    {
        return DB::table('alokasi_armada as al')
            ->join('supir as s', 's.id_supir', '=', 'al.id_supir')
            ->leftJoin('armada as a', 'a.id_armada', '=', 'al.id_armada')
            ->leftJoin('supir as p', 'p.id_supir', '=', 'al.id_pemilik_asal')
            ->leftJoin('proyek as pr', 'pr.id_proyek', '=', 'al.id_proyek')
            ->whereNull('al.dihapus_pada')
            ->where('s.id_perusahaan', $idPerusahaan)
            ->when($idArmada, fn ($q, $v) => $q->where('al.id_armada', $v))
            ->when($idProyek, fn ($q, $v) => $q->where('al.id_proyek', $v))
            ->when($dari, fn ($q, $v) => $q->where('al.tanggal', '>=', $v))
            ->when($sampai, fn ($q, $v) => $q->where('al.tanggal', '<=', $v))
            ->when($search, fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('s.nama', 'like', "%{$search}%")
                   ->orWhere('a.nopol', 'like', "%{$search}%");
            }))
            ->orderByDesc('al.tanggal')
            ->orderBy('s.nama')
            ->select(
                'al.id_alokasi', 'al.tanggal', 'al.id_proyek', 'al.id_supir', 'al.id_armada',
                'al.id_pemilik_asal', 'al.sumber', 'al.keterangan', 'al.dibuat_pada',
                's.nama as supir_nama', 'a.nopol as armada_nopol',
                'p.nama as pemilik_nama', 'pr.nama_proyek'
            )
            ->paginate($limit, ['*'], 'page', $page);
    }

    /**
     * Audit penuh: menyertakan baris yang sudah digantikan/dibatalkan
     * (soft-deleted) supaya pergerakan pemegang mobil terlihat utuh.
     */
    public function riwayatPerArmada(string $idArmada, ?string $dari = null, ?string $sampai = null): array
    {
        return DB::table('alokasi_armada as al')
            ->join('supir as s', 's.id_supir', '=', 'al.id_supir')
            ->leftJoin('supir as p', 'p.id_supir', '=', 'al.id_pemilik_asal')
            ->leftJoin('proyek as pr', 'pr.id_proyek', '=', 'al.id_proyek')
            ->where('al.id_armada', $idArmada)
            ->when($dari, fn ($q, $v) => $q->where('al.tanggal', '>=', $v))
            ->when($sampai, fn ($q, $v) => $q->where('al.tanggal', '<=', $v))
            ->orderBy('al.tanggal')
            ->orderBy('al.dibuat_pada')
            ->select(
                'al.tanggal', 'al.sumber', 'al.keterangan', 'al.dibuat_pada', 'al.dihapus_pada',
                's.nama as supir_nama', 'p.nama as pemilik_nama', 'pr.nama_proyek'
            )
            ->get()
            ->all();
    }

    public function findArmadaMilikPerusahaan(string $idArmada, string $idPerusahaan): ?object
    {
        return DB::table('armada')
            ->whereNull('dihapus_pada')
            ->where('id_armada', $idArmada)
            ->where('id_perusahaan', $idPerusahaan)
            ->first();
    }

    public function getPerusahaan(string $idPerusahaan): ?object
    {
        return DB::table('perusahaan')->where('id_perusahaan', $idPerusahaan)->first();
    }

    public function findAktifBySupirTanggal(string $idSupir, string $tanggal): ?object
    {
        return DB::table('alokasi_armada')
            ->whereNull('dihapus_pada')
            ->where('id_supir', $idSupir)
            ->where('tanggal', $tanggal)
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_alokasi');
        DB::table('alokasi_armada')->insert($data);
        return DB::table('alokasi_armada')->where('id_alokasi', $data['id_alokasi'])->first();
    }

    public function softDeleteById(string $idAlokasi): void
    {
        DB::table('alokasi_armada')
            ->where('id_alokasi', $idAlokasi)
            ->update(RecordHelper::stampDelete());
    }

    public function softDeleteSemua(string $idSupir, string $tanggal): void
    {
        DB::table('alokasi_armada')
            ->whereNull('dihapus_pada')
            ->where('id_supir', $idSupir)
            ->where('tanggal', $tanggal)
            ->update(RecordHelper::stampDelete());
    }

    public function jadwalMendatangSupirProyek(string $idSupir, string $idProyek, string $dariTanggal): array
    {
        return DB::table('jadwal_shift')
            ->whereNull('dihapus_pada')
            ->where('id_supir', $idSupir)
            ->where('id_proyek', $idProyek)
            ->where('tanggal', '>=', $dariTanggal)
            ->orderBy('tanggal')
            ->pluck('tanggal')
            ->all();
    }

    public function alokasiNopolMap(string $idSupir, string $dari, string $sampai): array
    {
        $rows = DB::table('alokasi_armada as al')
            ->join('armada as a', 'a.id_armada', '=', 'al.id_armada')
            ->whereNull('al.dihapus_pada')
            ->where('al.id_supir', $idSupir)
            ->whereBetween('al.tanggal', [$dari, $sampai])
            ->select('al.tanggal', 'al.id_armada', 'a.nopol')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[substr((string) $row->tanggal, 0, 10)] = [
                'id_armada' => $row->id_armada,
                'nopol'     => $row->nopol,
            ];
        }
        return $map;
    }

    public function penugasanAktifSupirProyek(string $idSupir, string $idProyek): ?object
    {
        return DB::table('penugasan')
            ->whereNull('dihapus_pada')
            ->where('id_supir', $idSupir)
            ->where('id_proyek', $idProyek)
            ->whereIn('status', ['pending', 'aktif'])
            ->orderByDesc('dibuat_pada')
            ->first();
    }

    /**
     * Kandidat armada yang boleh dipinjamkan ke supir shift pada satu tanggal:
     * (1) armada penugasan supir lain di proyek yang sama yang pemiliknya off
     *     (cuti disetujui ATAU tidak dijadwalkan shift tanggal itu), dan
     * (2) armada aktif tanpa pemegang penugasan sama sekali.
     * Armada berstatus perawatan/tidak_aktif atau yang sudah dialokasikan aktif
     * tanggal itu dikecualikan. Hasil diurutkan nopol.
     *
     * @return array<int, object{id_armada: string, nopol: string, id_pemilik_asal: ?string, keterangan: string}>
     */
    public function kandidatArmadaNganggur(string $idSupir, string $tanggal, string $idProyek): array
    {
        $idPerusahaan = DB::table('supir')->where('id_supir', $idSupir)->value('id_perusahaan');

        $dialokasikan = DB::table('alokasi_armada')
            ->whereNull('dihapus_pada')
            ->where('tanggal', $tanggal)
            ->whereNotNull('id_armada')
            ->pluck('id_armada')
            ->all();

        $milikSupirLain = DB::table('penugasan as pn')
            ->join('armada as a', function ($join) {
                $join->on('a.id_armada', '=', 'pn.id_armada')
                     ->whereNull('a.dihapus_pada');
            })
            ->whereNull('pn.dihapus_pada')
            ->where('pn.id_proyek', $idProyek)
            ->whereIn('pn.status', ['pending', 'aktif'])
            ->where('pn.id_supir', '!=', $idSupir)
            ->whereNotIn('a.status', ['perawatan', 'tidak_aktif'])
            ->when($dialokasikan !== [], fn ($q) => $q->whereNotIn('a.id_armada', $dialokasikan))
            ->select('a.id_armada', 'a.nopol', 'pn.id_supir as id_pemilik_asal')
            ->get();

        $hasil = [];
        foreach ($milikSupirLain as $row) {
            $pemilikCuti = DB::table('pengajuan_cuti')
                ->whereNull('dihapus_pada')
                ->where('id_supir', $row->id_pemilik_asal)
                ->where('status', 'disetujui')
                ->where('tanggal_mulai', '<=', $tanggal)
                ->where('tanggal_selesai', '>=', $tanggal)
                ->exists();

            $pemilikDijadwalkan = DB::table('jadwal_shift')
                ->whereNull('dihapus_pada')
                ->where('id_supir', $row->id_pemilik_asal)
                ->where('tanggal', $tanggal)
                ->exists();

            if (!$pemilikCuti && $pemilikDijadwalkan) {
                continue;
            }

            $hasil[] = (object) [
                'id_armada'       => $row->id_armada,
                'nopol'           => $row->nopol,
                'id_pemilik_asal' => $row->id_pemilik_asal,
                'keterangan'      => $pemilikCuti ? 'Pemilik cuti' : 'Pemilik tidak dijadwalkan',
            ];
        }

        $dipegang = DB::table('penugasan')
            ->whereNull('dihapus_pada')
            ->whereIn('status', ['pending', 'aktif'])
            ->whereNotNull('id_armada')
            ->pluck('id_armada')
            ->all();

        $tanpaPemilik = DB::table('armada')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNotIn('status', ['perawatan', 'tidak_aktif'])
            ->when($dipegang !== [], fn ($q) => $q->whereNotIn('id_armada', $dipegang))
            ->when($dialokasikan !== [], fn ($q) => $q->whereNotIn('id_armada', $dialokasikan))
            ->select('id_armada', 'nopol')
            ->get();

        foreach ($tanpaPemilik as $row) {
            $hasil[] = (object) [
                'id_armada'       => $row->id_armada,
                'nopol'           => $row->nopol,
                'id_pemilik_asal' => null,
                'keterangan'      => 'Armada tanpa pemilik',
            ];
        }

        usort($hasil, fn ($a, $b) => strcmp((string) $a->nopol, (string) $b->nopol));
        return $hasil;
    }

}
