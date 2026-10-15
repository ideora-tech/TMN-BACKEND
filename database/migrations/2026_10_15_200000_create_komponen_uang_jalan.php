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
    private const BAWAAN = [
        ['nama' => 'Tol', 'urutan' => 1, 'kolom_rute' => 'estimasi_tol', 'kolom_uj' => 'tol_per_trip'],
        ['nama' => 'BBM', 'urutan' => 2, 'kolom_rute' => 'estimasi_bbm', 'kolom_uj' => 'bbm_per_trip'],
        ['nama' => 'Biaya Lain', 'urutan' => 3, 'kolom_rute' => 'estimasi_biaya_lain', 'kolom_uj' => 'biaya_lain_per_trip'],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('komponen_uang_jalan')) {
            Schema::create('komponen_uang_jalan', function (Blueprint $table) {
                $table->char('id_komponen_uang_jalan', 36)->primary();
                $table->char('id_perusahaan', 36);
                $table->string('nama', 100);
                $table->unsignedSmallInteger('urutan')->default(0);
                $table->tinyInteger('aktif')->default(1);
                MigrationHelper::auditColumns($table);
                $table->index('id_perusahaan', 'idx_komponen_uj_perusahaan');
            });
        }

        if (!Schema::hasTable('proyek_rute_komponen')) {
            Schema::create('proyek_rute_komponen', function (Blueprint $table) {
                $table->char('id_proyek_rute_komponen', 36)->primary();
                $table->char('id_perusahaan', 36);
                $table->char('id_proyek_rute', 36);
                $table->char('id_komponen_uang_jalan', 36);
                $table->decimal('nominal', 15, 2)->default(0);
                MigrationHelper::auditColumns($table);
                $table->index('id_proyek_rute', 'idx_prk_proyek_rute');
                $table->index('id_komponen_uang_jalan', 'idx_prk_komponen');
            });
        }

        if (!Schema::hasTable('uang_jalan_rincian')) {
            Schema::create('uang_jalan_rincian', function (Blueprint $table) {
                $table->char('id_uang_jalan_rincian', 36)->primary();
                $table->char('id_perusahaan', 36);
                $table->char('id_uang_jalan', 36);
                $table->char('id_komponen_uang_jalan', 36)->nullable();
                $table->string('nama_komponen', 100);
                $table->unsignedSmallInteger('urutan')->default(0);
                $table->decimal('nominal', 15, 2)->default(0);
                MigrationHelper::auditColumns($table);
                $table->index('id_uang_jalan', 'idx_ujr_uang_jalan');
                $table->index('id_komponen_uang_jalan', 'idx_ujr_komponen');
            });
        }

        if (DB::table('komponen_uang_jalan')->exists()) {
            return;
        }

        DB::transaction(fn () => $this->pindahkanData());
    }

    public function down(): void
    {
        Schema::dropIfExists('uang_jalan_rincian');
        Schema::dropIfExists('proyek_rute_komponen');
        Schema::dropIfExists('komponen_uang_jalan');
    }

    private function pindahkanData(): void
    {
        $now  = now();
        $peta = [];
        foreach (DB::table('perusahaan')->whereNull('dihapus_pada')->pluck('id_perusahaan') as $idPerusahaan) {
            foreach (self::BAWAAN as $b) {
                $id = (string) Str::uuid();
                DB::table('komponen_uang_jalan')->insert([
                    'id_komponen_uang_jalan' => $id,
                    'id_perusahaan'          => $idPerusahaan,
                    'nama'                   => $b['nama'],
                    'urutan'                 => $b['urutan'],
                    'aktif'                  => 1,
                    'dibuat_pada'            => $now,
                ]);
                $peta[(string) $idPerusahaan][$b['nama']] = $id;
            }
        }

        DB::table('proyek_rute')
            ->whereNull('dihapus_pada')
            ->orderBy('id_proyek_rute')
            ->select(['id_proyek_rute', 'id_perusahaan', 'uang_jalan', 'estimasi_tol', 'estimasi_bbm', 'estimasi_biaya_lain'])
            ->chunk(500, function ($baris) use ($peta, $now) {
                foreach ($baris as $r) {
                    $komponen = $peta[(string) $r->id_perusahaan] ?? null;
                    if ($komponen === null) {
                        continue;
                    }
                    foreach ($this->rincianDenganSelisih($r, 'kolom_rute', $r->uang_jalan) as $nama => $nominal) {
                        DB::table('proyek_rute_komponen')->insert([
                            'id_proyek_rute_komponen' => (string) Str::uuid(),
                            'id_perusahaan'           => $r->id_perusahaan,
                            'id_proyek_rute'          => $r->id_proyek_rute,
                            'id_komponen_uang_jalan'  => $komponen[$nama],
                            'nominal'                 => $nominal,
                            'dibuat_pada'             => $now,
                        ]);
                    }
                }
            });

        DB::table('uang_jalan')
            ->whereNull('dihapus_pada')
            ->orderBy('id_uang_jalan')
            ->select(['id_uang_jalan', 'id_perusahaan', 'uang_jalan_per_trip', 'tol_per_trip', 'bbm_per_trip', 'biaya_lain_per_trip'])
            ->chunk(500, function ($baris) use ($peta, $now) {
                foreach ($baris as $r) {
                    $komponen = $peta[(string) $r->id_perusahaan] ?? null;
                    $urutan = 0;
                    foreach ($this->rincianDenganSelisih($r, 'kolom_uj', $r->uang_jalan_per_trip) as $nama => $nominal) {
                        DB::table('uang_jalan_rincian')->insert([
                            'id_uang_jalan_rincian'  => (string) Str::uuid(),
                            'id_perusahaan'          => $r->id_perusahaan,
                            'id_uang_jalan'          => $r->id_uang_jalan,
                            'id_komponen_uang_jalan' => $komponen[$nama] ?? null,
                            'nama_komponen'          => $nama,
                            'urutan'                 => ++$urutan,
                            'nominal'                => $nominal,
                            'dibuat_pada'            => $now,
                        ]);
                    }
                }
            });
    }

    private function rincianDenganSelisih(object $baris, string $jenisKolom, mixed $total): array
    {
        $nilai = [];
        foreach (self::BAWAAN as $b) {
            $nominal = round((float) ($baris->{$b[$jenisKolom]} ?? 0), 2);
            if ($nominal > 0) {
                $nilai[$b['nama']] = $nominal;
            }
        }

        $selisih = round((float) ($total ?? 0) - array_sum($nilai), 2);
        if ($selisih > 0) {
            $nilai['Biaya Lain'] = round(($nilai['Biaya Lain'] ?? 0) + $selisih, 2);
        }

        $urut = [];
        foreach (self::BAWAAN as $b) {
            if (isset($nilai[$b['nama']])) {
                $urut[$b['nama']] = $nilai[$b['nama']];
            }
        }

        return $urut;
    }
};
