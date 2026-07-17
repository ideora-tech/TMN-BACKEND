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
        Schema::create('sparepart', function (Blueprint $table) {
            $table->char('id_sparepart', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->string('kode', 50); // unik per perusahaan di app-level, BUKAN DB unique
            $table->string('nama', 150);
            $table->string('satuan', 30)->default('pcs');
            $table->decimal('harga_standar', 15, 2)->default(0);
            $table->integer('stok')->default(0);
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);
            $table->index(['id_perusahaan', 'kode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sparepart');
    }
};
