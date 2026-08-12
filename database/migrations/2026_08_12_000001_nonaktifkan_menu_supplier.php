<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Supplier pindah jadi tab di halaman Pembelian Sparepart. Menu hanya
     * dinonaktifkan (bukan soft-delete) karena CheckIzinPeran mencari baris
     * menu ber-path '/supplier' yang belum dihapus — kalau dihapus, API
     * supplier ikut 403 padahal tab-nya masih memakainya.
     */
    private string $idMenuSupplier = 'm0000001-0000-4000-8000-000000000085';

    public function up(): void
    {
        DB::table('menu')
            ->where('id_menu', $this->idMenuSupplier)
            ->update(['aktif' => 0]);
    }

    public function down(): void
    {
        DB::table('menu')
            ->where('id_menu', $this->idMenuSupplier)
            ->update(['aktif' => 1]);
    }
};
