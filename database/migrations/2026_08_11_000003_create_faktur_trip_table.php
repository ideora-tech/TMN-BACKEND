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
        Schema::create('faktur_trip', function (Blueprint $table) {
            $table->char('id_faktur_trip', 36)->primary();
            $table->char('id_faktur', 36)->index();
            $table->char('id_trip', 36)->index();
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faktur_trip');
    }
};
