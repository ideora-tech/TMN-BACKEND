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
        Schema::create('permintaan_vendor_unit', function (Blueprint $table) {
            $table->char('id_permintaan_unit', 36)->primary();
            $table->char('id_permintaan', 36)->index();
            $table->char('id_jenis_kendaraan', 36)->nullable();
            $table->unsignedSmallInteger('jumlah_unit');
            $table->unsignedSmallInteger('urutan')->default(1);
            MigrationHelper::auditColumns($table);
        });

        $permintaan = DB::table('permintaan_vendor')
            ->whereNull('dihapus_pada')
            ->get(['id_permintaan', 'id_jenis_kendaraan', 'jumlah_unit', 'dibuat_pada']);

        foreach ($permintaan as $row) {
            DB::table('permintaan_vendor_unit')->insert([
                'id_permintaan_unit' => (string) Str::uuid(),
                'id_permintaan'      => $row->id_permintaan,
                'id_jenis_kendaraan' => $row->id_jenis_kendaraan,
                'jumlah_unit'        => $row->jumlah_unit,
                'urutan'             => 1,
                'dibuat_pada'        => $row->dibuat_pada ?? now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('permintaan_vendor_unit');
    }
};
