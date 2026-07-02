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
        Schema::create('kontrak_vendor', function (Blueprint $table) {
            $table->char('id_kontrak_vendor', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->char('id_vendor', 36);
            $table->char('id_proyek', 36)->nullable();
            $table->enum('mekanisme', ['unit_only', 'unit_driver', 'full']);
            $table->decimal('nilai_kontrak', 15, 2)->default(0);
            $table->date('tanggal_mulai')->nullable();
            $table->date('tanggal_selesai')->nullable();
            $table->string('status', 50)->default('aktif');
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kontrak_vendor');
    }
};
