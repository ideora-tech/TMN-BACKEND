<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('faktur', function (Blueprint $table) {
            $table->string('nama_pajak', 50)->nullable()->after('total');
            $table->decimal('persen_pajak', 5, 2)->nullable()->after('nama_pajak');
        });
    }

    public function down(): void
    {
        Schema::table('faktur', function (Blueprint $table) {
            $table->dropColumn(['nama_pajak', 'persen_pajak']);
        });
    }
};
