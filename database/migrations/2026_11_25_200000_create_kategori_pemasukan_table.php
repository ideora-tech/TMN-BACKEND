<?php

declare(strict_types=1);

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Master kategori pemasukan (sebelumnya daftar tetap di kode). `pemasukan.kategori`
 * tetap menyimpan `kode`, jadi data lama tidak berubah. Lima kategori bawaan diisi untuk
 * setiap perusahaan yang sudah ada; perusahaan baru dilengkapi otomatis saat dibaca.
 */
return new class extends Migration
{
    private const BAWAAN = [
        ['pendapatan_jasa',   'Pendapatan Jasa',   'pelanggan',    1],
        ['penjualan_aset',    'Penjualan Aset',    'investasi',    2],
        ['pengembalian_dana', 'Pengembalian Dana', 'pengembalian', 3],
        ['modal_pinjaman',    'Modal/Pinjaman',    'pendanaan',    4],
        ['lainnya',           'Lainnya',           'operasi_lain', 99],
    ];

    public function up(): void
    {
        Schema::create('kategori_pemasukan', function (Blueprint $table) {
            $table->char('id_kategori_pemasukan', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->string('kode', 30);
            $table->string('nama', 100);
            $table->string('pos_psak', 20)->default('operasi_lain');
            $table->tinyInteger('sistem')->default(0);
            $table->tinyInteger('aktif')->default(1);
            $table->integer('urutan')->default(50);
            MigrationHelper::auditColumns($table);
            $table->index(['id_perusahaan', 'kode']);
        });

        $now = now();
        foreach (DB::table('perusahaan')->pluck('id_perusahaan') as $idPerusahaan) {
            foreach (self::BAWAAN as [$kode, $nama, $pos, $urutan]) {
                DB::table('kategori_pemasukan')->insert([
                    'id_kategori_pemasukan' => (string) Str::uuid(),
                    'id_perusahaan'         => $idPerusahaan,
                    'kode'                  => $kode,
                    'nama'                  => $nama,
                    'pos_psak'              => $pos,
                    'sistem'                => 1,
                    'aktif'                 => 1,
                    'urutan'                => $urutan,
                    'dibuat_pada'           => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kategori_pemasukan');
    }
};
