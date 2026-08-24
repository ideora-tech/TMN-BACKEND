<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use App\Modules\JadwalKeberangkatan\JadwalKeberangkatanModel;
use App\Modules\Proyek\ProyekModel;
use App\Modules\Trip\TripModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Armada lintas proyek: penugasan tidak lagi mengunci status armada.
 * Status armada murni cermin kondisi fisik yang digerakkan lifecycle trip —
 * checkin 'digunakan', checkout/batal 'tersedia'; 'perawatan'/'tidak_aktif'
 * di-set manual dan memblokir checkin.
 */
class PenugasanArmadaLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function makeArmada(string $status = 'tersedia'): ArmadaModel
    {
        return ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B ' . random_int(1000, 9999) . ' ' . Str::random(3),
            'merk'          => 'Hino',
            'status'        => $status,
        ]);
    }

    private function makeProyek(): ProyekModel
    {
        $idKlien = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $idKlien,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'Klien Lifecycle Test',
            'dibuat_pada'   => now(),
        ]);

        return ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $idKlien,
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Lifecycle Test',
        ]);
    }

    private function makeTrip(string $idPenugasan, string $status = 'belum_mulai'): TripModel
    {
        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $idPenugasan,
            'waktu_berangkat' => now(),
        ]);

        return TripModel::create([
            'id_jadwal' => $jadwal->id_jadwal,
            'status'    => $status,
        ]);
    }

    private function buatLaporanKosong(string $idTrip): void
    {
        DB::table('laporan_perjalanan')->insert([
            'id_laporan'    => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_trip'       => $idTrip,
            'dibuat_pada'   => now(),
        ]);
    }

    public function test_create_penugasan_tidak_mengubah_status_armada(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada('tersedia');
        $proyek = $this->makeProyek();

        $this->postJson('/api/v1/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $armada->id_armada,
        ])->assertStatus(201);

        $this->assertSame('tersedia', $armada->fresh()->status);
    }

    public function test_satu_armada_boleh_ditugaskan_ke_banyak_proyek(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada  = $this->makeArmada('tersedia');
        $proyekA = $this->makeProyek();
        $proyekB = $this->makeProyek();

        $this->postJson('/api/v1/penugasan', [
            'id_proyek' => $proyekA->id_proyek,
            'id_armada' => $armada->id_armada,
            'status'    => 'aktif',
        ])->assertStatus(201);

        $this->postJson('/api/v1/penugasan', [
            'id_proyek' => $proyekB->id_proyek,
            'id_armada' => $armada->id_armada,
            'status'    => 'aktif',
        ])->assertStatus(201);

        $this->assertSame(2, DB::table('penugasan')->where('id_armada', $armada->id_armada)->count());
        $this->assertSame('tersedia', $armada->fresh()->status);
    }

    public function test_create_penugasan_dengan_armada_sedang_digunakan_tetap_boleh(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada('digunakan');
        $proyek = $this->makeProyek();

        $this->postJson('/api/v1/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $armada->id_armada,
        ])->assertStatus(201);
    }

    public function test_create_penugasan_dengan_armada_tidak_ada_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();

        $this->postJson('/api/v1/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => (string) Str::uuid(),
        ])->assertStatus(422);
    }

    public function test_ubah_status_dan_hapus_penugasan_tidak_menyentuh_status_armada(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada('tersedia');
        $proyek = $this->makeProyek();

        $idPenugasan = $this->postJson('/api/v1/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $armada->id_armada,
            'status'    => 'aktif',
        ])->json('data.id_penugasan');

        $this->putJson("/api/v1/penugasan/{$idPenugasan}", ['status' => 'selesai'])->assertStatus(200);
        $this->assertSame('tersedia', $armada->fresh()->status);

        // Penugasan selesai terkunci dari penghapusan — buka kembali dulu
        $this->deleteJson("/api/v1/penugasan/{$idPenugasan}")->assertStatus(422);
        $this->putJson("/api/v1/penugasan/{$idPenugasan}", ['status' => 'aktif'])->assertStatus(200);

        $this->deleteJson("/api/v1/penugasan/{$idPenugasan}")->assertStatus(200);
        $this->assertSame('tersedia', $armada->fresh()->status);
    }

    public function test_checkin_mengunci_armada_dan_checkout_melepasnya(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada('tersedia');
        $proyek = $this->makeProyek();

        $idPenugasan = $this->postJson('/api/v1/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $armada->id_armada,
            'status'    => 'aktif',
        ])->json('data.id_penugasan');
        $trip = $this->makeTrip($idPenugasan);

        $this->postJson("/api/v1/trip/{$trip->id_trip}/checkin")->assertStatus(200);
        $this->assertSame('digunakan', $armada->fresh()->status);

        $this->buatLaporanKosong($trip->id_trip);
        $this->postJson("/api/v1/trip/{$trip->id_trip}/checkout")->assertStatus(200);
        $this->assertSame('tersedia', $armada->fresh()->status);
    }

    public function test_mulai_trip_via_endpoint_mulai_juga_mengunci_armada(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada('tersedia');
        $proyek = $this->makeProyek();

        $idPenugasan = $this->postJson('/api/v1/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $armada->id_armada,
            'status'    => 'aktif',
        ])->json('data.id_penugasan');

        $this->postJson('/api/v1/trip/mulai', ['id_penugasan' => $idPenugasan])->assertStatus(201);
        $this->assertSame('digunakan', $armada->fresh()->status);
    }

    public function test_batalkan_trip_berjalan_melepas_armada(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada('tersedia');
        $proyek = $this->makeProyek();

        $idPenugasan = $this->postJson('/api/v1/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $armada->id_armada,
            'status'    => 'aktif',
        ])->json('data.id_penugasan');
        $trip = $this->makeTrip($idPenugasan);

        $this->postJson("/api/v1/trip/{$trip->id_trip}/checkin")->assertStatus(200);
        $this->assertSame('digunakan', $armada->fresh()->status);

        $this->postJson("/api/v1/trip/{$trip->id_trip}/batalkan")->assertStatus(200);
        $this->assertSame('tersedia', $armada->fresh()->status);
    }

    public function test_checkin_ditolak_saat_armada_perawatan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada('perawatan');
        $proyek = $this->makeProyek();

        $idPenugasan = $this->postJson('/api/v1/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $armada->id_armada,
            'status'    => 'aktif',
        ])->json('data.id_penugasan');
        $trip = $this->makeTrip($idPenugasan);

        $res = $this->postJson("/api/v1/trip/{$trip->id_trip}/checkin");

        $res->assertStatus(422);
        $this->assertStringContainsString('perawatan', $res->json('message'));
        $this->assertDatabaseHas('trip', ['id_trip' => $trip->id_trip, 'status' => 'belum_mulai']);
    }

    public function test_mulai_trip_ditolak_saat_armada_perawatan_dan_tidak_meninggalkan_sisa(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada('tidak_aktif');
        $proyek = $this->makeProyek();

        $idPenugasan = $this->postJson('/api/v1/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $armada->id_armada,
            'status'    => 'aktif',
        ])->json('data.id_penugasan');

        $this->postJson('/api/v1/trip/mulai', ['id_penugasan' => $idPenugasan])->assertStatus(422);

        $this->assertSame(0, DB::table('trip')->count());
        $this->assertSame(0, DB::table('jadwal_keberangkatan')->where('id_penugasan', $idPenugasan)->count());
    }

    public function test_checkout_tidak_menimpa_status_perawatan_yang_diset_di_tengah_trip(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada('tersedia');
        $proyek = $this->makeProyek();

        $idPenugasan = $this->postJson('/api/v1/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $armada->id_armada,
            'status'    => 'aktif',
        ])->json('data.id_penugasan');
        $trip = $this->makeTrip($idPenugasan);

        $this->postJson("/api/v1/trip/{$trip->id_trip}/checkin")->assertStatus(200);
        $armada->fresh()->update(['status' => 'perawatan']);

        $this->buatLaporanKosong($trip->id_trip);
        $this->postJson("/api/v1/trip/{$trip->id_trip}/checkout")->assertStatus(200);

        $this->assertSame('perawatan', $armada->fresh()->status);
    }

    private function buatPenugasanAktif(ArmadaModel $armada, ?string $idSupir = null): string
    {
        $proyek = $this->makeProyek();

        return (string) $this->postJson('/api/v1/penugasan', array_filter([
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $armada->id_armada,
            'id_supir'  => $idSupir,
            'status'    => 'aktif',
        ]))->json('data.id_penugasan');
    }

    public function test_checkin_kedua_untuk_armada_sama_ditolak_meski_beda_proyek(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada('tersedia');

        $tripA = $this->makeTrip($this->buatPenugasanAktif($armada));
        $tripB = $this->makeTrip($this->buatPenugasanAktif($armada));

        $this->postJson("/api/v1/trip/{$tripA->id_trip}/checkin")->assertStatus(200);

        $res = $this->postJson("/api/v1/trip/{$tripB->id_trip}/checkin");

        $res->assertStatus(422);
        $this->assertStringContainsString('sedang berjalan', $res->json('message'));
        $this->assertDatabaseHas('trip', ['id_trip' => $tripB->id_trip, 'status' => 'belum_mulai']);
        $this->assertSame('digunakan', $armada->fresh()->status);
    }

    public function test_checkout_tidak_melepas_armada_bila_masih_ada_trip_berjalan_lain(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada('digunakan');

        $tripA = $this->makeTrip($this->buatPenugasanAktif($armada), 'berjalan');
        $this->makeTrip($this->buatPenugasanAktif($armada), 'berjalan');

        $this->buatLaporanKosong($tripA->id_trip);
        $this->postJson("/api/v1/trip/{$tripA->id_trip}/checkout")->assertStatus(200);

        $this->assertSame('digunakan', $armada->fresh()->status);
    }

    public function test_hapus_trip_berjalan_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada('tersedia');
        $trip = $this->makeTrip($this->buatPenugasanAktif($armada), 'berjalan');

        $this->deleteJson("/api/v1/trip/{$trip->id_trip}")->assertStatus(422);
        $this->assertDatabaseHas('trip', ['id_trip' => $trip->id_trip, 'dihapus_pada' => null]);

        $this->buatLaporanKosong($trip->id_trip);
        $this->postJson("/api/v1/trip/{$trip->id_trip}/checkout")->assertStatus(200);
        $this->deleteJson("/api/v1/trip/{$trip->id_trip}")->assertStatus(200);
    }

    public function test_ganti_armada_penugasan_dengan_trip_berjalan_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armadaLama = $this->makeArmada('tersedia');
        $armadaBaru = $this->makeArmada('tersedia');
        $idPenugasan = $this->buatPenugasanAktif($armadaLama);
        $this->makeTrip($idPenugasan, 'berjalan');

        $res = $this->putJson("/api/v1/penugasan/{$idPenugasan}", ['id_armada' => $armadaBaru->id_armada]);

        $res->assertStatus(422);
        $this->assertDatabaseHas('penugasan', ['id_penugasan' => $idPenugasan, 'id_armada' => $armadaLama->id_armada]);
    }

    public function test_ganti_armada_penugasan_ke_armada_tidak_ada_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada('tersedia');
        $idPenugasan = $this->buatPenugasanAktif($armada);

        $this->putJson("/api/v1/penugasan/{$idPenugasan}", ['id_armada' => (string) Str::uuid()])
            ->assertStatus(422);
    }

    public function test_jumlah_penugasan_aktif_muncul_di_list_dan_detail_armada(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada('tersedia');
        $this->buatPenugasanAktif($armada);
        $this->buatPenugasanAktif($armada);

        $list = $this->getJson('/api/v1/armada?search=' . urlencode($armada->nopol));
        $list->assertStatus(200);
        $this->assertSame(2, $list->json('data.0.jumlah_penugasan_aktif'));

        $detail = $this->getJson("/api/v1/armada/{$armada->id_armada}");
        $detail->assertStatus(200)->assertJsonPath('data.jumlah_penugasan_aktif', 2);
    }

    public function test_jumlah_penugasan_aktif_tidak_menghitung_penugasan_proyek_terhapus(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada('tersedia');
        $this->buatPenugasanAktif($armada);

        $proyekTerhapus = $this->makeProyek();
        $this->postJson('/api/v1/penugasan', [
            'id_proyek' => $proyekTerhapus->id_proyek,
            'id_armada' => $armada->id_armada,
            'status'    => 'aktif',
        ])->assertStatus(201);
        DB::table('proyek')->where('id_proyek', $proyekTerhapus->id_proyek)->update(['dihapus_pada' => now()]);

        $this->getJson("/api/v1/armada/{$armada->id_armada}")
            ->assertStatus(200)
            ->assertJsonPath('data.jumlah_penugasan_aktif', 1);
    }
}
