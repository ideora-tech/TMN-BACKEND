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
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE perawatan_armada MODIFY status ENUM('terjadwal', 'dalam_proses', 'selesai', 'dibatalkan') NOT NULL DEFAULT 'selesai'");
        } else {
            Schema::table('perawatan_armada', function (Blueprint $table) {
                $table->enum('status', ['terjadwal', 'dalam_proses', 'selesai', 'dibatalkan'])->default('selesai')->change();
            });
        }

        Schema::table('perawatan_armada', function (Blueprint $table) {
            $table->text('alasan_batal')->nullable()->after('alasan_hapus');
        });
    }

    public function down(): void
    {
        Schema::table('perawatan_armada', function (Blueprint $table) {
            $table->dropColumn('alasan_batal');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE perawatan_armada MODIFY status ENUM('terjadwal', 'dalam_proses', 'selesai') NOT NULL DEFAULT 'selesai'");
        } else {
            Schema::table('perawatan_armada', function (Blueprint $table) {
                $table->enum('status', ['terjadwal', 'dalam_proses', 'selesai'])->default('selesai')->change();
            });
        }
    }
};
