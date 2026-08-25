<?php

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('approval_keputusan', function (Blueprint $table) {
            $table->char('id_keputusan', 36)->primary();
            $table->char('id_approval', 36);
            $table->char('id_pengguna', 36);
            $table->enum('status', ['menunggu', 'disetujui', 'ditolak'])->default('menunggu');
            $table->text('catatan')->nullable();
            $table->dateTime('waktu_aksi')->nullable();
            MigrationHelper::auditColumns($table);

            $table->index(['id_approval', 'id_pengguna']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_keputusan');
    }
};
