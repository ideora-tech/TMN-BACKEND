<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('menu', function (Blueprint $table) {
            $table->string('icon', 100)->nullable()->after('path');
            $table->json('kode_peran')->nullable()->after('icon');
        });
    }

    public function down(): void
    {
        Schema::table('menu', function (Blueprint $table) {
            $table->dropColumn(['icon', 'kode_peran']);
        });
    }
};
