<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interval_perawatan', function (Blueprint $table) {
            $table->char('id_armada', 36)->nullable()->after('id_jenis_kendaraan');
            $table->char('id_jenis_kendaraan', 36)->nullable()->change();
            $table->index(['id_perusahaan', 'id_armada', 'id_jenis_perawatan'], 'interval_perawatan_armada_idx');
        });

        $templateLama = DB::table('interval_perawatan')
            ->whereNull('dihapus_pada')
            ->whereNull('id_armada')
            ->whereNotNull('id_jenis_kendaraan')
            ->get(['id_interval_perawatan', 'id_perusahaan', 'id_jenis_perawatan', 'id_jenis_kendaraan', 'interval_hari', 'interval_km', 'aktif']);

        foreach ($templateLama as $template) {
            $armadaAktif = DB::table('armada')
                ->whereNull('dihapus_pada')
                ->where('status', '!=', 'tidak_aktif')
                ->where('id_perusahaan', $template->id_perusahaan)
                ->where('id_jenis_kendaraan', $template->id_jenis_kendaraan)
                ->get(['id_armada']);

            foreach ($armadaAktif as $armada) {
                DB::table('interval_perawatan')->insert([
                    'id_interval_perawatan' => (string) Str::uuid(),
                    'id_perusahaan'         => $template->id_perusahaan,
                    'id_jenis_perawatan'    => $template->id_jenis_perawatan,
                    'id_jenis_kendaraan'    => null,
                    'id_armada'             => $armada->id_armada,
                    'interval_hari'         => $template->interval_hari,
                    'interval_km'           => $template->interval_km,
                    'aktif'                 => $template->aktif,
                    'dibuat_pada'           => now(),
                ]);
            }

            DB::table('interval_perawatan')
                ->where('id_interval_perawatan', $template->id_interval_perawatan)
                ->update(['dihapus_pada' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('interval_perawatan', function (Blueprint $table) {
            $table->dropIndex('interval_perawatan_armada_idx');
            $table->dropColumn('id_armada');
        });
    }
};
