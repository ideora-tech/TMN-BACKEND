<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private string $idIntervalPerawatan = 'm0000003-0000-4000-8000-000000000001';

    public function up(): void
    {
        DB::table('menu_peran')->where('id_menu', $this->idIntervalPerawatan)->delete();
        DB::table('menu')->where('id_menu', $this->idIntervalPerawatan)->update([
            'aktif'        => 0,
            'diubah_pada'  => now(),
            'dihapus_pada' => now(),
        ]);
    }

    public function down(): void
    {
        // Menu Interval Perawatan tidak dihapus permanen (soft: aktif=0) agar baris menu tetap ada.
        // No-op: restore aktif=1 + menu_peran butuh tahu role apa saja yang tadinya diberi
        // akses, kompleks untuk auto-restore — sama seperti pola hapus_menu_jadwal.
    }
};
