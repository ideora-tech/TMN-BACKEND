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
        Schema::create('pengaturan_kode', function (Blueprint $table) {
            $table->char('id_pengaturan_kode', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->string('entitas', 50);
            $table->string('prefix', 20);
            $table->integer('panjang_digit')->default(4);
            $table->string('reset', 10)->default('tidak');
            MigrationHelper::auditColumns($table);

            $table->unique(['id_perusahaan', 'entitas'], 'pengaturan_kode_perusahaan_entitas_unik');
        });

        Schema::create('kode_sequence', function (Blueprint $table) {
            $table->char('id_sequence', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->string('entitas', 50);
            $table->string('periode', 10)->default('');
            $table->integer('nilai_terakhir')->default(0);

            $table->unique(['id_perusahaan', 'entitas', 'periode'], 'kode_sequence_perusahaan_entitas_periode_unik');
        });

        $defaults = [
            'proyek'    => ['prefix' => 'PRJ', 'panjang_digit' => 4, 'reset' => 'tahunan'],
            'rute'      => ['prefix' => 'RT',  'panjang_digit' => 4, 'reset' => 'tidak'],
            'penawaran' => ['prefix' => 'PNW', 'panjang_digit' => 4, 'reset' => 'bulanan'],
        ];

        $now = now();
        $perusahaan = DB::table('perusahaan')->whereNull('dihapus_pada')->pluck('id_perusahaan');
        foreach ($perusahaan as $idPerusahaan) {
            foreach ($defaults as $entitas => $aturan) {
                DB::table('pengaturan_kode')->insertOrIgnore([
                    'id_pengaturan_kode' => (string) Str::uuid(),
                    'id_perusahaan'      => $idPerusahaan,
                    'entitas'            => $entitas,
                    'prefix'             => $aturan['prefix'],
                    'panjang_digit'      => $aturan['panjang_digit'],
                    'reset'              => $aturan['reset'],
                    'dibuat_pada'        => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kode_sequence');
        Schema::dropIfExists('pengaturan_kode');
    }
};
