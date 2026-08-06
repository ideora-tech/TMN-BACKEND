<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interval_perawatan', function (Blueprint $table) {
            $table->unsignedInteger('interval_hari')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('interval_perawatan', function (Blueprint $table) {
            $table->unsignedInteger('interval_hari')->nullable(false)->change();
        });
    }
};
