<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackfillJadwalServisTest extends TestCase
{
    use RefreshDatabase;

    private function makeArmada(string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada'     => $id,
            'id_perusahaan' => $idPerusahaan,
            'nopol'         => 'B ' . random_int(1000, 9999) . ' ' . Str::random(2),
            'status'        => 'tersedia',
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makeInterval(int $intervalBulan, string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $idKendaraan = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $idKendaraan, 'id_perusahaan' => $idPerusahaan,
            'kode_jenis' => 'CDD-' . Str::random(4), 'nama_jenis' => 'CDD', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $id = (string) Str::uuid();
        DB::table('interval_perawatan')->insert([
            'id_interval_perawatan' => $id, 'id_perusahaan' => $idPerusahaan,
            'id_jenis_kendaraan' => $idKendaraan, 'interval_bulan' => $intervalBulan,
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makePerawatan(string $idArmada, string $tanggal, ?string $idIntervalPerawatan, ?string $jadwal = null): string
    {
        $id = (string) Str::uuid();
        DB::table('perawatan_armada')->insert([
            'id_perawatan' => $id, 'id_armada' => $idArmada, 'id_interval_perawatan' => $idIntervalPerawatan,
            'tanggal' => $tanggal, 'biaya' => 100000,
            'status' => 'selesai', 'jadwal_servis_berikutnya' => $jadwal, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    public function test_backfill_mengisi_jadwal_kosong_pada_servis_terbaru(): void
    {
        $armada = $this->makeArmada();
        $idInterval = $this->makeInterval(6);
        $this->makePerawatan($armada, '2026-01-01', $idInterval); // servis lama, jadwal kosong
        $idTerbaru = $this->makePerawatan($armada, '2026-06-01', $idInterval); // servis terbaru, jadwal kosong

        $this->artisan('servis:backfill-jadwal')->assertExitCode(0);

        $terbaru = DB::table('perawatan_armada')->where('id_perawatan', $idTerbaru)->first();
        $this->assertSame('2026-12-01', $terbaru->jadwal_servis_berikutnya); // 2026-06-01 + 6 bulan

        $lama = DB::table('perawatan_armada')->where('id_perawatan', '!=', $idTerbaru)->first();
        $this->assertNull($lama->jadwal_servis_berikutnya); // servis lama TIDAK disentuh
    }

    public function test_backfill_tidak_menimpa_jadwal_yang_sudah_terisi(): void
    {
        $armada = $this->makeArmada();
        $idInterval = $this->makeInterval(6);
        $id = $this->makePerawatan($armada, '2026-06-01', $idInterval, '2026-08-01');

        $this->artisan('servis:backfill-jadwal')->assertExitCode(0);

        $row = DB::table('perawatan_armada')->where('id_perawatan', $id)->first();
        $this->assertSame('2026-08-01', $row->jadwal_servis_berikutnya);
    }

    public function test_backfill_idempoten_dijalankan_dua_kali(): void
    {
        $armada = $this->makeArmada();
        $idInterval = $this->makeInterval(6);
        $id = $this->makePerawatan($armada, '2026-06-01', $idInterval);

        $this->artisan('servis:backfill-jadwal')->assertExitCode(0);
        $this->artisan('servis:backfill-jadwal')->assertExitCode(0);

        $row = DB::table('perawatan_armada')->where('id_perawatan', $id)->first();
        $this->assertSame('2026-12-01', $row->jadwal_servis_berikutnya);
    }

    public function test_backfill_paket_tanpa_interval_bulan_tidak_mengisi(): void
    {
        $armada = $this->makeArmada();
        $idKendaraan = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $idKendaraan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jenis' => 'CDD-' . Str::random(4), 'nama_jenis' => 'CDD', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $idInterval = (string) Str::uuid();
        DB::table('interval_perawatan')->insert([
            'id_interval_perawatan' => $idInterval, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_jenis_kendaraan' => $idKendaraan, 'interval_km' => 10000, 'interval_bulan' => null,
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $id = $this->makePerawatan($armada, '2026-06-01', $idInterval);

        $this->artisan('servis:backfill-jadwal')->assertExitCode(0);

        $row = DB::table('perawatan_armada')->where('id_perawatan', $id)->first();
        $this->assertNull($row->jadwal_servis_berikutnya);
    }

    public function test_backfill_servis_tanpa_paket_tidak_mengisi(): void
    {
        $armada = $this->makeArmada();
        $id = $this->makePerawatan($armada, '2026-06-01', null); // catatan insidental, tanpa tautan paket

        $this->artisan('servis:backfill-jadwal')->assertExitCode(0);

        $row = DB::table('perawatan_armada')->where('id_perawatan', $id)->first();
        $this->assertNull($row->jadwal_servis_berikutnya);
    }
}
