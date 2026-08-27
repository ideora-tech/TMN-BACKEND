<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE approval_pengajuan MODIFY status ENUM('menunggu','disetujui','ditolak','dibatalkan') DEFAULT 'menunggu'");
        } else {
            Schema::table('approval_pengajuan', function (Blueprint $table) {
                $table->enum('status', ['menunggu', 'disetujui', 'ditolak', 'dibatalkan'])->default('menunggu')->change();
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE approval_pengajuan MODIFY status ENUM('menunggu','disetujui','ditolak') DEFAULT 'menunggu'");
        } else {
            Schema::table('approval_pengajuan', function (Blueprint $table) {
                $table->enum('status', ['menunggu', 'disetujui', 'ditolak'])->default('menunggu')->change();
            });
        }
    }
};
