<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\JadwalKeberangkatan\JadwalKeberangkatanModel;
use App\Modules\JenisBbm\JenisBbmModel;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use App\Modules\Trip\TripModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LaporanBbmTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrip(string $idPerusahaan, string $status = 'selesai'): TripModel
    {
        $proyek = ProyekModel::create([
            'id_perusahaan' => $idPerusahaan,
            'id_klien'      => (string) Str::uuid(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Test Laporan BBM',
        ]);

        $penugasan = PenugasanModel::create([
            'id_proyek' => $proyek->id_proyek,
        ]);

        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan' => $penugasan->id_penugasan,
        ]);

        return TripModel::create([
            'id_jadwal' => $jadwal->id_jadwal,
            'status'    => $status,
        ]);
    }

    private function makePerusahaanLain(): string
    {
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert([
            'id_perusahaan' => $idPerusahaanLain,
            'nama'          => 'Perusahaan Lain Test',
            'dibuat_pada'   => now(),
        ]);
        return $idPerusahaanLain;
    }

    private function makeJenisBbm(string $idPerusahaan, string $nama = 'Solar'): JenisBbmModel
    {
        return JenisBbmModel::create([
            'id_perusahaan' => $idPerusahaan,
            'nama_bbm'      => $nama,
        ]);
    }

    public function test_membuat_laporan_dengan_jenis_bbm_dan_jumlah_liter_tersimpan(): void
    {
        $this->actingAsRole('ADMIN');
        $trip = $this->makeTrip(self::PERUSAHAAN_ID);
        $jenis = $this->makeJenisBbm(self::PERUSAHAAN_ID);

        $res = $this->postJson("/api/v1/trip/{$trip->id_trip}/laporan-perjalanan", [
            'biaya_bbm'       => 500000,
            'jarak_tempuh_km' => 120,
            'uang_jalan'      => 200000,
            'id_jenis_bbm'    => $jenis->id_jenis_bbm,
            'jumlah_liter'    => 73.5,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id_jenis_bbm', $jenis->id_jenis_bbm)
            ->assertJsonPath('data.jumlah_liter', 73.5);

        $this->assertDatabaseHas('laporan_perjalanan', [
            'id_trip'      => $trip->id_trip,
            'id_jenis_bbm' => $jenis->id_jenis_bbm,
            'jumlah_liter' => 73.5,
        ]);
    }

    public function test_menolak_laporan_dengan_id_jenis_bbm_milik_perusahaan_lain(): void
    {
        $this->actingAsRole('ADMIN');
        $trip = $this->makeTrip(self::PERUSAHAAN_ID);
        $idPerusahaanLain = $this->makePerusahaanLain();
        $jenisLain = $this->makeJenisBbm($idPerusahaanLain, 'Pertalite');

        $res = $this->postJson("/api/v1/trip/{$trip->id_trip}/laporan-perjalanan", [
            'biaya_bbm'       => 500000,
            'jarak_tempuh_km' => 120,
            'uang_jalan'      => 200000,
            'id_jenis_bbm'    => $jenisLain->id_jenis_bbm,
            'jumlah_liter'    => 50,
        ]);

        $res->assertStatus(404)->assertJsonPath('message', 'Jenis BBM tidak ditemukan');
        $this->assertDatabaseCount('laporan_perjalanan', 0);
    }

    public function test_laporan_lama_tanpa_field_bbm_tetap_berhasil_dibuat(): void
    {
        $this->actingAsRole('ADMIN');
        $trip = $this->makeTrip(self::PERUSAHAAN_ID);

        $res = $this->postJson("/api/v1/trip/{$trip->id_trip}/laporan-perjalanan", [
            'biaya_bbm'       => 500000,
            'jarak_tempuh_km' => 120,
            'uang_jalan'      => 200000,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id_jenis_bbm', null)
            ->assertJsonPath('data.jumlah_liter', null);

        $this->assertDatabaseHas('laporan_perjalanan', [
            'id_trip'      => $trip->id_trip,
            'id_jenis_bbm' => null,
        ]);
    }

    public function test_update_laporan_menambahkan_jenis_bbm_dan_jumlah_liter(): void
    {
        $this->actingAsRole('ADMIN');
        $trip = $this->makeTrip(self::PERUSAHAAN_ID);
        $jenis = $this->makeJenisBbm(self::PERUSAHAAN_ID);

        $createRes = $this->postJson("/api/v1/trip/{$trip->id_trip}/laporan-perjalanan", [
            'biaya_bbm'       => 500000,
            'jarak_tempuh_km' => 120,
            'uang_jalan'      => 200000,
        ]);
        $createRes->assertStatus(201);
        $idLaporan = $createRes->json('data.id_laporan');

        $res = $this->putJson("/api/v1/laporan-perjalanan/{$idLaporan}", [
            'id_jenis_bbm' => $jenis->id_jenis_bbm,
            'jumlah_liter' => 60.5,
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.id_jenis_bbm', $jenis->id_jenis_bbm)
            ->assertJsonPath('data.jumlah_liter', 60.5);
    }

    public function test_menolak_update_laporan_dengan_id_jenis_bbm_tidak_valid(): void
    {
        $this->actingAsRole('ADMIN');
        $trip = $this->makeTrip(self::PERUSAHAAN_ID);

        $createRes = $this->postJson("/api/v1/trip/{$trip->id_trip}/laporan-perjalanan", [
            'biaya_bbm'       => 500000,
            'jarak_tempuh_km' => 120,
            'uang_jalan'      => 200000,
        ]);
        $createRes->assertStatus(201);
        $idLaporan = $createRes->json('data.id_laporan');

        $res = $this->putJson("/api/v1/laporan-perjalanan/{$idLaporan}", [
            'id_jenis_bbm' => (string) Str::uuid(),
        ]);

        $res->assertStatus(404)->assertJsonPath('message', 'Jenis BBM tidak ditemukan');
    }
}
