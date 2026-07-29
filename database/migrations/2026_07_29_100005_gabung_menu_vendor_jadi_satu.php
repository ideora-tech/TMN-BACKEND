<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ID_MENU_VENDOR_LAMA = [
        'm0000001-0000-4000-8000-000000000070',
        'm0000001-0000-4000-8000-000000000071',
        'm0000001-0000-4000-8000-000000000072',
        'm0000001-0000-4000-8000-000000000073',
    ];

    public function up(): void
    {
        DB::table('menu')
            ->whereIn('id_menu', self::ID_MENU_VENDOR_LAMA)
            ->update(['dihapus_pada' => now(), 'aktif' => 0]);

        DB::table('menu_peran')
            ->whereIn('id_menu', self::ID_MENU_VENDOR_LAMA)
            ->delete();
    }

    public function down(): void
    {
        // menu & menu_peran tidak di-restore otomatis — restore manual via
        // MenuSeeder, sama seperti pola menu_sensitif_hanya_superadmin.
    }
};
