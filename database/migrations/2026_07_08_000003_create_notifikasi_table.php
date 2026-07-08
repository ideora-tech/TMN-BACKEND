<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Helpers\MigrationHelper;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifikasi', function (Blueprint $table) {
            $table->char('id_notifikasi', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->char('id_pengguna', 36)->nullable();
            $table->string('judul', 200);
            $table->text('isi');
            $table->string('tipe', 50)->default('info');
            $table->char('referensi_id', 36)->nullable();
            $table->string('referensi_tipe', 100)->nullable();
            $table->tinyInteger('dibaca')->default(0);
            $table->dateTime('dibaca_pada')->nullable();
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifikasi');
    }
};