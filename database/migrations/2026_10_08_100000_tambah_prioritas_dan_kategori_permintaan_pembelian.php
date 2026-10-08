<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('permintaan_pembelian', 'prioritas')) {
            Schema::table('permintaan_pembelian', function (Blueprint $table) {
                $table->string('prioritas', 10)->default('normal')->after('tanggal_dibutuhkan');
            });
        }

        DB::table('menu')->where('path', '/judul-permintaan')->update(['nama_menu' => 'Kategori Permintaan']);
    }

    public function down(): void
    {
        DB::table('menu')->where('path', '/judul-permintaan')->update(['nama_menu' => 'Judul Permintaan']);

        if (Schema::hasColumn('permintaan_pembelian', 'prioritas')) {
            Schema::table('permintaan_pembelian', function (Blueprint $table) {
                $table->dropColumn('prioritas');
            });
        }
    }
};
