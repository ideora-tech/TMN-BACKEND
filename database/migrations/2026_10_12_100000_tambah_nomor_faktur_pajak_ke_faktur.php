<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('faktur', 'nomor_faktur_pajak')) {
            Schema::table('faktur', function (Blueprint $table) {
                $table->string('nomor_faktur_pajak', 50)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('faktur', 'nomor_faktur_pajak')) {
            Schema::table('faktur', function (Blueprint $table) {
                $table->dropColumn('nomor_faktur_pajak');
            });
        }
    }
};
