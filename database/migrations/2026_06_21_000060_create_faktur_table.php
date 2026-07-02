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
        Schema::create('faktur', function (Blueprint $table) {
            $table->char('id_faktur', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->char('id_proyek', 36)->nullable();
            $table->char('id_klien', 36)->nullable();
            $table->string('nomor_faktur', 100);
            $table->unique(['id_perusahaan', 'nomor_faktur'], 'faktur_perusahaan_nomor_unique');
            $table->decimal('total', 15, 2)->default(0);
            $table->enum('status', ['draft', 'terkirim', 'lunas', 'batal'])->default('draft');
            $table->date('tanggal_faktur')->nullable();
            $table->date('jatuh_tempo')->nullable();
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faktur');
    }
};
