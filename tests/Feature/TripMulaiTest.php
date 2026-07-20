<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TripMulaiTest extends TestCase
{
    use RefreshDatabase;

    private function makeKlien(?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $id,
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'Klien Mulai Trip',
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makeSupir(): string
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => 'Budi Santoso',
            'no_sim'        => 'SIM-' . Str::random(8),
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makeRute(string $nama = 'Jakarta - Bandung', ?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute'       => $id,
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'kode_rute'     => 'RUT-' . Str::random(8),
            'nama_rute'     => $nama,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makePenugasan(?string $idPerusahaan = null): PenugasanModel
    {
        $proyek = ProyekModel::create([
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'id_klien'      => $this->makeKlien($idPerusahaan),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Mulai Trip',
        ]);

        $idArmada = ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B ' . rand(1000, 9999) . ' MT',
            'merk'          => 'Hino',
        ])->id_armada;

        return PenugasanModel::create([
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $idArmada,
            'id_supir'  => $this->makeSupir(),
        ]);
    }

    public function test_mulai_trip_membuat_jadwal_otomatis_dan_trip_berjalan(): void
    {
        $this->actingAsRole('ADMIN');
        $penugasan = $this->makePenugasan();
        $idRute    = $this->makeRute('Jakarta - Bandung');

        $res = $this->postJson('/api/v1/trip/mulai', [
            'id_penugasan' => $penugasan->id_penugasan,
            'id_rute'      => $idRute,
        ]);

        $res->assertStatus(201)->assertJsonPath('data.status', 'berjalan');

        $this->assertDatabaseHas('jadwal_keberangkatan', [
            'id_penugasan' => $penugasan->id_penugasan,
            'id_rute'      => $idRute,
            'rute'         => 'Jakarta - Bandung',
        ]);
        $this->assertSame(1, DB::table('trip')->whereNotNull('waktu_checkin')->where('status', 'berjalan')->count());
    }

    public function test_mulai_trip_tanpa_rute_boleh(): void
    {
        $this->actingAsRole('ADMIN');
        $penugasan = $this->makePenugasan();

        $this->postJson('/api/v1/trip/mulai', ['id_penugasan' => $penugasan->id_penugasan])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'berjalan');
    }

    public function test_mulai_trip_ditolak_jika_masih_ada_trip_aktif(): void
    {
        $this->actingAsRole('ADMIN');
        $penugasan = $this->makePenugasan();

        $this->postJson('/api/v1/trip/mulai', ['id_penugasan' => $penugasan->id_penugasan])->assertStatus(201);

        $res = $this->postJson('/api/v1/trip/mulai', ['id_penugasan' => $penugasan->id_penugasan]);
        $res->assertStatus(422);
        $this->assertStringContainsString('trip aktif', $res->json('message'));
    }

    public function test_setelah_checkout_bisa_mulai_trip_lagi(): void
    {
        $this->actingAsRole('ADMIN');
        $penugasan = $this->makePenugasan();

        $idTrip = $this->postJson('/api/v1/trip/mulai', ['id_penugasan' => $penugasan->id_penugasan])
            ->json('data.id_trip');
        $this->postJson("/api/v1/trip/{$idTrip}/checkout")->assertStatus(200);

        $this->postJson('/api/v1/trip/mulai', ['id_penugasan' => $penugasan->id_penugasan])->assertStatus(201);
    }

    public function test_mulai_trip_penugasan_perusahaan_lain_404(): void
    {
        $this->actingAsRole('ADMIN');
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $penugasanLain = $this->makePenugasan($idPerusahaanLain);

        $this->postJson('/api/v1/trip/mulai', ['id_penugasan' => $penugasanLain->id_penugasan])
            ->assertStatus(404);
    }

    public function test_mulai_trip_id_rute_perusahaan_lain_404(): void
    {
        $this->actingAsRole('ADMIN');
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $ruteLain  = $this->makeRute('Rute Rahasia Perusahaan Lain', $idPerusahaanLain);
        $penugasan = $this->makePenugasan();

        $res = $this->postJson('/api/v1/trip/mulai', [
            'id_penugasan' => $penugasan->id_penugasan,
            'id_rute'      => $ruteLain,
        ]);

        $res->assertStatus(404);
        $this->assertDatabaseMissing('jadwal_keberangkatan', [
            'id_penugasan' => $penugasan->id_penugasan,
        ]);
        $this->assertSame(0, DB::table('trip')->count());
    }

    public function test_list_trip_filter_id_penugasan_dan_id_supir(): void
    {
        $this->actingAsRole('ADMIN');
        $penugasanA = $this->makePenugasan();
        $penugasanB = $this->makePenugasan();

        $this->postJson('/api/v1/trip/mulai', ['id_penugasan' => $penugasanA->id_penugasan])->assertStatus(201);
        $this->postJson('/api/v1/trip/mulai', ['id_penugasan' => $penugasanB->id_penugasan])->assertStatus(201);

        $byPenugasan = $this->getJson("/api/v1/trip?id_penugasan={$penugasanA->id_penugasan}");
        $byPenugasan->assertStatus(200);
        $this->assertCount(1, $byPenugasan->json('data'));

        $bySupir = $this->getJson("/api/v1/trip?id_supir={$penugasanB->id_supir}");
        $bySupir->assertStatus(200);
        $this->assertCount(1, $bySupir->json('data'));
    }
}
