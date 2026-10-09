<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $kolom = [
        'tanggal_efektif', 'jenis', 'nomor_sk', 'keterangan', 'urutan',
        'nama_jabatan_lama', 'nama_jabatan_baru', 'nama_departemen_lama', 'nama_departemen_baru',
    ];

    public function up(): void
    {
        Schema::table('riwayat_jabatan', function (Blueprint $table) {
            $table->date('tanggal_efektif')->nullable();
            $table->string('jenis', 20)->nullable();
            $table->string('nomor_sk', 100)->nullable();
            $table->text('keterangan')->nullable();
            $table->unsignedInteger('urutan')->default(0);
            $table->string('nama_jabatan_lama', 150)->nullable();
            $table->string('nama_jabatan_baru', 150)->nullable();
            $table->string('nama_departemen_lama', 150)->nullable();
            $table->string('nama_departemen_baru', 150)->nullable();
        });

        $jabatan = DB::table('jabatan as j')
            ->leftJoin('departemen as d', 'd.id_departemen', '=', 'j.id_departemen')
            ->get(['j.id_jabatan', 'j.nama_jabatan', 'd.nama_departemen'])
            ->keyBy('id_jabatan');

        $urutan = [];
        $baris = DB::table('riwayat_jabatan')
            ->orderBy('id_karyawan')
            ->orderBy('dibuat_pada')
            ->orderBy('id_riwayat')
            ->get(['id_riwayat', 'id_karyawan', 'id_jabatan_lama', 'id_jabatan_baru', 'dibuat_pada']);

        foreach ($baris as $r) {
            $urutan[$r->id_karyawan] = ($urutan[$r->id_karyawan] ?? 0) + 1;
            $lama = $r->id_jabatan_lama !== null ? ($jabatan[$r->id_jabatan_lama] ?? null) : null;
            $baru = $r->id_jabatan_baru !== null ? ($jabatan[$r->id_jabatan_baru] ?? null) : null;

            DB::table('riwayat_jabatan')->where('id_riwayat', $r->id_riwayat)->update([
                'tanggal_efektif'      => substr((string) $r->dibuat_pada, 0, 10),
                'jenis'                => $r->id_jabatan_lama === null ? 'awal' : null,
                'urutan'               => $urutan[$r->id_karyawan],
                'nama_jabatan_lama'    => $lama->nama_jabatan ?? null,
                'nama_jabatan_baru'    => $baru->nama_jabatan ?? null,
                'nama_departemen_lama' => $lama->nama_departemen ?? null,
                'nama_departemen_baru' => $baru->nama_departemen ?? null,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('riwayat_jabatan', function (Blueprint $table) {
            $table->dropColumn($this->kolom);
        });
    }
};
