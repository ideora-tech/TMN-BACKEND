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
        Schema::create('perawatan_jenis', function (Blueprint $table) {
            $table->char('id_perawatan_jenis', 36)->primary();
            $table->char('id_perawatan', 36)->index();
            $table->char('id_jenis_perawatan', 36);
            $table->date('jadwal_servis_berikutnya')->nullable();
            $table->unsignedSmallInteger('urutan')->default(1);
            MigrationHelper::auditColumns($table);
        });

        $perawatan = DB::table('perawatan_armada')
            ->whereNull('dihapus_pada')
            ->whereNotNull('id_jenis_perawatan')
            ->get(['id_perawatan', 'id_jenis_perawatan', 'jadwal_servis_berikutnya', 'dibuat_pada']);

        foreach ($perawatan as $row) {
            DB::table('perawatan_jenis')->insert([
                'id_perawatan_jenis'       => (string) Str::uuid(),
                'id_perawatan'             => $row->id_perawatan,
                'id_jenis_perawatan'       => $row->id_jenis_perawatan,
                'jadwal_servis_berikutnya' => $row->jadwal_servis_berikutnya,
                'urutan'                   => 1,
                'dibuat_pada'              => $row->dibuat_pada ?? now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('perawatan_jenis');
    }
};
