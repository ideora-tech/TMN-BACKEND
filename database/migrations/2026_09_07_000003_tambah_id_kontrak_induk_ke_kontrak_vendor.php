<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kontrak_vendor', function (Blueprint $table) {
            $table->char('id_kontrak_induk', 36)->nullable()->after('id_vendor');
            $table->index('id_kontrak_induk');
        });
    }

    public function down(): void
    {
        Schema::table('kontrak_vendor', function (Blueprint $table) {
            $table->dropIndex(['id_kontrak_induk']);
            $table->dropColumn('id_kontrak_induk');
        });
    }
};
