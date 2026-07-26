<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StatusTripTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrip(): string
    {
        $idKlien = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $idKlien,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'Klien Status Trip',
            'dibuat_pada'   => now(),
        ]);

        $idProyek = (string) Str::uuid();
        DB::table('proyek')->insert([
            'id_proyek'     => $idProyek,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $idKlien,
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Status Trip',
            'dibuat_pada'   => now(),
        ]);

        $idPenugasan = (string) Str::uuid();
        DB::table('penugasan')->insert([
            'id_penugasan' => $idPenugasan,
            'id_proyek'    => $idProyek,
            'status'       => 'aktif',
            'dibuat_pada'  => now(),
        ]);

        $idJadwal = (string) Str::uuid();
        DB::table('jadwal_keberangkatan')->insert([
            'id_jadwal'       => $idJadwal,
            'id_penugasan'    => $idPenugasan,
            'waktu_berangkat' => now(),
            'dibuat_pada'     => now(),
        ]);

        $idTrip = (string) Str::uuid();
        DB::table('trip')->insert([
            'id_trip'     => $idTrip,
            'id_jadwal'   => $idJadwal,
            'status'      => 'berjalan',
            'dibuat_pada' => now(),
        ]);
        return $idTrip;
    }

    public function test_menambah_status_trip_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $idTrip = $this->makeTrip();

        $res = $this->postJson("/api/v1/trip/{$idTrip}/status", [
            'status'      => 'tiba_tujuan',
            'keterangan'  => 'Sampai di lokasi bongkar',
            'latitude'    => -6.200000,
            'longitude'   => 106.816666,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'tiba_tujuan')
            ->assertJsonPath('data.latitude', -6.2)
            ->assertJsonPath('data.longitude', 106.816666);

        $this->assertDatabaseHas('status_trip', [
            'id_trip' => $idTrip,
            'status'  => 'tiba_tujuan',
        ]);
    }

    public function test_menambah_status_trip_ke_trip_tidak_ada_mengembalikan_404(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/trip/' . Str::uuid()->toString() . '/status', [
            'status' => 'berangkat',
        ]);

        $res->assertStatus(404);
    }

    public function test_list_status_trip_urut_terbaru_dulu(): void
    {
        $this->actingAsRole('ADMIN');
        $idTrip = $this->makeTrip();

        $this->postJson("/api/v1/trip/{$idTrip}/status", ['status' => 'berangkat']);
        $this->travel(1)->seconds();
        $this->postJson("/api/v1/trip/{$idTrip}/status", ['status' => 'tiba_tujuan']);

        $res = $this->getJson("/api/v1/trip/{$idTrip}/status");

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(2, $data);
        $this->assertSame('tiba_tujuan', $data[0]['status']);
    }
}
