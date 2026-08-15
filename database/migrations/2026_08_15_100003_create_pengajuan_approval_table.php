<?php

declare(strict_types=1);

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengajuan_approval', function (Blueprint $table) {
            $table->char('id_approval', 36)->primary();
            $table->char('id_pengajuan', 36)->index();
            $table->char('id_pengguna', 36);
            $table->string('status', 10);
            $table->string('catatan', 255)->nullable();
            $table->dateTime('waktu_aksi')->nullable();
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengajuan_approval');
    }
};
