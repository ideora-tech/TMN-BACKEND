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
        Schema::create('pemasukan', function (Blueprint $table) {
            $table->char('id_pemasukan', 36)->primary();
            $table->char('id_perusahaan', 36)->index();
            $table->string('nomor_pemasukan', 30);
            $table->string('kategori', 30);
            $table->date('tanggal');
            $table->decimal('nominal', 15, 2);
            $table->string('sumber_dana', 150);
            $table->string('keterangan', 255)->nullable();
            $table->string('url_bukti', 500)->nullable();
            MigrationHelper::auditColumns($table);
            $table->index(['id_perusahaan', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pemasukan');
    }
};
