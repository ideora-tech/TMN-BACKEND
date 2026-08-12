<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('armada_vendor', function (Blueprint $table) {
            $table->char('id_jenis_kendaraan', 36)->nullable()->index()->after('jenis');
        });
    }

    public function down(): void
    {
        Schema::table('armada_vendor', function (Blueprint $table) {
            $table->dropColumn('id_jenis_kendaraan');
        });
    }
};
