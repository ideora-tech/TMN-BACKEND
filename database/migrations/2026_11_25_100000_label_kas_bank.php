<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Menu "Arus Kas" sekarang berisi buku kas bank (pemasukan klien, pengisian Petty Cash,
 * rekap saldo, laporan PSAK), jadi namanya diganti "Kas Bank" supaya jelas bedanya
 * dengan Petty Cash. Hanya diganti bila masih bernama default — nama ubahan admin dibiarkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('menu')
            ->where('path', '/arus-kas')
            ->where('nama_menu', 'Arus Kas')
            ->update(['nama_menu' => 'Kas Bank']);
    }

    public function down(): void
    {
        DB::table('menu')
            ->where('path', '/arus-kas')
            ->where('nama_menu', 'Kas Bank')
            ->update(['nama_menu' => 'Arus Kas']);
    }
};
