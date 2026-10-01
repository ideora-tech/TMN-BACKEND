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
    public function up(): void
    {
        if (!Schema::hasTable('surat_jalan_trip')) {
            Schema::create('surat_jalan_trip', function (Blueprint $table) {
                $table->char('id_surat_jalan', 36)->primary();
                $table->char('id_laporan', 36)->index();
                $table->char('id_titik_drop', 36)->nullable()->index();
                $table->unsignedTinyInteger('urutan');
                $table->string('no_surat_jalan', 100);
                MigrationHelper::auditColumns($table);
            });
        }

        Schema::table('laporan_perjalanan', function (Blueprint $table) {
            $table->string('no_surat_jalan', 500)->nullable()->change();
        });

        DB::table('laporan_perjalanan as lp')
            ->whereNull('lp.dihapus_pada')
            ->whereNotNull('lp.no_surat_jalan')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('surat_jalan_trip as sj')->whereColumn('sj.id_laporan', 'lp.id_laporan'))
            ->orderBy('lp.id_laporan')
            ->select(['lp.id_laporan', 'lp.no_surat_jalan'])
            ->chunk(500, function ($baris) {
                $rows = [];
                foreach ($baris as $l) {
                    $daftar = array_values(array_filter(
                        array_map('trim', preg_split('/[,;]/', (string) $l->no_surat_jalan) ?: []),
                        fn ($no) => $no !== '',
                    ));
                    foreach ($daftar as $i => $no) {
                        $rows[] = [
                            'id_surat_jalan' => (string) Str::uuid(),
                            'id_laporan'     => $l->id_laporan,
                            'id_titik_drop'  => null,
                            'urutan'         => $i + 1,
                            'no_surat_jalan' => mb_substr($no, 0, 100),
                            'dibuat_pada'    => now(),
                        ];
                    }
                }
                if ($rows !== []) {
                    DB::table('surat_jalan_trip')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('surat_jalan_trip');
    }
};
