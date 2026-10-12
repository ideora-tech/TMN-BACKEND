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
        if (Schema::hasTable('lampiran_master')) {
            return;
        }

        Schema::create('lampiran_master', function (Blueprint $table) {
            $table->char('id_lampiran', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->string('jenis_pemilik', 30);
            $table->char('id_pemilik', 36);
            $table->string('url_file', 500);
            $table->string('nama_asli', 255);
            $table->string('tipe_mime', 150)->nullable();
            $table->unsignedInteger('ukuran')->default(0);
            $table->unsignedSmallInteger('urutan')->default(0);
            MigrationHelper::auditColumns($table);

            $table->index(['jenis_pemilik', 'id_pemilik'], 'idx_lampiran_master_pemilik');
            $table->index('id_perusahaan', 'idx_lampiran_master_perusahaan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lampiran_master');
    }
};
