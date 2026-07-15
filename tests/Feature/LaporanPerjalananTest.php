<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\JadwalKeberangkatan\JadwalKeberangkatanModel;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use App\Modules\Trip\TripModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class LaporanPerjalananTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrip(string $status): TripModel
    {
        $proyek = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => (string) Str::uuid(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Test Laporan Perjalanan',
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

    public function test_membuat_laporan_untuk_trip_selesai(): void
    {
        $this->actingAsRole('ADMIN');
        $trip = $this->makeTrip('selesai');

        $res = $this->postJson("/api/v1/trip/{$trip->id_trip}/laporan-perjalanan", [
            'biaya_bbm'       => 500000,
            'jarak_tempuh_km' => 120,
            'uang_jalan'      => 200000,
            'catatan_insiden' => null,
            'biaya_lain'      => [
                ['nama_biaya' => 'Tol', 'nominal' => 75000],
            ],
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.biaya_lain.0.nama_biaya', 'Tol');

        $this->assertDatabaseHas('laporan_perjalanan', [
            'id_trip' => $trip->id_trip,
        ]);
    }

    public function test_menolak_laporan_duplikat(): void
    {
        $this->actingAsRole('ADMIN');
        $trip = $this->makeTrip('selesai');

        $payload = [
            'biaya_bbm'       => 500000,
            'jarak_tempuh_km' => 120,
            'uang_jalan'      => 200000,
        ];

        $this->postJson("/api/v1/trip/{$trip->id_trip}/laporan-perjalanan", $payload)
            ->assertStatus(201);

        $res = $this->postJson("/api/v1/trip/{$trip->id_trip}/laporan-perjalanan", $payload);

        $res->assertStatus(409);
    }

    public function test_menolak_laporan_untuk_trip_perusahaan_lain(): void
    {
        $this->actingAsRole('ADMIN');
        $trip = $this->makeTripUntukPerusahaanLain('selesai');

        $res = $this->postJson("/api/v1/trip/{$trip->id_trip}/laporan-perjalanan", [
            'biaya_bbm'       => 500000,
            'jarak_tempuh_km' => 120,
            'uang_jalan'      => 200000,
        ]);

        $res->assertStatus(404);
        $this->assertDatabaseCount('laporan_perjalanan', 0);
    }

    public function test_menolak_get_laporan_untuk_trip_perusahaan_lain(): void
    {
        $this->actingAsRole('ADMIN');
        $trip = $this->makeTripUntukPerusahaanLain('selesai');

        $res = $this->getJson("/api/v1/trip/{$trip->id_trip}/laporan-perjalanan");

        $res->assertStatus(404);
    }

    public function test_menolak_trip_belum_mulai(): void
    {
        $this->actingAsRole('ADMIN');
        $trip = $this->makeTrip('belum_mulai');

        $res = $this->postJson("/api/v1/trip/{$trip->id_trip}/laporan-perjalanan", [
            'biaya_bbm'       => 500000,
            'jarak_tempuh_km' => 120,
            'uang_jalan'      => 200000,
        ]);

        $res->assertStatus(422);
    }

    public function test_get_laporan_dengan_relasi(): void
    {
        $this->actingAsRole('ADMIN');
        $trip = $this->makeTrip('selesai');

        $this->postJson("/api/v1/trip/{$trip->id_trip}/laporan-perjalanan", [
            'biaya_bbm'       => 500000,
            'jarak_tempuh_km' => 120,
            'uang_jalan'      => 200000,
            'biaya_lain'      => [
                ['nama_biaya' => 'Tol', 'nominal' => 75000],
            ],
        ])->assertStatus(201);

        $res = $this->getJson("/api/v1/trip/{$trip->id_trip}/laporan-perjalanan");

        $res->assertStatus(200)
            ->assertJsonPath('success', true);

        $data = $res->json('data');
        $this->assertIsArray($data['biaya_lain']);
        $this->assertIsArray($data['foto']);
        $this->assertCount(1, $data['biaya_lain']);
    }

    public function test_update_mengganti_biaya_lain(): void
    {
        $this->actingAsRole('ADMIN');
        $trip = $this->makeTrip('selesai');

        $createRes = $this->postJson("/api/v1/trip/{$trip->id_trip}/laporan-perjalanan", [
            'biaya_bbm'       => 500000,
            'jarak_tempuh_km' => 120,
            'uang_jalan'      => 200000,
            'biaya_lain'      => [
                ['nama_biaya' => 'Tol', 'nominal' => 75000],
            ],
        ]);
        $createRes->assertStatus(201);
        $idLaporan = $createRes->json('data.id_laporan');

        $res = $this->putJson("/api/v1/laporan-perjalanan/{$idLaporan}", [
            'biaya_lain' => [
                ['nama_biaya' => 'Parkir', 'nominal' => 20000],
            ],
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('success', true);

        $data = $res->json('data');
        $this->assertCount(1, $data['biaya_lain']);
        $this->assertSame('Parkir', $data['biaya_lain'][0]['nama_biaya']);

        $this->assertDatabaseHas('biaya_lain_trip', [
            'id_laporan' => $idLaporan,
            'nama_biaya' => 'Parkir',
            'dihapus_pada' => null,
        ]);
        $this->assertDatabaseHas('biaya_lain_trip', [
            'id_laporan' => $idLaporan,
            'nama_biaya' => 'Tol',
        ]);
        $this->assertDatabaseMissing('biaya_lain_trip', [
            'id_laporan' => $idLaporan,
            'nama_biaya' => 'Tol',
            'dihapus_pada' => null,
        ]);
    }

    public function test_menolak_update_laporan_milik_perusahaan_lain(): void
    {
        $this->actingAsRole('ADMIN');
        $trip = $this->makeTrip('selesai');

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert([
            'id_perusahaan' => $idPerusahaanLain,
            'nama'          => 'Perusahaan Lain Test',
            'dibuat_pada'   => now(),
        ]);

        $idLaporan = (string) Str::uuid();
        DB::table('laporan_perjalanan')->insert([
            'id_laporan'      => $idLaporan,
            'id_perusahaan'   => $idPerusahaanLain,
            'id_trip'         => $trip->id_trip,
            'biaya_bbm'       => 100000,
            'jarak_tempuh_km' => 10,
            'uang_jalan'      => 50000,
            'dibuat_pada'     => now(),
        ]);

        $res = $this->putJson("/api/v1/laporan-perjalanan/{$idLaporan}", [
            'biaya_bbm' => 200000,
        ]);

        $res->assertStatus(404);

        $this->assertDatabaseHas('laporan_perjalanan', [
            'id_laporan' => $idLaporan,
            'biaya_bbm'  => 100000,
        ]);
    }

    public function test_upload_foto(): void
    {
        Storage::fake('public');
        $this->actingAsRole('ADMIN');
        $trip = $this->makeTrip('selesai');

        $createRes = $this->postJson("/api/v1/trip/{$trip->id_trip}/laporan-perjalanan", [
            'biaya_bbm'       => 500000,
            'jarak_tempuh_km' => 120,
            'uang_jalan'      => 200000,
        ]);
        $createRes->assertStatus(201);
        $idLaporan = $createRes->json('data.id_laporan');

        $res = $this->postJson("/api/v1/laporan-perjalanan/{$idLaporan}/foto", [
            'file'       => UploadedFile::fake()->image('muatan.jpg'),
            'keterangan' => 'Muatan depan',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertIsString($res->json('data.url_file'));
        $this->assertNotEmpty($res->json('data.url_file'));

        $this->assertDatabaseHas('foto_laporan_perjalanan', [
            'id_laporan' => $idLaporan,
            'keterangan' => 'Muatan depan',
        ]);
    }
}
