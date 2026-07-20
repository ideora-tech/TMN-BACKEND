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

    private function makeArmada(?string $idJenisKendaraan, string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada'          => $id,
            'id_perusahaan'      => $idPerusahaan,
            'id_jenis_kendaraan' => $idJenisKendaraan,
            'nopol'              => 'B ' . random_int(1000, 9999) . ' ' . Str::random(2),
            'status'             => 'tersedia',
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ]);
        return $id;
    }

    private function makeJenisKendaraan(string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $id, 'id_perusahaan' => $idPerusahaan,
            'kode_jenis' => 'CDD-' . Str::random(4), 'nama_jenis' => 'CDD', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeJenisPerawatan(string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_perawatan')->insert([
            'id_jenis_perawatan' => $id, 'id_perusahaan' => $idPerusahaan,
            'nama' => 'Ganti Oli', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeInterval(string $idJenisPerawatan, string $idJenisKendaraan, int $hari, string $idPerusahaan = self::PERUSAHAAN_ID): void
    {
        DB::table('interval_perawatan')->insert([
            'id_interval_perawatan' => (string) Str::uuid(), 'id_perusahaan' => $idPerusahaan,
            'id_jenis_perawatan' => $idJenisPerawatan, 'id_jenis_kendaraan' => $idJenisKendaraan,
            'interval_hari' => $hari, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
    }

    private function makePerawatan(string $idArmada, string $tanggal, ?string $idJenisPerawatan, ?string $jadwal = null): string
    {
        $id = (string) Str::uuid();
        DB::table('perawatan_armada')->insert([
            'id_perawatan' => $id, 'id_armada' => $idArmada, 'id_jenis_perawatan' => $idJenisPerawatan,
            'tanggal' => $tanggal, 'jenis_perawatan' => 'Ganti Oli', 'biaya' => 100000,
            'status' => 'selesai', 'jadwal_servis_berikutnya' => $jadwal, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    public function test_backfill_mengisi_jadwal_kosong_pada_servis_terbaru(): void
    {
        $idKendaraan = $this->makeJenisKendaraan();
        $idJenis = $this->makeJenisPerawatan();
        $this->makeInterval($idJenis, $idKendaraan, 180);
        $armada = $this->makeArmada($idKendaraan);
        $this->makePerawatan($armada, '2026-01-01', $idJenis); // servis lama, jadwal kosong
        $idTerbaru = $this->makePerawatan($armada, '2026-06-01', $idJenis); // servis terbaru, jadwal kosong

        $this->artisan('servis:backfill-jadwal')->assertExitCode(0);

        $terbaru = DB::table('perawatan_armada')->where('id_perawatan', $idTerbaru)->first();
        $this->assertSame('2026-11-28', $terbaru->jadwal_servis_berikutnya); // 2026-06-01 + 180 hari

        $lama = DB::table('perawatan_armada')->where('id_perawatan', '!=', $idTerbaru)->first();
        $this->assertNull($lama->jadwal_servis_berikutnya); // servis lama TIDAK disentuh
    }

    public function test_backfill_tidak_menimpa_jadwal_yang_sudah_terisi(): void
    {
        $idKendaraan = $this->makeJenisKendaraan();
        $idJenis = $this->makeJenisPerawatan();
        $this->makeInterval($idJenis, $idKendaraan, 180);
        $armada = $this->makeArmada($idKendaraan);
        $id = $this->makePerawatan($armada, '2026-06-01', $idJenis, '2026-08-01');

        $this->artisan('servis:backfill-jadwal')->assertExitCode(0);

        $row = DB::table('perawatan_armada')->where('id_perawatan', $id)->first();
        $this->assertSame('2026-08-01', $row->jadwal_servis_berikutnya);
    }

    public function test_backfill_idempoten_dijalankan_dua_kali(): void
    {
        $idKendaraan = $this->makeJenisKendaraan();
        $idJenis = $this->makeJenisPerawatan();
        $this->makeInterval($idJenis, $idKendaraan, 180);
        $armada = $this->makeArmada($idKendaraan);
        $id = $this->makePerawatan($armada, '2026-06-01', $idJenis);

        $this->artisan('servis:backfill-jadwal')->assertExitCode(0);
        $this->artisan('servis:backfill-jadwal')->assertExitCode(0);

        $row = DB::table('perawatan_armada')->where('id_perawatan', $id)->first();
        $this->assertSame('2026-11-28', $row->jadwal_servis_berikutnya);
    }

    public function test_backfill_tanpa_interval_cocok_tidak_mengisi(): void
    {
        $armada = $this->makeArmada($this->makeJenisKendaraan());
        $idJenis = $this->makeJenisPerawatan();
        // sengaja tidak buat interval_perawatan untuk kombinasi ini
        $id = $this->makePerawatan($armada, '2026-06-01', $idJenis);

        $this->artisan('servis:backfill-jadwal')->assertExitCode(0);

        $row = DB::table('perawatan_armada')->where('id_perawatan', $id)->first();
        $this->assertNull($row->jadwal_servis_berikutnya);
    }

    public function test_backfill_servis_tanpa_id_jenis_perawatan_tidak_mengisi(): void
    {
        $armada = $this->makeArmada($this->makeJenisKendaraan());
        $id = $this->makePerawatan($armada, '2026-06-01', null); // jenis_perawatan teks bebas, id null

        $this->artisan('servis:backfill-jadwal')->assertExitCode(0);

        $row = DB::table('perawatan_armada')->where('id_perawatan', $id)->first();
        $this->assertNull($row->jadwal_servis_berikutnya);
    }
}
