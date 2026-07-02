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
        Schema::create('rekonsiliasi', function (Blueprint $table) {
            $table->char('id_rekonsiliasi', 36)->primary();
            $table->char('id_faktur', 36);
            $table->text('catatan_klien')->nullable();
            $table->text('catatan_keuangan')->nullable();
            $table->enum('status', ['pending', 'selesai'])->default('pending');
            $table->dateTime('diselesaikan_pada')->nullable();
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rekonsiliasi');
    }
};
