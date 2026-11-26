<?php

namespace Tests;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    public const PERUSAHAAN_ID = 'b8f3c1a2-0000-4000-8000-000000000001';

    protected function ensurePerusahaan(): void
    {
        DB::table('perusahaan')->insertOrIgnore([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => 'TMN Transport Test',
            'dibuat_pada' => now(),
        ]);
    }

    /**
     * Isi saldo awal Kas Kecil dengan nominal besar bertanggal lampau. Sejak aturan
     * sumber dana, semua pembayaran pengajuan (selain pengisian kas kecil) memotong
     * saldo Kas Kecil — tes yang hanya menguji alur transfer butuh saldo cukup.
     */
    protected function isiSaldoKasKecil(float $nominal = 1_000_000_000_000, string $idPerusahaan = self::PERUSAHAAN_ID): void
    {
        $this->ensurePerusahaan();
        $ada = DB::table('kas_kecil_transaksi')
            ->where('id_perusahaan', $idPerusahaan)->where('jenis', 'saldo_awal')->whereNull('dihapus_pada')->exists();
        if ($ada) {
            return;
        }

        DB::table('kas_kecil_transaksi')->insert([
            'id_transaksi'    => (string) Str::uuid(),
            'id_perusahaan'   => $idPerusahaan,
            'nomor_transaksi' => 'KK-SALDO-AWAL-TES',
            'tanggal'         => '2000-01-01',
            'arah'            => 'masuk',
            'jenis'           => 'saldo_awal',
            'nominal'         => $nominal,
            'keterangan'      => 'Saldo awal untuk tes',
            'dibuat_pada'     => now(),
        ]);
    }

    /** Baris keluar Buku Kas Kecil hasil pembayaran pengajuan berkategori tertentu. */
    protected function pembayaranKasKecil(string $kategoriPengajuan): \Illuminate\Support\Collection
    {
        return DB::table('kas_kecil_transaksi as t')
            ->join('pengajuan_pengeluaran as pp', 'pp.id_pengajuan', '=', 't.id_pengajuan')
            ->whereNull('t.dihapus_pada')
            ->where('t.jenis', 'pembayaran')
            ->where('pp.kategori', $kategoriPengajuan)
            ->get(['t.tanggal', 't.arah', 't.nominal', 'pp.sumber_dana']);
    }

    protected function perusahaanGrupId(string $singkatan = 'SULITA', string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $ada = DB::table('perusahaan_grup')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('singkatan', $singkatan)
            ->whereNull('dihapus_pada')
            ->value('id_perusahaan_grup');
        if ($ada !== null) {
            return (string) $ada;
        }

        $id = (string) Str::uuid();
        DB::table('perusahaan_grup')->insert([
            'id_perusahaan_grup' => $id, 'id_perusahaan' => $idPerusahaan, 'singkatan' => $singkatan,
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        return $id;
    }

    protected function actingAsRole(string $kodePeran = 'ADMIN'): Pengguna
    {
        $this->ensurePerusahaan();
        $pengguna = Pengguna::create([
            'id_pengguna'   => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran'    => $kodePeran,
            'username'      => 'test_' . Str::random(8),
            'email'         => Str::random(8) . '@test.id',
            'kata_sandi'    => bcrypt('Password123!'),
            'aktif'         => 1,
        ]);
        Sanctum::actingAs($pengguna, ['*']);
        return $pengguna;
    }
}
