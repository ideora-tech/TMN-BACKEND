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
        Schema::create('approval_lampiran', function (Blueprint $table) {
            $table->char('id_lampiran', 36)->primary();
            $table->char('id_approval', 36)->index('idx_approval_lampiran_id_approval');
            $table->string('nama_file', 255);
            $table->string('url_file', 500);
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_lampiran');
    }
};
