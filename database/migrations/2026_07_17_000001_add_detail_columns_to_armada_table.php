<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('armada', function (Blueprint $table) {
            $table->string('nomor_rangka', 50)->nullable()->unique();
            $table->string('nomor_mesin', 50)->nullable();
            $table->string('warna', 50)->nullable();
            $table->string('jenis_bahan_bakar', 20)->nullable(); // solar|bensin|gas|listrik|hybrid — divalidasi di Request
            $table->unsignedInteger('kapasitas_muatan_kg')->nullable();
            $table->date('tanggal_beli')->nullable();
            $table->decimal('harga_beli', 15, 2)->nullable();
            $table->string('kondisi_beli', 10)->nullable(); // baru|bekas
            $table->string('url_foto', 500)->nullable();
            $table->text('keterangan')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('armada', function (Blueprint $table) {
            $table->dropColumn([
                'nomor_rangka',
                'nomor_mesin',
                'warna',
                'jenis_bahan_bakar',
                'kapasitas_muatan_kg',
                'tanggal_beli',
                'harga_beli',
                'kondisi_beli',
                'url_foto',
                'keterangan',
            ]);
        });
    }
};
