<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('menu')->where('path', '/tarif-rute')->pluck('id_menu');

        DB::table('menu_peran')->whereIn('id_menu', $ids)->delete();
        DB::table('menu')->whereIn('id_menu', $ids)->update(['aktif' => 0, 'diubah_pada' => now()]);
    }

    public function down(): void
    {
        // Menu Tarif Rute tidak dihapus permanen (soft: aktif=0) agar baris menu tetap ada.
        // No-op: restore aktif=1 + menu_peran butuh tahu role apa saja yang tadinya diberi
        // akses, kompleks untuk auto-restore — sama seperti pola hapus_menu_jadwal.
    }
};
