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
        Schema::create('armada_vendor', function (Blueprint $table) {
            $table->char('id_armada_vendor', 36)->primary();
            $table->char('id_vendor', 36);
            $table->string('nopol', 20);
            $table->string('merk', 100)->nullable();
            $table->string('jenis', 100)->nullable();
            $table->smallInteger('tahun')->nullable();
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);

            $table->index('id_vendor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('armada_vendor');
    }
};
