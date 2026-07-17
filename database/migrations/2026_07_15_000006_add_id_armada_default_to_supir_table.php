<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supir', function (Blueprint $table) {
            $table->char('id_armada_default', 36)->nullable()->after('id_pengguna');
        });
    }

    public function down(): void
    {
        Schema::table('supir', function (Blueprint $table) {
            $table->dropColumn('id_armada_default');
        });
    }
};
