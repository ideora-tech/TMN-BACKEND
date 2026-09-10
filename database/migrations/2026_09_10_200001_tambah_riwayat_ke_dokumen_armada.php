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
        Schema::table('dokumen_armada', function (Blueprint $table) {
            $table->tinyInteger('aktif')->default(1)->after('url_file');
            $table->char('id_dokumen_sebelumnya', 36)->nullable()->after('aktif');
            $table->index(['id_armada', 'jenis_dokumen', 'aktif'], 'dokumen_armada_armada_jenis_aktif_idx');
        });

        $grupDuplikat = DB::table('dokumen_armada')
            ->whereNull('dihapus_pada')
            ->where('jenis_dokumen', '<>', 'Lainnya')
            ->select('id_armada', 'jenis_dokumen')
            ->groupBy('id_armada', 'jenis_dokumen')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($grupDuplikat as $grup) {
            $ids = DB::table('dokumen_armada')
                ->whereNull('dihapus_pada')
                ->where('id_armada', $grup->id_armada)
                ->where('jenis_dokumen', $grup->jenis_dokumen)
                ->orderByRaw('berlaku_sampai IS NULL')
                ->orderByDesc('berlaku_sampai')
                ->orderByDesc('dibuat_pada')
                ->pluck('id_dokumen_armada')
                ->values();

            foreach ($ids as $urutan => $id) {
                DB::table('dokumen_armada')
                    ->where('id_dokumen_armada', $id)
                    ->update([
                        'aktif'                 => $urutan === 0 ? 1 : 0,
                        'id_dokumen_sebelumnya' => $ids[$urutan + 1] ?? null,
                    ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('dokumen_armada', function (Blueprint $table) {
            $table->dropIndex('dokumen_armada_armada_jenis_aktif_idx');
            $table->dropColumn(['aktif', 'id_dokumen_sebelumnya']);
        });
    }
};
