<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Penyeragaman kosakata status armada ke nilai resmi:
 * tersedia | digunakan | perawatan | tidak_aktif.
 *
 * Data lama (form lama) memakai nilai campuran: aktif, servis, nonaktif.
 * Kolom `armada.status` tetap string(50) — tidak dikonversi ke enum SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('armada')->where('status', 'aktif')->update(['status' => 'tersedia']);
        DB::table('armada')->where('status', 'servis')->update(['status' => 'perawatan']);
        DB::table('armada')->where('status', 'nonaktif')->update(['status' => 'tidak_aktif']);
    }

    public function down(): void
    {
        // No-op: normalisasi data lama tidak reversibel (pemetaan lama->baru
        // tidak 1:1 dapat dibalik dengan aman — data hasil rollback bisa
        // salah jika ada baris yang sudah berstatus 'tersedia' dari awal,
        // bukan hasil migrasi ini).
    }
};
