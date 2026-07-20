<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('menu')->where('path', '/jadwal')->pluck('id_menu');

        DB::table('menu_peran')->whereIn('id_menu', $ids)->delete();
        DB::table('menu')->whereIn('id_menu', $ids)->update(['aktif' => 0, 'diubah_pada' => now()]);
    }

    public function down(): void
    {
        // Menu Jadwal tidak dihapus permanen (soft: aktif=0) agar baris menu tetap ada
        // untuk join otorisasi di CheckIzinPeran (endpoint jadwal existing dibiarkan
        // hidup untuk kompatibilitas). No-op: restore aktif=1 + menu_peran butuh tahu
        // role apa saja yang tadinya diberi akses, kompleks untuk auto-restore.
    }
};
