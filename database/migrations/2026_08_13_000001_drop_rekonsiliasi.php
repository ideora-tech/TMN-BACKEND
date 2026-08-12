<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('rekonsiliasi');

        $idMenu = DB::table('menu')->where('path', '/rekonsiliasi')->value('id_menu');
        if ($idMenu !== null) {
            DB::table('izin_peran')->where('id_menu', $idMenu)->delete();
            DB::table('menu_peran')->where('id_menu', $idMenu)->delete();
            DB::table('menu')->where('id_menu', $idMenu)->delete();
        }
    }

    public function down(): void
    {
        // Fitur rekonsiliasi dihapus; pemulihan dilakukan lewat implementasi ulang, bukan rollback.
    }
};
