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

    public function findById(string $id): ?object
    {
        return DB::table('alokasi_armada')
            ->whereNull('dihapus_pada')
            ->where('id_alokasi', $id)
            ->first();
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

    public function update(string $id, array $data): void
    {
        DB::table('alokasi_armada')
            ->where('id_alokasi', $id)
            ->update(RecordHelper::stampUpdate($data));
    }

    public function softDeleteNonManual(string $idSupir, string $tanggal): void
    {
        DB::table('alokasi_armada')
            ->whereNull('dihapus_pada')
            ->where('id_supir', $idSupir)
            ->where('tanggal', $tanggal)
            ->where('sumber', '!=', 'manual')
            ->update(RecordHelper::stampDelete());
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

    public function supirRow(string $idSupir): ?object
    {
        return DB::table('supir')
            ->whereNull('dihapus_pada')
            ->where('id_supir', $idSupir)
            ->first();
    }

    public function armadaLayak(string $idArmada): ?object
    {
        return DB::table('armada')
            ->whereNull('dihapus_pada')
            ->whereNotIn('status', ['perawatan', 'tidak_aktif'])
            ->where('id_armada', $idArmada)
            ->first();
    }

    public function armadaTerpakai(string $idArmada, string $tanggal, ?string $excludeIdAlokasi = null): bool
    {
        return DB::table('alokasi_armada')
            ->whereNull('dihapus_pada')
            ->where('id_armada', $idArmada)
            ->where('tanggal', $tanggal)
            ->when($excludeIdAlokasi, fn ($q, $v) => $q->where('id_alokasi', '!=', $v))
            ->exists();
    }

    /**
     * Armada nganggur tanggal T (kepemilikan dibaca dari PENUGASAN, bukan master):
     * armada layak, belum dialokasikan tanggal itu, dan TIDAK ADA penugasan aktif
     * yang memegang armada tsb dengan supir yang benar-benar masuk hari itu
     * (dijadwalkan shift dan tidak sedang cuti disetujui).
     */
    public function cariArmadaNganggur(string $idPerusahaan, string $tanggal, ?string $idProyek = null): array
    {
        return DB::table('armada as a')
            ->where('a.id_perusahaan', $idPerusahaan)
            ->whereNull('a.dihapus_pada')
            ->whereNotIn('a.status', ['perawatan', 'tidak_aktif'])
            // Batasi ke lingkup proyek: mobil pegangan penugasan proyek LAIN
            // tidak boleh dipinjam (mobil bebas tanpa penugasan tetap boleh).
            ->when($idProyek, fn ($q, $v) => $q->whereNotExists(function ($q2) use ($v) {
                $q2->select(DB::raw(1))->from('penugasan as pgl')
                   ->whereColumn('pgl.id_armada', 'a.id_armada')
                   ->whereNull('pgl.dihapus_pada')
                   ->whereIn('pgl.status', ['pending', 'aktif'])
                   ->where('pgl.id_proyek', '!=', $v);
            }))
            ->whereNotExists(function ($q) use ($tanggal) {
                $q->select(DB::raw(1))->from('alokasi_armada as al')
                  ->whereColumn('al.id_armada', 'a.id_armada')
                  ->whereNull('al.dihapus_pada')
                  ->where('al.tanggal', $tanggal);
            })
            ->whereNotExists(function ($q) use ($tanggal) {
                $q->select(DB::raw(1))->from('penugasan as pg')
                  ->whereColumn('pg.id_armada', 'a.id_armada')
                  ->whereNull('pg.dihapus_pada')
                  ->whereIn('pg.status', ['pending', 'aktif'])
                  ->whereNotNull('pg.id_supir')
                  ->whereExists(function ($q2) use ($tanggal) {
                      $q2->select(DB::raw(1))->from('jadwal_shift as js')
                         ->whereColumn('js.id_supir', 'pg.id_supir')
                         ->whereNull('js.dihapus_pada')
                         ->where('js.tanggal', $tanggal);
                  })
                  ->whereNotExists(function ($q3) use ($tanggal) {
                      $q3->select(DB::raw(1))->from('pengajuan_cuti as pc')
                         ->whereColumn('pc.id_supir', 'pg.id_supir')
                         ->whereNull('pc.dihapus_pada')
                         ->where('pc.status', 'disetujui')
                         ->whereDate('pc.tanggal_mulai', '<=', $tanggal)
                         ->whereDate('pc.tanggal_selesai', '>=', $tanggal);
                  });
            })
            ->orderBy('a.nopol')
            ->select('a.id_armada', 'a.nopol')
            ->get()
            ->all();
    }

    public function pemilikCuti(string $idSupir, string $tanggal): bool
    {
        return DB::table('pengajuan_cuti')
            ->whereNull('dihapus_pada')
            ->where('id_supir', $idSupir)
            ->where('status', 'disetujui')
            ->whereDate('tanggal_mulai', '<=', $tanggal)
            ->whereDate('tanggal_selesai', '>=', $tanggal)
            ->exists();
    }

    public function cariPemegangArmada(string $idArmada): ?object
    {
        return DB::table('penugasan as pg')
            ->join('supir as s', 's.id_supir', '=', 'pg.id_supir')
            ->whereNull('pg.dihapus_pada')
            ->whereNull('s.dihapus_pada')
            ->whereIn('pg.status', ['pending', 'aktif'])
            ->where('pg.id_armada', $idArmada)
            ->orderByDesc('pg.dibuat_pada')
            ->select('s.id_supir', 's.nama')
            ->first();
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

}
