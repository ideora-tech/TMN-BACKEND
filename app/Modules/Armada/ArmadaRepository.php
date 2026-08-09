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

    /**
     * Warning servis berbasis KILOMETER: per armada × aturan interval_km jenis
     * kendaraannya, hitung sisa km = (km servis terakhir jenis itu + interval_km)
     * − odometer terakhir armada. Masuk daftar bila sisa ≤ 10% interval_km
     * (termasuk negatif = lewat). Armada tanpa riwayat km dilewati.
     */
    public function findServisJatuhTempoKm(string $idPerusahaan): array
    {
        $rules = DB::table('interval_perawatan as ip')
            ->join('jenis_perawatan as jp', 'jp.id_jenis_perawatan', '=', 'ip.id_jenis_perawatan')
            ->whereNull('ip.dihapus_pada')
            ->whereNull('jp.dihapus_pada')
            ->where('ip.id_perusahaan', $idPerusahaan)
            ->where('ip.aktif', 1)
            ->whereNotNull('ip.interval_km')
            ->get(['ip.id_jenis_kendaraan', 'ip.id_jenis_perawatan', 'ip.interval_km', 'jp.nama as nama_jenis_perawatan']);

        if ($rules->isEmpty()) {
            return [];
        }

        $armadaList = DB::table('armada')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->whereIn('id_jenis_kendaraan', $rules->pluck('id_jenis_kendaraan')->unique()->all())
            ->get(['id_armada', 'nopol', 'id_jenis_kendaraan']);

        if ($armadaList->isEmpty()) {
            return [];
        }

        $armadaIds = $armadaList->pluck('id_armada')->all();

        $kmSekarangMap = DB::table('perawatan_armada')
            ->whereNull('dihapus_pada')
            ->whereIn('id_armada', $armadaIds)
            ->whereNotNull('km_odometer')
            ->groupBy('id_armada')
            ->selectRaw('id_armada, MAX(km_odometer) as km_terakhir')
            ->pluck('km_terakhir', 'id_armada');

        $servisTerakhir = DB::table('perawatan_armada as p1')
            ->whereNull('p1.dihapus_pada')
            ->whereIn('p1.id_armada', $armadaIds)
            ->where('p1.status', 'selesai')
            ->whereNotNull('p1.id_jenis_perawatan')
            ->whereNotNull('p1.km_odometer')
            ->whereRaw('p1.id_perawatan = (
                SELECT p2.id_perawatan FROM perawatan_armada p2
                WHERE p2.id_armada = p1.id_armada
                  AND p2.id_jenis_perawatan = p1.id_jenis_perawatan
                  AND p2.status = \'selesai\'
                  AND p2.km_odometer IS NOT NULL
                  AND p2.dihapus_pada IS NULL
                ORDER BY p2.tanggal DESC, p2.dibuat_pada DESC
                LIMIT 1
            )')
            ->get(['p1.id_armada', 'p1.id_jenis_perawatan', 'p1.km_odometer']);

        $servisMap = [];
        foreach ($servisTerakhir as $row) {
            $servisMap[$row->id_armada . '|' . $row->id_jenis_perawatan] = (int) $row->km_odometer;
        }

        $rulesPerKendaraan = $rules->groupBy('id_jenis_kendaraan');

        $hasil = [];
        foreach ($armadaList as $armada) {
            $kmSekarang = $kmSekarangMap[$armada->id_armada] ?? null;
            if ($kmSekarang === null) {
                continue;
            }

            foreach ($rulesPerKendaraan->get($armada->id_jenis_kendaraan, collect()) as $rule) {
                $kmServis = $servisMap[$armada->id_armada . '|' . $rule->id_jenis_perawatan] ?? null;
                if ($kmServis === null) {
                    continue;
                }

                $intervalKm = (int) $rule->interval_km;
                $sisaKm = ($kmServis + $intervalKm) - (int) $kmSekarang;
                $ambang = max(1, (int) round($intervalKm * 0.1));
                if ($sisaKm > $ambang) {
                    continue;
                }

                $hasil[] = [
                    'id_armada'       => $armada->id_armada,
                    'nopol'           => $armada->nopol,
                    'jenis_perawatan' => $rule->nama_jenis_perawatan,
                    'km_jatuh_tempo'  => $kmServis + $intervalKm,
                    'km_sekarang'     => (int) $kmSekarang,
                    'sisa_km'         => $sisaKm,
                ];
            }
        }

        usort($hasil, fn ($a, $b) => $a['sisa_km'] <=> $b['sisa_km']);
        return $hasil;
    }

    public function hitungStatusArmada(string $idPerusahaan): array
    {
        $rows = DB::table('armada')
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as jumlah')
            ->pluck('jumlah', 'status');

        return [
            'total'       => (int) $rows->sum(),
            'tersedia'    => (int) ($rows['tersedia'] ?? 0),
            'digunakan'   => (int) ($rows['digunakan'] ?? 0),
            'perawatan'   => (int) ($rows['perawatan'] ?? 0),
            'tidak_aktif' => (int) ($rows['tidak_aktif'] ?? 0),
        ];
    }

    public function findPerawatanAktif(string $idPerusahaan): array
    {
        return DB::table('perawatan_armada as p')
            ->join('armada as a', 'a.id_armada', '=', 'p.id_armada')
            ->leftJoin('jenis_perawatan as jp', 'jp.id_jenis_perawatan', '=', 'p.id_jenis_perawatan')
            ->where('a.id_perusahaan', $idPerusahaan)
            ->whereNull('p.dihapus_pada')
            ->whereNull('a.dihapus_pada')
            ->whereIn('p.status', ['terjadwal', 'dalam_proses'])
            ->orderByRaw("CASE p.status WHEN 'dalam_proses' THEN 0 ELSE 1 END")
            ->orderBy('p.tanggal')
            ->select(
                'p.id_perawatan',
                'p.id_armada',
                'a.nopol',
                DB::raw('COALESCE(jp.nama, p.jenis_perawatan) as jenis_perawatan'),
                'p.tanggal',
                'p.status',
                'p.km_odometer',
            )
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }
}
