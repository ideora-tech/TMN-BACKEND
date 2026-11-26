<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Istilah "Kas Kecil" diganti "Petty Cash" di tampilan. Nama menu & jenis approval
 * disimpan di DB dan bisa diubah admin, jadi hanya baris yang masih bernama default
 * yang diperbarui — nama hasil ubahan admin tidak ditimpa.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('menu')
            ->where('path', '/kas-kecil')
            ->where('nama_menu', 'Kas Kecil')
            ->update(['nama_menu' => 'Petty Cash']);

        DB::table('approval_event_type')
            ->where('kode', 'pengisian_kas_kecil')
            ->where('nama', 'Pengisian Kas Kecil')
            ->update(['nama' => 'Pengisian Petty Cash']);
    }

    public function down(): void
    {
        DB::table('menu')
            ->where('path', '/kas-kecil')
            ->where('nama_menu', 'Petty Cash')
            ->update(['nama_menu' => 'Kas Kecil']);

        DB::table('approval_event_type')
            ->where('kode', 'pengisian_kas_kecil')
            ->where('nama', 'Pengisian Petty Cash')
            ->update(['nama' => 'Pengisian Kas Kecil']);
    }
};
