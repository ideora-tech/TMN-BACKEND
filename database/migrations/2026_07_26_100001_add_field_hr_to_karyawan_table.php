<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('karyawan', function (Blueprint $table) {
            $table->string('nik_ktp', 20)->nullable()->after('nik');
            $table->string('tempat_lahir', 100)->nullable()->after('jenis_kelamin');
            $table->text('alamat_ktp')->nullable()->after('tanggal_lahir');
            $table->text('alamat_domisili')->nullable()->after('alamat_ktp');
            $table->string('status_pernikahan', 20)->nullable()->after('alamat_domisili');
            $table->tinyInteger('jumlah_tanggungan')->default(0)->after('status_pernikahan');
            $table->string('status_ptkp', 10)->nullable()->after('jumlah_tanggungan');
            $table->string('npwp', 30)->nullable()->after('status_ptkp');
            $table->string('nama_bank', 50)->nullable()->after('npwp');
            $table->string('nomor_rekening', 50)->nullable()->after('nama_bank');
            $table->string('atas_nama_rekening', 150)->nullable()->after('nomor_rekening');
            $table->tinyInteger('ikut_bpjs_kesehatan')->default(0)->after('atas_nama_rekening');
            $table->string('no_bpjs_kesehatan', 30)->nullable()->after('ikut_bpjs_kesehatan');
            $table->tinyInteger('ikut_bpjs_ketenagakerjaan')->default(0)->after('no_bpjs_kesehatan');
            $table->string('no_bpjs_ketenagakerjaan', 30)->nullable()->after('ikut_bpjs_ketenagakerjaan');
            $table->string('kontak_darurat_nama', 150)->nullable()->after('no_bpjs_ketenagakerjaan');
            $table->string('kontak_darurat_telepon', 30)->nullable()->after('kontak_darurat_nama');
            $table->string('kontak_darurat_hubungan', 50)->nullable()->after('kontak_darurat_telepon');
            $table->string('pendidikan_terakhir', 50)->nullable()->after('kontak_darurat_hubungan');
        });
    }

    public function down(): void
    {
        Schema::table('karyawan', function (Blueprint $table) {
            $table->dropColumn([
                'nik_ktp', 'tempat_lahir', 'alamat_ktp', 'alamat_domisili',
                'status_pernikahan', 'jumlah_tanggungan', 'status_ptkp', 'npwp',
                'nama_bank', 'nomor_rekening', 'atas_nama_rekening',
                'ikut_bpjs_kesehatan', 'no_bpjs_kesehatan',
                'ikut_bpjs_ketenagakerjaan', 'no_bpjs_ketenagakerjaan',
                'kontak_darurat_nama', 'kontak_darurat_telepon', 'kontak_darurat_hubungan',
                'pendidikan_terakhir',
            ]);
        });
    }
};
