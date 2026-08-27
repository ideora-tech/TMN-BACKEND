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
            DB::statement("ALTER TABLE invoice_vendor MODIFY status ENUM('draft','menunggu_approval','diverifikasi','ditolak') NOT NULL DEFAULT 'draft'");
            return;
        }

        Schema::table('invoice_vendor', function (Blueprint $table) {
            $table->enum('status', ['draft', 'menunggu_approval', 'diverifikasi', 'ditolak'])
                ->default('draft')->change();
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE invoice_vendor MODIFY status ENUM('draft','diverifikasi','ditolak') NOT NULL DEFAULT 'draft'");
            return;
        }

        Schema::table('invoice_vendor', function (Blueprint $table) {
            $table->enum('status', ['draft', 'diverifikasi', 'ditolak'])
                ->default('draft')->change();
        });
    }
};
