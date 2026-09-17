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
        Schema::create('sparepart_foto', function (Blueprint $table) {
            $table->char('id_foto', 36)->primary();
            $table->char('id_sparepart', 36)->index();
            $table->string('url_file', 500);
            $table->string('nama_asli', 255);
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sparepart_foto');
    }
};
