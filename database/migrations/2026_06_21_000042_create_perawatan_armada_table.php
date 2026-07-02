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
        Schema::create('perawatan_armada', function (Blueprint $table) {
            $table->char('id_perawatan', 36)->primary();
            $table->char('id_armada', 36);
            $table->date('tanggal');
            $table->string('jenis_perawatan', 150);
            $table->decimal('biaya', 15, 2)->default(0);
            $table->text('keterangan')->nullable();
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perawatan_armada');
    }
};
