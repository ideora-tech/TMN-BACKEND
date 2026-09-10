<?php

declare(strict_types=1);

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * paket_perawatan_sparepart berkunci (id_jenis_perawatan, id_jenis_kendaraan,
     * id_sparepart) — tidak cocok lagi karena Interval Perawatan sudah tidak
     * berkonsep jenis perawatan. Tabel baru ini berkunci langsung ke
     * id_interval_perawatan (satu jenis kendaraan boleh punya beberapa paket).
     *
     * Migration ini SEKALIGUS dedup interval_perawatan: sebelum interval_hari
     * dikonversi ke interval_bulan (migration sebelumnya), beberapa baris yang
     * dulunya berbeda id_jenis_perawatan bisa jadi identik pada kombinasi
     * (id_jenis_kendaraan, interval_km, interval_bulan). Untuk tiap kombinasi
     * begitu: sisakan satu baris (paling banyak sparepart; seri → dibuat_pada
     * paling awal), gabungkan (union, qty terbesar) sparepart SEMUA baris pada
     * kombinasi itu (termasuk yang kalah) ke baris yang disisakan, soft-delete
     * baris yang kalah, lalu null-kan id_jenis_perawatan baris yang disisakan.
     * Idempoten: baris yang sudah diproses punya id_jenis_perawatan NULL
     * sehingga tidak terjaring lagi pada run berikutnya.
     */
    public function up(): void
    {
        Schema::create('interval_perawatan_sparepart', function (Blueprint $table) {
            $table->char('id_interval_sparepart', 36)->primary();
            $table->char('id_interval_perawatan', 36);
            $table->char('id_sparepart', 36);
            $table->unsignedInteger('qty_standar');
            MigrationHelper::auditColumns($table);

            $table->index('id_interval_perawatan', 'interval_perawatan_sparepart_lookup_idx');
        });

        $this->dedupDanMigrasiSparepart();
    }

    private function dedupDanMigrasiSparepart(): void
    {
        $rows = DB::table('interval_perawatan')
            ->whereNull('dihapus_pada')
            ->whereNotNull('id_jenis_perawatan')
            ->whereNotNull('id_jenis_kendaraan')
            ->orderBy('dibuat_pada')
            ->get(['id_interval_perawatan', 'id_perusahaan', 'id_jenis_perawatan', 'id_jenis_kendaraan', 'interval_km', 'interval_bulan', 'dibuat_pada']);

        if ($rows->isEmpty()) {
            return;
        }

        $grup = [];
        foreach ($rows as $row) {
            $kunci = implode('|', [
                $row->id_perusahaan,
                $row->id_jenis_kendaraan,
                $row->interval_km ?? 'null',
                $row->interval_bulan ?? 'null',
            ]);
            $grup[$kunci][] = $row;
        }

        $now = now();

        foreach ($grup as $anggota) {
            $jumlahSparepart = [];
            foreach ($anggota as $row) {
                $jumlahSparepart[$row->id_interval_perawatan] = DB::table('paket_perawatan_sparepart')
                    ->whereNull('dihapus_pada')
                    ->where('id_jenis_perawatan', $row->id_jenis_perawatan)
                    ->where('id_jenis_kendaraan', $row->id_jenis_kendaraan)
                    ->count();
            }

            usort($anggota, function (object $a, object $b) use ($jumlahSparepart) {
                $countA = $jumlahSparepart[$a->id_interval_perawatan];
                $countB = $jumlahSparepart[$b->id_interval_perawatan];
                if ($countA !== $countB) {
                    return $countB <=> $countA;
                }
                return strcmp((string) $a->dibuat_pada, (string) $b->dibuat_pada);
            });

            $pemenang = $anggota[0];
            $kalah = array_slice($anggota, 1);

            $union = [];
            foreach ($anggota as $row) {
                $sparepartRows = DB::table('paket_perawatan_sparepart')
                    ->whereNull('dihapus_pada')
                    ->where('id_jenis_perawatan', $row->id_jenis_perawatan)
                    ->where('id_jenis_kendaraan', $row->id_jenis_kendaraan)
                    ->get(['id_sparepart', 'qty_standar']);

                foreach ($sparepartRows as $sp) {
                    $qty = (int) $sp->qty_standar;
                    if (!isset($union[$sp->id_sparepart]) || $union[$sp->id_sparepart] < $qty) {
                        $union[$sp->id_sparepart] = $qty;
                    }
                }
            }

            foreach ($union as $idSparepart => $qty) {
                DB::table('interval_perawatan_sparepart')->insert([
                    'id_interval_sparepart' => (string) Str::uuid(),
                    'id_interval_perawatan' => $pemenang->id_interval_perawatan,
                    'id_sparepart'          => $idSparepart,
                    'qty_standar'           => $qty,
                    'dibuat_pada'           => $now,
                ]);
            }

            foreach ($kalah as $row) {
                DB::table('interval_perawatan')
                    ->where('id_interval_perawatan', $row->id_interval_perawatan)
                    ->update(['dihapus_pada' => $now]);
            }

            DB::table('interval_perawatan')
                ->where('id_interval_perawatan', $pemenang->id_interval_perawatan)
                ->update(['id_jenis_perawatan' => null]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('interval_perawatan_sparepart');
    }
};
