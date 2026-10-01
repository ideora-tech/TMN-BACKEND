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
        Schema::create('penawaran_lampiran', function (Blueprint $table) {
            $table->char('id_lampiran', 36)->primary();
            $table->char('id_penawaran', 36)->index('idx_penawaran_lampiran_id_penawaran');
            $table->string('url_file', 500);
            $table->string('nama_asli', 255);
            $table->unsignedSmallInteger('urutan')->default(0);
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penawaran_lampiran');
    }
};
