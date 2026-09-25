<?php

declare(strict_types=1);

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private array $kategoriDefault = ['ATK', 'Perlengkapan Kantor', 'Kebersihan', 'Elektronik', 'Aset', 'Lainnya'];

    public function up(): void
    {
        Schema::create('kategori_barang', function (Blueprint $table) {
            $table->char('id_kategori_barang', 36)->primary();
            $table->char('id_perusahaan', 36)->index();
            $table->string('nama', 100);
            $table->text('keterangan')->nullable();
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);
        });

        $now = now();
        $perusahaan = DB::table('perusahaan')->whereNull('dihapus_pada')->pluck('id_perusahaan');
        foreach ($perusahaan as $idPerusahaan) {
            foreach ($this->kategoriDefault as $nama) {
                DB::table('kategori_barang')->insert([
                    'id_kategori_barang' => (string) Str::uuid(),
                    'id_perusahaan'      => $idPerusahaan,
                    'nama'               => $nama,
                    'aktif'              => 1,
                    'dibuat_pada'        => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kategori_barang');
    }
};
