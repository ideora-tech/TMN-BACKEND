<?php

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('approval_pengajuan', function (Blueprint $table) {
            $table->char('id_approval', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->char('id_event_type', 36);
            $table->char('id_referensi', 36);
            $table->char('id_pengguna_pengaju', 36);
            $table->decimal('nominal', 15, 2)->nullable();
            $table->enum('status', ['menunggu', 'disetujui', 'ditolak'])->default('menunggu');
            $table->text('alasan_ditolak')->nullable();
            MigrationHelper::auditColumns($table);

            $table->index(['id_event_type', 'id_referensi']);
            $table->index(['id_perusahaan', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_pengajuan');
    }
};
