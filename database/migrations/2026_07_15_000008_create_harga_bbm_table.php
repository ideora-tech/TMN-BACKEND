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
        Schema::create('harga_bbm', function (Blueprint $table) {
            $table->char('id_harga_bbm', 36)->primary();
            $table->char('id_jenis_bbm', 36);
            $table->decimal('harga_per_liter', 12, 2);
            $table->date('berlaku_mulai');
            MigrationHelper::auditColumns($table);

            $table->index('id_jenis_bbm');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('harga_bbm');
    }
};
