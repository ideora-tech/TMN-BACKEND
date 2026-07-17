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
        Schema::create('sparepart_mutasi', function (Blueprint $table) {
            $table->char('id_mutasi', 36)->primary();
            $table->char('id_sparepart', 36)->index();
            $table->enum('jenis', ['masuk', 'keluar', 'penyesuaian']);
            $table->integer('qty'); // masuk/keluar selalu positif; penyesuaian boleh negatif (delta)
            $table->decimal('harga', 15, 2)->nullable();
            $table->char('id_perawatan', 36)->nullable()->index();
            $table->text('keterangan')->nullable();
            $table->date('tanggal');
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sparepart_mutasi');
    }
};
