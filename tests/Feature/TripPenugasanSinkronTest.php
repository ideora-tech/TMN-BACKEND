<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use App\Modules\JadwalKeberangkatan\JadwalKeberangkatanModel;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use App\Modules\Trip\TripModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TripPenugasanSinkronTest extends TestCase
{
    use RefreshDatabase;

    private function makeKlien(?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $id,
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'Klien Sinkron Test',
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
            'nama'          => 'Supir Sinkron',
            'no_sim'        => 'SIM-' . Str::random(8),
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makePenugasan(string $statusArmada = 'digunakan', string $statusPenugasan = 'aktif', ?string $idPerusahaan = null): PenugasanModel
    {
        $proyek = ProyekModel::create([
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'id_klien'      => $this->makeKlien($idPerusahaan),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Sinkron Test',
        ]);

        $idArmada = ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B ' . rand(1000, 9999) . ' SK',
            'merk'          => 'Hino',
            'status'        => $statusArmada,
        ])->id_armada;

        return PenugasanModel::create([
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $idArmada,
            'id_supir'  => $this->makeSupir(),
            'status'    => $statusPenugasan,
        ]);
    }

    private function makeTripUntukPenugasan(PenugasanModel $penugasan, string $status = 'berjalan'): TripModel
    {
        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'waktu_berangkat' => now(),
            'rute'            => 'Rute Sinkron',
        ]);

        return TripModel::create([
            'id_jadwal' => $jadwal->id_jadwal,
            'status'    => $status,
        ]);
    }

    public function test_mulai_trip_dari_penugasan_selesai_ditolak(): void
    {
        $this->actingAsRole('ADMIN');
        $penugasan = $this->makePenugasan('tersedia', 'selesai');

        $res = $this->postJson('/api/v1/trip/mulai', ['id_penugasan' => $penugasan->id_penugasan]);

        $res->assertStatus(422);
        $this->assertStringContainsString('selesai', $res->json('message'));
        $this->assertSame(0, DB::table('trip')->count());
    }

    public function test_mulai_trip_dari_penugasan_batal_ditolak(): void
    {
        $this->actingAsRole('ADMIN');
        $penugasan = $this->makePenugasan('tersedia', 'batal');

        $res = $this->postJson('/api/v1/trip/mulai', ['id_penugasan' => $penugasan->id_penugasan]);

        $res->assertStatus(422);
        $this->assertStringContainsString('batal', $res->json('message'));
    }

    public function test_mulai_trip_dari_penugasan_pending_dan_aktif_tetap_boleh(): void
    {
        $this->actingAsRole('ADMIN');

        $pending = $this->makePenugasan('digunakan', 'pending');
        $this->postJson('/api/v1/trip/mulai', ['id_penugasan' => $pending->id_penugasan])->assertStatus(201);

        $aktif = $this->makePenugasan('digunakan', 'aktif');
        $this->postJson('/api/v1/trip/mulai', ['id_penugasan' => $aktif->id_penugasan])->assertStatus(201);
    }

    public function test_checkout_dengan_selesaikan_penugasan_menyelesaikan_penugasan_dan_melepas_armada(): void
    {
        $this->actingAsRole('ADMIN');
        $penugasan = $this->makePenugasan('digunakan', 'aktif');
        $trip = $this->makeTripUntukPenugasan($penugasan, 'berjalan');

        $res = $this->postJson("/api/v1/trip/{$trip->id_trip}/checkout", ['selesaikan_penugasan' => true]);

        $res->assertStatus(200)->assertJsonPath('data.status', 'selesai');
        $this->assertDatabaseHas('penugasan', [
            'id_penugasan' => $penugasan->id_penugasan,
            'status'       => 'selesai',
        ]);
        $this->assertDatabaseHas('armada', [
            'id_armada' => $penugasan->id_armada,
            'status'    => 'tersedia',
        ]);
    }

    public function test_checkout_tanpa_flag_tidak_menyentuh_penugasan_tapi_melepas_armada_fisik(): void
    {
        $this->actingAsRole('ADMIN');
        $penugasan = $this->makePenugasan('digunakan', 'aktif');
        $trip = $this->makeTripUntukPenugasan($penugasan, 'berjalan');

        $this->postJson("/api/v1/trip/{$trip->id_trip}/checkout")->assertStatus(200);

        $this->assertDatabaseHas('penugasan', [
            'id_penugasan' => $penugasan->id_penugasan,
            'status'       => 'aktif',
        ]);
        $this->assertDatabaseHas('armada', [
            'id_armada' => $penugasan->id_armada,
            'status'    => 'tersedia',
        ]);
    }

    public function test_checkout_melepas_armada_fisik_meski_ada_penugasan_aktif_lain(): void
    {
        $this->actingAsRole('ADMIN');
        $penugasan = $this->makePenugasan('digunakan', 'aktif');
        PenugasanModel::create([
            'id_proyek' => $penugasan->id_proyek,
            'id_armada' => $penugasan->id_armada,
            'status'    => 'aktif',
        ]);
        $trip = $this->makeTripUntukPenugasan($penugasan, 'berjalan');

        $this->postJson("/api/v1/trip/{$trip->id_trip}/checkout", ['selesaikan_penugasan' => true])
            ->assertStatus(200);

        $this->assertDatabaseHas('penugasan', [
            'id_penugasan' => $penugasan->id_penugasan,
            'status'       => 'selesai',
        ]);
        $this->assertDatabaseHas('armada', [
            'id_armada' => $penugasan->id_armada,
            'status'    => 'tersedia',
        ]);
    }

    public function test_endpoint_trip_lintas_perusahaan_404(): void
    {
        $this->actingAsRole('ADMIN');
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);

        $penugasanLain = $this->makePenugasan('digunakan', 'aktif', $idPerusahaanLain);
        $tripBerjalan  = $this->makeTripUntukPenugasan($penugasanLain, 'berjalan');
        $tripBelum     = $this->makeTripUntukPenugasan($this->makePenugasan('digunakan', 'aktif', $idPerusahaanLain), 'belum_mulai');

        $this->getJson("/api/v1/trip/{$tripBerjalan->id_trip}")->assertStatus(404);
        $this->postJson("/api/v1/trip/{$tripBelum->id_trip}/checkin")->assertStatus(404);
        $this->postJson("/api/v1/trip/{$tripBerjalan->id_trip}/checkout")->assertStatus(404);
        $this->deleteJson("/api/v1/trip/{$tripBerjalan->id_trip}")->assertStatus(404);

        $this->assertDatabaseHas('trip', [
            'id_trip'      => $tripBerjalan->id_trip,
            'status'       => 'berjalan',
            'dihapus_pada' => null,
        ]);
    }

    public function test_endpoint_trip_perusahaan_sendiri_tetap_jalan(): void
    {
        $this->actingAsRole('ADMIN');
        $penugasan = $this->makePenugasan('digunakan', 'aktif');
        $trip = $this->makeTripUntukPenugasan($penugasan, 'belum_mulai');

        $this->getJson("/api/v1/trip/{$trip->id_trip}")->assertStatus(200);
        $this->postJson("/api/v1/trip/{$trip->id_trip}/checkin")->assertStatus(200);
        $this->postJson("/api/v1/trip/{$trip->id_trip}/checkout")->assertStatus(200);
    }

    public function test_checkout_selesaikan_ditolak_bila_masih_ada_trip_lain_non_final(): void
    {
        $this->actingAsRole('ADMIN');
        $penugasan = $this->makePenugasan('digunakan', 'aktif');
        $tripA = $this->makeTripUntukPenugasan($penugasan, 'berjalan');
        $this->makeTripUntukPenugasan($penugasan, 'belum_mulai');

        $res = $this->postJson("/api/v1/trip/{$tripA->id_trip}/checkout", ['selesaikan_penugasan' => true]);

        $res->assertStatus(422);
        $this->assertStringContainsString('trip lain', $res->json('message'));
        $this->assertDatabaseHas('trip', ['id_trip' => $tripA->id_trip, 'status' => 'berjalan']);
        $this->assertDatabaseHas('penugasan', ['id_penugasan' => $penugasan->id_penugasan, 'status' => 'aktif']);
    }

    public function test_checkout_selesaikan_pada_penugasan_batal_ditolak_dan_checkout_rollback(): void
    {
        $this->actingAsRole('ADMIN');
        $penugasan = $this->makePenugasan('digunakan', 'batal');
        $trip = $this->makeTripUntukPenugasan($penugasan, 'berjalan');

        $res = $this->postJson("/api/v1/trip/{$trip->id_trip}/checkout", ['selesaikan_penugasan' => true]);

        $res->assertStatus(422);
        $this->assertDatabaseHas('trip', ['id_trip' => $trip->id_trip, 'status' => 'berjalan']);
        $this->assertDatabaseHas('penugasan', ['id_penugasan' => $penugasan->id_penugasan, 'status' => 'batal']);
    }

    public function test_checkout_selesaikan_penugasan_vendor_dengan_kontrak_terhapus_tetap_sukses(): void
    {
        $this->actingAsRole('ADMIN');

        $idVendor = (string) Str::uuid();
        DB::table('vendor')->insert([
            'id_vendor'     => $idVendor,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VEN-' . Str::random(8),
            'nama_vendor'   => 'Vendor Sinkron',
            'dibuat_pada'   => now(),
        ]);
        $idKontrak = (string) Str::uuid();
        DB::table('kontrak_vendor')->insert([
            'id_kontrak_vendor' => $idKontrak,
            'id_perusahaan'     => self::PERUSAHAAN_ID,
            'id_vendor'         => $idVendor,
            'mekanisme'         => 'unit_driver',
            'dibuat_pada'       => now(),
            'dihapus_pada'      => now(),
        ]);
        $idArmadaVendor = (string) Str::uuid();
        DB::table('armada_vendor')->insert([
            'id_armada_vendor' => $idArmadaVendor,
            'id_vendor'        => $idVendor,
            'nopol'            => 'D 1111 VS',
            'dibuat_pada'      => now(),
        ]);
        $idSupirVendor = (string) Str::uuid();
        DB::table('supir_vendor')->insert([
            'id_supir_vendor' => $idSupirVendor,
            'id_vendor'       => $idVendor,
            'nama'            => 'Supir Vendor Sinkron',
            'dibuat_pada'     => now(),
        ]);

        $proyek = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $this->makeKlien(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Vendor Sinkron',
        ]);
        $penugasan = PenugasanModel::create([
            'id_proyek'         => $proyek->id_proyek,
            'sumber'            => 'vendor',
            'id_kontrak_vendor' => $idKontrak,
            'id_armada_vendor'  => $idArmadaVendor,
            'id_supir_vendor'   => $idSupirVendor,
            'status'            => 'aktif',
        ]);
        $trip = $this->makeTripUntukPenugasan($penugasan, 'berjalan');

        $res = $this->postJson("/api/v1/trip/{$trip->id_trip}/checkout", ['selesaikan_penugasan' => true]);

        $res->assertStatus(200)->assertJsonPath('data.status', 'selesai');
        $this->assertDatabaseHas('penugasan', [
            'id_penugasan' => $penugasan->id_penugasan,
            'status'       => 'selesai',
        ]);
    }

    public function test_store_trip_jadwal_perusahaan_lain_404(): void
    {
        $this->actingAsRole('ADMIN');
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $penugasanLain = $this->makePenugasan('digunakan', 'aktif', $idPerusahaanLain);
        $jadwalLain = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasanLain->id_penugasan,
            'waktu_berangkat' => now(),
        ]);

        $this->postJson('/api/v1/trip', ['id_jadwal' => $jadwalLain->id_jadwal])->assertStatus(404);
        $this->assertSame(0, DB::table('trip')->count());
    }

    public function test_store_trip_pada_penugasan_final_ditolak(): void
    {
        $this->actingAsRole('ADMIN');
        $penugasan = $this->makePenugasan('tersedia', 'selesai');
        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'waktu_berangkat' => now(),
        ]);

        $res = $this->postJson('/api/v1/trip', ['id_jadwal' => $jadwal->id_jadwal]);

        $res->assertStatus(422);
        $this->assertStringContainsString('selesai', $res->json('message'));
    }

    public function test_checkin_ditolak_bila_penugasan_sudah_final(): void
    {
        $this->actingAsRole('ADMIN');
        $penugasan = $this->makePenugasan('tersedia', 'batal');
        $trip = $this->makeTripUntukPenugasan($penugasan, 'belum_mulai');

        $res = $this->postJson("/api/v1/trip/{$trip->id_trip}/checkin");

        $res->assertStatus(422);
        $this->assertDatabaseHas('trip', ['id_trip' => $trip->id_trip, 'status' => 'belum_mulai']);
    }

    public function test_hapus_penugasan_dengan_trip_non_final_ditolak(): void
    {
        $this->actingAsRole('ADMIN');
        $penugasan = $this->makePenugasan('digunakan', 'aktif');
        $this->makeTripUntukPenugasan($penugasan, 'berjalan');

        $res = $this->deleteJson("/api/v1/penugasan/{$penugasan->id_penugasan}");

        $res->assertStatus(422);
        $this->assertDatabaseHas('penugasan', ['id_penugasan' => $penugasan->id_penugasan, 'dihapus_pada' => null]);
    }

    public function test_hapus_penugasan_dengan_semua_trip_final_tetap_boleh(): void
    {
        $this->actingAsRole('ADMIN');
        $penugasan = $this->makePenugasan('digunakan', 'selesai');
        $this->makeTripUntukPenugasan($penugasan, 'selesai');

        $this->deleteJson("/api/v1/penugasan/{$penugasan->id_penugasan}")->assertStatus(200);
    }

    public function test_hapus_jadwal_dengan_trip_non_final_ditolak(): void
    {
        $this->actingAsRole('ADMIN');
        $penugasan = $this->makePenugasan('digunakan', 'aktif');
        $trip = $this->makeTripUntukPenugasan($penugasan, 'berjalan');

        $res = $this->deleteJson("/api/v1/jadwal/{$trip->id_jadwal}");

        $res->assertStatus(422);
        $this->assertDatabaseHas('jadwal_keberangkatan', ['id_jadwal' => $trip->id_jadwal, 'dihapus_pada' => null]);
    }

    public function test_status_trip_lintas_perusahaan_404(): void
    {
        $this->actingAsRole('ADMIN');
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $penugasanLain = $this->makePenugasan('digunakan', 'aktif', $idPerusahaanLain);
        $tripLain = $this->makeTripUntukPenugasan($penugasanLain, 'berjalan');

        $this->getJson("/api/v1/trip/{$tripLain->id_trip}/status")->assertStatus(404);
        $this->postJson("/api/v1/trip/{$tripLain->id_trip}/status", ['status' => 'berangkat'])->assertStatus(404);
        $this->assertSame(0, DB::table('status_trip')->count());
    }
}
