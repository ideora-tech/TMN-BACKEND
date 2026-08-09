<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proyek_rute', function (Blueprint $table) {
            $table->integer('estimasi_ritase')->default(1)->after('harga_penawaran');
        });

        $rows = DB::table('penawaran')
            ->join('penawaran_item', 'penawaran_item.id_penawaran', '=', 'penawaran.id_penawaran')
            ->whereNotNull('penawaran.id_proyek')
            ->whereNull('penawaran.dihapus_pada')
            ->whereNull('penawaran_item.dihapus_pada')
            ->orderByDesc('penawaran_item.dibuat_pada')
            ->select(
                'penawaran.id_proyek',
                'penawaran_item.id_rute',
                'penawaran_item.id_jenis_kendaraan',
                'penawaran_item.estimasi_ritase',
            )
            ->get();

        foreach ($rows as $row) {
            DB::table('proyek_rute')
                ->where('id_proyek', $row->id_proyek)
                ->where('id_rute', $row->id_rute)
                ->where('id_jenis_kendaraan', $row->id_jenis_kendaraan)
                ->whereNull('dihapus_pada')
                ->update(['estimasi_ritase' => (int) $row->estimasi_ritase]);
        }
    }

    public function down(): void
    {
        Schema::table('proyek_rute', function (Blueprint $table) {
            $table->dropColumn('estimasi_ritase');
        });
    }
};
