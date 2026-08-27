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
            DB::statement("ALTER TABLE faktur MODIFY status ENUM('draft','menunggu_approval','terkirim','lunas','batal') NOT NULL DEFAULT 'draft'");
            return;
        }

        Schema::table('faktur', function (Blueprint $table) {
            $table->enum('status', ['draft', 'menunggu_approval', 'terkirim', 'lunas', 'batal'])
                ->default('draft')->change();
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE faktur MODIFY status ENUM('draft','terkirim','lunas','batal') NOT NULL DEFAULT 'draft'");
            return;
        }

        Schema::table('faktur', function (Blueprint $table) {
            $table->enum('status', ['draft', 'terkirim', 'lunas', 'batal'])
                ->default('draft')->change();
        });
    }
};
