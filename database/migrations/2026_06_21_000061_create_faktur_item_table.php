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
        Schema::create('faktur_item', function (Blueprint $table) {
            $table->char('id_faktur_item', 36)->primary();
            $table->char('id_faktur', 36);
            $table->string('deskripsi', 300);
            $table->decimal('qty', 10, 2)->default(1);
            $table->decimal('harga_satuan', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2)->default(0);
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faktur_item');
    }
};
