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
        Schema::table('sparepart_foto', function (Blueprint $table) {
            $table->unsignedSmallInteger('urutan')->default(0)->after('nama_asli');
        });

        $nomor = [];
        DB::table('sparepart_foto')
            ->orderBy('dibuat_pada')
            ->orderBy('id_foto')
            ->get(['id_foto', 'id_sparepart'])
            ->each(function ($row) use (&$nomor) {
                $nomor[$row->id_sparepart] = ($nomor[$row->id_sparepart] ?? 0) + 1;

                DB::table('sparepart_foto')
                    ->where('id_foto', $row->id_foto)
                    ->update(['urutan' => $nomor[$row->id_sparepart]]);
            });
    }

    public function down(): void
    {
        Schema::table('sparepart_foto', function (Blueprint $table) {
            $table->dropColumn('urutan');
        });
    }
};
