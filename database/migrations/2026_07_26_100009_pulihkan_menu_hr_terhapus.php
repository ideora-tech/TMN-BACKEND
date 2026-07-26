<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Baris menu HR bisa berstatus soft-deleted dari data lama; upsert seed
    // tidak memulihkan dihapus_pada, jadi dipulihkan eksplisit di sini.
    public function up(): void
    {
        DB::table('menu')
            ->whereIn('id_menu', [
                'm0000001-0000-4000-8000-000000000060', // grup HR
                'm0000001-0000-4000-8000-000000000061', // Karyawan
                'm0000001-0000-4000-8000-000000000062', // Cuti & Izin
            ])
            ->update(['dihapus_pada' => null, 'dihapus_oleh' => null, 'aktif' => 1]);
    }

    public function down(): void
    {
        // Tidak ada rollback — pemulihan data.
    }
};
