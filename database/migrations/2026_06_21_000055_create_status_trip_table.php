<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('status_trip', function (Blueprint $table) {
            $table->char('id_status', 36)->primary();
            $table->char('id_trip', 36);
            $table->string('status', 100);
            $table->text('keterangan')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->char('dibuat_oleh', 36)->nullable();
            $table->dateTime('dibuat_pada')->useCurrent();
            $table->index('id_trip');
        });
    }
    public function down(): void { Schema::dropIfExists('status_trip'); }
};
