<?php

declare(strict_types=1);

namespace App\Modules\Armada;

use App\Modules\Armada\Contracts\ArmadaRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ArmadaRepository implements ArmadaRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $status, ?string $search = null): LengthAwarePaginator
    {
        $query = ArmadaModel::active()
            ->leftJoin('jenis_kendaraan', function ($join) {
                $join->on('jenis_kendaraan.id_jenis_kendaraan', '=', 'armada.id_jenis_kendaraan')
                    ->whereNull('jenis_kendaraan.dihapus_pada');
            })
            ->where('armada.id_perusahaan', $idPerusahaan)
            ->select('armada.*', 'jenis_kendaraan.nama_jenis')
            ->selectSub(
                DB::table('penugasan')
                    ->join('proyek', 'proyek.id_proyek', '=', 'penugasan.id_proyek')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('penugasan.id_armada', 'armada.id_armada')
                    ->whereNull('penugasan.dihapus_pada')
                    ->whereNull('proyek.dihapus_pada')
                    ->whereIn('penugasan.status', ['pending', 'aktif']),
                'jumlah_penugasan_aktif'
            )
            ->orderBy('armada.nopol');

        if ($status !== null) {
            $query->where('armada.status', $status);
        }

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('armada.nopol', 'like', "%{$search}%")
                  ->orWhere('armada.merk', 'like', "%{$search}%");
            });
        }

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?ArmadaModel
    {
        return ArmadaModel::active()->find($id);
    }

    public function countPenugasanAktif(string $idArmada): int
    {
        return (int) DB::table('penugasan')
            ->join('proyek', 'proyek.id_proyek', '=', 'penugasan.id_proyek')
            ->where('penugasan.id_armada', $idArmada)
            ->whereNull('penugasan.dihapus_pada')
            ->whereNull('proyek.dihapus_pada')
            ->whereIn('penugasan.status', ['pending', 'aktif'])
            ->count();
    }

    /**
     * Conditional update satu statement (atomic) — anti-TOCTOU: kunci hanya
     * berhasil bila armada tidak sedang perawatan/tidak_aktif, lepas hanya
     * bila memang sedang 'digunakan'. Status manual bengkel selalu menang.
     */
    public function tandaiDigunakanJikaSiap(string $idArmada): bool
    {
        return DB::table('armada')
            ->where('id_armada', $idArmada)
            ->whereNull('dihapus_pada')
            ->whereNotIn('status', ['perawatan', 'tidak_aktif'])
            ->update(['status' => 'digunakan', 'diubah_pada' => now()]) > 0;
    }

    public function lepaskanJikaDigunakan(string $idArmada): void
    {
        DB::table('armada')
            ->where('id_armada', $idArmada)
            ->whereNull('dihapus_pada')
            ->where('status', 'digunakan')
            ->update(['status' => 'tersedia', 'diubah_pada' => now()]);
    }

    public function findByNopol(string $nopol): ?ArmadaModel
    {
        return ArmadaModel::active()->where('nopol', $nopol)->first();
    }

    /**
     * Cari armada by nopol, di-scope ke perusahaan (dipakai oleh import supir
     * untuk matching id_armada_default). Perbandingan case-insensitive & trim
     * agar nopol dari file Excel tetap match walau beda kapitalisasi/spasi.
     */
    public function findByNopolMilikPerusahaan(string $nopol, string $idPerusahaan): ?ArmadaModel
    {
        return ArmadaModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->whereRaw('UPPER(TRIM(nopol)) = ?', [mb_strtoupper(trim($nopol))])
            ->first();
    }

    public function findByNomorRangka(string $nomorRangka): ?ArmadaModel
    {
        return ArmadaModel::active()->where('nomor_rangka', $nomorRangka)->first();
    }

    public function create(array $data): ArmadaModel
    {
        return ArmadaModel::create($data);
    }

    public function update(ArmadaModel $model, array $data): ArmadaModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(ArmadaModel $model): void
    {
        $model->softDelete();
    }

    public function findServisJatuhTempo(string $idPerusahaan, int $days): array
    {
        $batas = now()->addDays($days)->toDateString();

        return DB::table('perawatan_armada as p1')
            ->join('armada as a', 'a.id_armada', '=', 'p1.id_armada')
            ->where('a.id_perusahaan', $idPerusahaan)
            ->whereNull('p1.dihapus_pada')
            ->whereNull('a.dihapus_pada')
            ->whereNotNull('p1.jadwal_servis_berikutnya')
            ->where('p1.jadwal_servis_berikutnya', '<=', $batas)
            ->whereRaw('p1.id_perawatan = (
                SELECT p2.id_perawatan FROM perawatan_armada p2
                WHERE p2.id_armada = p1.id_armada AND p2.dihapus_pada IS NULL
                ORDER BY p2.tanggal DESC, p2.dibuat_pada DESC
                LIMIT 1
            )')
            ->select('a.id_armada', 'a.nopol', 'p1.jenis_perawatan', 'p1.jadwal_servis_berikutnya')
            ->orderBy('p1.jadwal_servis_berikutnya')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }
}
