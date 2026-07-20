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
        Schema::create('proyek_rute', function (Blueprint $table) {
            $table->char('id_proyek_rute', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->char('id_proyek', 36);
            $table->char('id_rute', 36);
            $table->char('id_jenis_kendaraan', 36);
            $table->char('id_tarif_rute', 36)->nullable();
            $table->text('keterangan')->nullable();
            MigrationHelper::auditColumns($table);

            $table->index(['id_proyek'], 'proyek_rute_proyek_idx');
        });

        // Backfill: proyek yang sudah lebih dulu tertaut ke penawaran (id_proyek terisi)
        // sebelum tabel ini ada — salin item penawarannya supaya langsung punya baris rute.
        $rows = DB::table('penawaran')
            ->join('penawaran_item', 'penawaran_item.id_penawaran', '=', 'penawaran.id_penawaran')
            ->whereNotNull('penawaran.id_proyek')
            ->whereNull('penawaran.dihapus_pada')
            ->whereNull('penawaran_item.dihapus_pada')
            ->select(
                'penawaran.id_perusahaan',
                'penawaran.id_proyek',
                'penawaran_item.id_rute',
                'penawaran_item.id_jenis_kendaraan',
                'penawaran_item.id_tarif_rute',
            )
            ->get();

        $now = now();
        foreach ($rows as $row) {
            DB::table('proyek_rute')->insert([
                'id_proyek_rute'     => (string) Str::uuid(),
                'id_perusahaan'      => $row->id_perusahaan,
                'id_proyek'          => $row->id_proyek,
                'id_rute'            => $row->id_rute,
                'id_jenis_kendaraan' => $row->id_jenis_kendaraan,
                'id_tarif_rute'      => $row->id_tarif_rute,
                'dibuat_pada'        => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('proyek_rute');
    }
};
