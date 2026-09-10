<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Kebalikan dari 2026_09_09_100001: interval dibalik dari per-armada
     * (id_armada) ke per JENIS KENDARAAN (id_jenis_kendaraan). Untuk tiap
     * kombinasi (jenis kendaraan armada, jenis perawatan) yang muncul di baris
     * per-armada aktif, buat 1 baris template baru memakai nilai interval yang
     * paling banyak muncul (mode atas tuple interval_hari+interval_km; seri
     * dipecah dengan interval_km terkecil), lalu SEMUA baris per-armada
     * (id_armada tidak null) disoft-delete — termasuk yang armadanya tidak
     * (lagi) punya jenis_kendaraan, karena tidak ada lagi model per-armada
     * untuk ditempati. Idempoten: setelah baris per-armada disoft-delete,
     * jalan ulang migration ini tidak menemukan baris apapun untuk diproses.
     */
    public function up(): void
    {
        $rows = DB::table('interval_perawatan as ip')
            ->join('armada as a', 'a.id_armada', '=', 'ip.id_armada')
            ->whereNull('ip.dihapus_pada')
            ->whereNotNull('ip.id_armada')
            ->whereNull('a.dihapus_pada')
            ->whereNotNull('a.id_jenis_kendaraan')
            ->get([
                'ip.id_perusahaan',
                'ip.id_jenis_perawatan',
                'ip.interval_hari',
                'ip.interval_km',
                'a.id_jenis_kendaraan',
            ]);

        $grup = [];
        foreach ($rows as $row) {
            $kunciGrup = $row->id_perusahaan . '|' . $row->id_jenis_kendaraan . '|' . $row->id_jenis_perawatan;
            $grup[$kunciGrup]['meta'] = [
                'id_perusahaan'      => $row->id_perusahaan,
                'id_jenis_kendaraan' => $row->id_jenis_kendaraan,
                'id_jenis_perawatan' => $row->id_jenis_perawatan,
            ];

            $kunciTuple = ($row->interval_hari ?? 'null') . ':' . ($row->interval_km ?? 'null');
            $grup[$kunciGrup]['tuples'][$kunciTuple]['count'] = ($grup[$kunciGrup]['tuples'][$kunciTuple]['count'] ?? 0) + 1;
            $grup[$kunciGrup]['tuples'][$kunciTuple]['interval_hari'] = $row->interval_hari;
            $grup[$kunciGrup]['tuples'][$kunciTuple]['interval_km'] = $row->interval_km;
        }

        foreach ($grup as $satu) {
            $tuples = $satu['tuples'];
            uasort($tuples, function (array $a, array $b) {
                if ($a['count'] !== $b['count']) {
                    return $b['count'] <=> $a['count'];
                }
                $kmA = $a['interval_km'] ?? PHP_INT_MAX;
                $kmB = $b['interval_km'] ?? PHP_INT_MAX;
                return $kmA <=> $kmB;
            });
            $pemenang = reset($tuples);

            DB::table('interval_perawatan')->insert([
                'id_interval_perawatan' => (string) Str::uuid(),
                'id_perusahaan'         => $satu['meta']['id_perusahaan'],
                'id_jenis_perawatan'    => $satu['meta']['id_jenis_perawatan'],
                'id_jenis_kendaraan'    => $satu['meta']['id_jenis_kendaraan'],
                'id_armada'             => null,
                'interval_hari'         => $pemenang['interval_hari'],
                'interval_km'           => $pemenang['interval_km'],
                'aktif'                 => 1,
                'dibuat_pada'           => now(),
            ]);
        }

        DB::table('interval_perawatan')
            ->whereNull('dihapus_pada')
            ->whereNotNull('id_armada')
            ->update(['dihapus_pada' => now()]);
    }

    public function down(): void
    {
        // Collapse tidak reversible (sama seperti expand 2026_09_09_100001 kemarin) — data lama per-armada sudah digabung.
    }
};
