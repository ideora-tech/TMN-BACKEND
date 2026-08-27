<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE penawaran MODIFY status ENUM('draft','menunggu_approval','terkirim','negosiasi','disetujui','ditolak') DEFAULT 'draft'");
            return;
        }

        Schema::table('penawaran', function (Blueprint $table) {
            $table->enum('status', ['draft', 'menunggu_approval', 'terkirim', 'negosiasi', 'disetujui', 'ditolak'])
                ->default('draft')->change();
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE penawaran MODIFY status ENUM('draft','terkirim','negosiasi','disetujui','ditolak') DEFAULT 'draft'");
            return;
        }

        Schema::table('penawaran', function (Blueprint $table) {
            $table->enum('status', ['draft', 'terkirim', 'negosiasi', 'disetujui', 'ditolak'])
                ->default('draft')->change();
        });
    }
};
