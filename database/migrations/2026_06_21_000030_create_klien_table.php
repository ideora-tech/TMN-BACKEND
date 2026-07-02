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
        Schema::create('klien', function (Blueprint $table) {
            $table->char('id_klien', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->string('kode_klien', 50)->unique();
            $table->string('nama_klien', 200);
            $table->string('email', 150)->nullable();
            $table->string('telepon', 30)->nullable();
            $table->text('alamat')->nullable();
            $table->string('kontak_pic', 200)->nullable();
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('klien');
    }
};
