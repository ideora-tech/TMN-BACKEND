<?php

declare(strict_types=1);

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Master kategori pengajuan pengeluaran yang dipilih manual di form Tambah Pengajuan.
 * `pengajuan_pengeluaran.kategori` tetap menyimpan `kode`, jadi data lama tidak berubah.
 * Kategori otomatis (uang jalan, gaji, vendor, sparepart, PR, ...) tetap ditentukan modulnya
 * dan tidak masuk master. Isi awal: 5 kategori bawaan + daftar beban dari tim keuangan.
 */
return new class extends Migration
{
    private const AWAL = [
        // kode, nama, sistem, urutan
        ['legalitas',                 'Legalitas',                    1, 1],
        ['perawatan',                 'Perawatan',                    1, 2],
        ['pembelian_aset',            'Pembelian Aset',               1, 3],
        ['pembayaran_pinjaman',       'Pembayaran Pinjaman',          1, 4],
        ['beban_operasional_uj',      'Beban Operasional - UJ',       0, 10],
        ['beban_operasional_vendor',  'Beban Operasional - Vendor',   0, 11],
        ['beban_operasional_lainnya', 'Beban Operasional - Lainnya',  0, 12],
        ['beban_maintenance',         'Beban Maintenance',            0, 13],
        ['beban_atk',                 'Beban ATK',                    0, 14],
        ['beban_hutang',              'Beban Hutang',                 0, 15],
        ['beban_kantor',              'Beban Kantor',                 0, 16],
        ['beban_aset',                'Beban Aset',                   0, 17],
        ['beban_gaji',                'Beban Gaji',                   0, 18],
        ['beban_deviden',             'Beban Deviden',                0, 19],
        ['beban_lainnya',             'Beban Lainnya',                0, 20],
        ['lainnya',                   'Lainnya',                      1, 99],
    ];

    public function up(): void
    {
        Schema::create('kategori_pengajuan', function (Blueprint $table) {
            $table->char('id_kategori_pengajuan', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->string('kode', 40);
            $table->string('nama', 100);
            $table->tinyInteger('sistem')->default(0);
            $table->tinyInteger('aktif')->default(1);
            $table->integer('urutan')->default(50);
            MigrationHelper::auditColumns($table);
            $table->index(['id_perusahaan', 'kode']);
        });

        $now = now();
        foreach (DB::table('perusahaan')->pluck('id_perusahaan') as $idPerusahaan) {
            foreach (self::AWAL as [$kode, $nama, $sistem, $urutan]) {
                DB::table('kategori_pengajuan')->insert([
                    'id_kategori_pengajuan' => (string) Str::uuid(),
                    'id_perusahaan'         => $idPerusahaan,
                    'kode'                  => $kode,
                    'nama'                  => $nama,
                    'sistem'                => $sistem,
                    'aktif'                 => 1,
                    'urutan'                => $urutan,
                    'dibuat_pada'           => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kategori_pengajuan');
    }
};
