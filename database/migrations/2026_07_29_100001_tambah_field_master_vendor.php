<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor', function (Blueprint $table) {
            $table->string('jenis_vendor', 50)->nullable()->after('nama_vendor');
            $table->string('pic_nama', 150)->nullable()->after('jenis_vendor');
            $table->string('npwp', 30)->nullable()->after('alamat');
            $table->date('tanggal_bergabung')->nullable()->after('npwp');
        });
    }

    public function down(): void
    {
        Schema::table('vendor', function (Blueprint $table) {
            $table->dropColumn(['jenis_vendor', 'pic_nama', 'npwp', 'tanggal_bergabung']);
        });
    }
};
