<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $idMenu = DB::table('menu')->where('path', '/tarif-rute')->whereNull('dihapus_pada')->pluck('id_menu');

        if ($idMenu->isNotEmpty()) {
            DB::table('izin_peran')->whereIn('id_menu', $idMenu)->delete();
            DB::table('menu')->whereIn('id_menu', $idMenu)->update([
                'dihapus_pada' => now(),
                'diubah_pada'  => now(),
            ]);
        }

        Schema::dropIfExists('tarif_rute');
    }

    public function down(): void
    {
    }
};
