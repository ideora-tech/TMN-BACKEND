<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proyek_rute', function (Blueprint $table) {
            $table->decimal('harga_penawaran', 15, 2)->nullable()->after('id_tarif_rute');
        });
    }

    public function down(): void
    {
        Schema::table('proyek_rute', function (Blueprint $table) {
            $table->dropColumn('harga_penawaran');
        });
    }
};
