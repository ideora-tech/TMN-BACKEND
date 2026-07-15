<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\JadwalKeberangkatan\JadwalKeberangkatanModel;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use App\Modules\Trip\TripModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RekapBiayaTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrip(string $status, ?float $estimasiBiaya = null): TripModel
    {
        $proyek = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => (string) Str::uuid(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Test Rekap Biaya',
        ]);

        $penugasan = PenugasanModel::create([
            'id_proyek'      => $proyek->id_proyek,
            'estimasi_biaya' => $estimasiBiaya,
        ]);

        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan' => $penugasan->id_penugasan,
        ]);

        return TripModel::create([
            'id_jadwal' => $jadwal->id_jadwal,
            'status'    => $status,
        ]);
    }

    private function makeTripUntukPerusahaanLain(string $status): TripModel
    {
        $idPerusahaanLain = (string) Str::uuid();

        DB::table('perusahaan')->insert([
            'id_perusahaan' => $idPerusahaanLain,
            'nama'          => 'Perusahaan Lain Test',
            'dibuat_pada'   => now(),
        ]);

        $proyek = ProyekModel::create([
            'id_perusahaan' => $idPerusahaanLain,
            'id_klien'      => (string) Str::uuid(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Perusahaan Lain',
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

    public function test_rekap_biaya_menghitung_total_dari_laporan_dan_selisih_estimasi(): void
    {
        $this->actingAsRole('ADMIN');
        $trip = $this->makeTrip('selesai', 1000000);

        $this->postJson("/api/v1/trip/{$trip->id_trip}/laporan-perjalanan", [
            'biaya_bbm'       => 500000,
            'jarak_tempuh_km' => 120,
            'uang_jalan'      => 200000,
            'biaya_lain'      => [
                ['nama_biaya' => 'Tol', 'nominal' => 75000],
            ],
        ])->assertStatus(201);

        $res = $this->getJson("/api/v1/trip/{$trip->id_trip}/rekap-biaya");

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_bbm', 500000)
            ->assertJsonPath('data.total_uang_jalan', 200000)
            ->assertJsonPath('data.total_biaya_lain', 75000)
            ->assertJsonPath('data.total_keseluruhan', 775000)
            ->assertJsonPath('data.estimasi_biaya', 1000000)
            ->assertJsonPath('data.selisih', 225000)
            ->assertJsonPath('data.jarak_tempuh_km', 120);

        $this->assertCount(1, $res->json('data.items'));
        $this->assertSame('Tol', $res->json('data.items.0.nama_biaya'));
    }

    public function test_rekap_biaya_trip_tanpa_laporan_semua_nol_tapi_estimasi_tetap_terisi(): void
    {
        $this->actingAsRole('ADMIN');
        $trip = $this->makeTrip('berjalan', 500000);

        $res = $this->getJson("/api/v1/trip/{$trip->id_trip}/rekap-biaya");

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_bbm', 0)
            ->assertJsonPath('data.total_uang_jalan', 0)
            ->assertJsonPath('data.total_biaya_lain', 0)
            ->assertJsonPath('data.total_keseluruhan', 0)
            ->assertJsonPath('data.estimasi_biaya', 500000)
            ->assertJsonPath('data.selisih', 500000)
            ->assertJsonPath('data.jarak_tempuh_km', null);

        $this->assertSame([], $res->json('data.items'));
    }

    public function test_rekap_biaya_selisih_null_bila_estimasi_null(): void
    {
        $this->actingAsRole('ADMIN');
        $trip = $this->makeTrip('berjalan', null);

        $res = $this->getJson("/api/v1/trip/{$trip->id_trip}/rekap-biaya");

        $res->assertStatus(200);

        $data = $res->json('data');
        $this->assertArrayHasKey('estimasi_biaya', $data);
        $this->assertArrayHasKey('selisih', $data);
        $this->assertNull($data['estimasi_biaya']);
        $this->assertNull($data['selisih']);
    }

    public function test_batalkan_trip_mengubah_status_menjadi_dibatalkan(): void
    {
        $this->actingAsRole('ADMIN');
        $trip = $this->makeTrip('belum_mulai');

        $res = $this->postJson("/api/v1/trip/{$trip->id_trip}/batalkan");

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'dibatalkan');

        $this->assertDatabaseHas('trip', [
            'id_trip' => $trip->id_trip,
            'status'  => 'dibatalkan',
        ]);
    }

    public function test_batalkan_trip_yang_sudah_selesai_ditolak(): void
    {
        $this->actingAsRole('ADMIN');
        $trip = $this->makeTrip('selesai');

        $res = $this->postJson("/api/v1/trip/{$trip->id_trip}/batalkan");

        $res->assertStatus(422);

        $this->assertDatabaseHas('trip', [
            'id_trip' => $trip->id_trip,
            'status'  => 'selesai',
        ]);
    }

    public function test_batalkan_trip_milik_perusahaan_lain_ditolak_404(): void
    {
        $this->actingAsRole('ADMIN');
        $trip = $this->makeTripUntukPerusahaanLain('belum_mulai');

        $res = $this->postJson("/api/v1/trip/{$trip->id_trip}/batalkan");

        $res->assertStatus(404);

        $this->assertDatabaseHas('trip', [
            'id_trip' => $trip->id_trip,
            'status'  => 'belum_mulai',
        ]);
    }

    public function test_rekap_biaya_trip_milik_perusahaan_lain_ditolak_404(): void
    {
        $this->actingAsRole('ADMIN');
        $trip = $this->makeTripUntukPerusahaanLain('selesai');

        $res = $this->getJson("/api/v1/trip/{$trip->id_trip}/rekap-biaya");

        $res->assertStatus(404);
    }

    public function test_get_trip_bisa_difilter_dengan_id_jadwal(): void
    {
        $this->actingAsRole('ADMIN');
        $tripA = $this->makeTrip('belum_mulai');
        $this->makeTrip('belum_mulai');

        $res = $this->getJson("/api/v1/trip?id_jadwal={$tripA->id_jadwal}");

        $res->assertStatus(200);

        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($tripA->id_trip, $data[0]['id_trip']);
        $this->assertSame($tripA->id_jadwal, $data[0]['id_jadwal']);
    }
}
