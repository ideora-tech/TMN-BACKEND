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
        Schema::create('rekening_vendor', function (Blueprint $table) {
            $table->char('id_rekening_vendor', 36)->primary();
            $table->char('id_vendor', 36);
            $table->string('nama_bank', 100);
            $table->string('nomor_rekening', 50);
            $table->string('atas_nama', 150);
            $table->string('cabang', 100)->nullable();
            $table->string('mata_uang', 10)->default('IDR');
            MigrationHelper::auditColumns($table);

            $table->index('id_vendor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rekening_vendor');
    }
};
