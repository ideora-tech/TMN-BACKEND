<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\ArmadaVendor\ArmadaVendorModel;
use App\Modules\JadwalKeberangkatan\JadwalKeberangkatanModel;
use App\Modules\KontrakVendor\KontrakVendorModel;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class JadwalBentrokTest extends TestCase
{
    use RefreshDatabase;

    private function makeProyek(): ProyekModel
    {
        return ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => (string) Str::uuid(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Test Jadwal Bentrok',
        ]);
    }

    private function makePenugasan(?string $idArmada = null, ?string $idSupir = null): PenugasanModel
    {
        return PenugasanModel::create([
            'id_proyek' => $this->makeProyek()->id_proyek,
            'id_armada' => $idArmada,
            'id_supir'  => $idSupir,
        ]);
    }

    private function makeJadwal(string $idPenugasan, ?string $mulai, ?string $selesai = null): JadwalKeberangkatanModel
    {
        return JadwalKeberangkatanModel::create([
            'id_penugasan'    => $idPenugasan,
            'waktu_berangkat' => $mulai,
            'estimasi_tiba'   => $selesai,
        ]);
    }

    private function makeVendor(): VendorModel
    {
        return VendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => 'Vendor Test Jadwal Bentrok',
        ]);
    }

    private function makeKontrak(string $idVendor): KontrakVendorModel
    {
        return KontrakVendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_vendor'     => $idVendor,
            'mekanisme'     => 'unit_only',
        ]);
    }

    private function makeArmadaVendor(string $idVendor): ArmadaVendorModel
    {
        return ArmadaVendorModel::create([
            'id_vendor' => $idVendor,
            'nopol'     => 'B ' . random_int(1000, 9999) . ' VD',
        ]);
    }

    private function makePenugasanVendor(string $idKontrakVendor, string $idArmadaVendor): PenugasanModel
    {
        return PenugasanModel::create([
            'id_proyek'         => $this->makeProyek()->id_proyek,
            'sumber'            => 'vendor',
            'id_kontrak_vendor' => $idKontrakVendor,
            'id_armada_vendor'  => $idArmadaVendor,
            'id_supir'          => (string) Str::uuid(),
        ]);
    }

    public function test_create_jadwal_bentrok_armada_sama_ditolak_422(): void
    {
        $this->actingAsRole('ADMIN');

        $idArmada = (string) Str::uuid();
        $penugasanA = $this->makePenugasan($idArmada, (string) Str::uuid());
        $this->makeJadwal($penugasanA->id_penugasan, '2026-08-01 08:00:00', '2026-08-01 12:00:00');

        $penugasanB = $this->makePenugasan($idArmada, (string) Str::uuid());

        $res = $this->postJson('/api/v1/jadwal', [
            'id_penugasan'    => $penugasanB->id_penugasan,
            'waktu_berangkat' => '2026-08-01 10:00:00',
            'estimasi_tiba'   => '2026-08-01 14:00:00',
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsStringIgnoringCase('bentrok', (string) $res->json('message'));
    }

    public function test_create_jadwal_non_overlap_armada_sama_diterima_201(): void
    {
        $this->actingAsRole('ADMIN');

        $idArmada = (string) Str::uuid();
        $penugasanA = $this->makePenugasan($idArmada, (string) Str::uuid());
        $this->makeJadwal($penugasanA->id_penugasan, '2026-08-01 08:00:00', '2026-08-01 12:00:00');

        $penugasanB = $this->makePenugasan($idArmada, (string) Str::uuid());

        $res = $this->postJson('/api/v1/jadwal', [
            'id_penugasan'    => $penugasanB->id_penugasan,
            'waktu_berangkat' => '2026-08-01 13:00:00',
            'estimasi_tiba'   => '2026-08-01 17:00:00',
        ]);

        $res->assertStatus(201);
    }

    public function test_create_jadwal_armada_dan_supir_beda_diterima_201(): void
    {
        $this->actingAsRole('ADMIN');

        $penugasanA = $this->makePenugasan((string) Str::uuid(), (string) Str::uuid());
        $this->makeJadwal($penugasanA->id_penugasan, '2026-08-01 08:00:00', '2026-08-01 12:00:00');

        $penugasanB = $this->makePenugasan((string) Str::uuid(), (string) Str::uuid());

        $res = $this->postJson('/api/v1/jadwal', [
            'id_penugasan'    => $penugasanB->id_penugasan,
            'waktu_berangkat' => '2026-08-01 08:00:00',
            'estimasi_tiba'   => '2026-08-01 12:00:00',
        ]);

        $res->assertStatus(201);
    }

    public function test_jadwal_existing_tanpa_estimasi_tiba_fallback_8_jam_tetap_terdeteksi(): void
    {
        $this->actingAsRole('ADMIN');

        $idArmada = (string) Str::uuid();
        $penugasanA = $this->makePenugasan($idArmada, (string) Str::uuid());
        // Tanpa estimasi_tiba -> fallback waktu_berangkat + 8 jam = 2026-08-01 16:00:00
        $this->makeJadwal($penugasanA->id_penugasan, '2026-08-01 08:00:00', null);

        $penugasanB = $this->makePenugasan($idArmada, (string) Str::uuid());

        $res = $this->postJson('/api/v1/jadwal', [
            'id_penugasan'    => $penugasanB->id_penugasan,
            'waktu_berangkat' => '2026-08-01 15:00:00',
            'estimasi_tiba'   => '2026-08-01 18:00:00',
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsStringIgnoringCase('bentrok', (string) $res->json('message'));
    }

    public function test_update_jadwal_ke_waktu_bentrok_ditolak_422(): void
    {
        $this->actingAsRole('ADMIN');

        $idArmada = (string) Str::uuid();
        $penugasanA = $this->makePenugasan($idArmada, (string) Str::uuid());
        $jadwalA = $this->makeJadwal($penugasanA->id_penugasan, '2026-08-01 08:00:00', '2026-08-01 12:00:00');

        $penugasanC = $this->makePenugasan($idArmada, (string) Str::uuid());
        $this->makeJadwal($penugasanC->id_penugasan, '2026-08-01 13:00:00', '2026-08-01 17:00:00');

        $res = $this->putJson("/api/v1/jadwal/{$jadwalA->id_jadwal}", [
            'waktu_berangkat' => '2026-08-01 14:00:00',
            'estimasi_tiba'   => '2026-08-01 16:00:00',
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsStringIgnoringCase('bentrok', (string) $res->json('message'));
    }

    public function test_update_jadwal_ke_waktunya_sendiri_diterima_200(): void
    {
        $this->actingAsRole('ADMIN');

        $idArmada = (string) Str::uuid();
        $penugasanA = $this->makePenugasan($idArmada, (string) Str::uuid());
        $jadwalA = $this->makeJadwal($penugasanA->id_penugasan, '2026-08-01 08:00:00', '2026-08-01 12:00:00');

        $res = $this->putJson("/api/v1/jadwal/{$jadwalA->id_jadwal}", [
            'waktu_berangkat' => '2026-08-01 08:00:00',
            'estimasi_tiba'   => '2026-08-01 12:00:00',
            'rute'            => 'Rute diperbarui',
        ]);

        $res->assertStatus(200);
        $res->assertJsonPath('data.rute', 'Rute diperbarui');
    }

    public function test_create_jadwal_bentrok_armada_vendor_sama_ditolak_422(): void
    {
        $this->actingAsRole('ADMIN');

        $vendor = $this->makeVendor();
        $kontrak = $this->makeKontrak($vendor->id_vendor);
        $armadaVendor = $this->makeArmadaVendor($vendor->id_vendor);

        $penugasanA = $this->makePenugasanVendor($kontrak->id_kontrak_vendor, $armadaVendor->id_armada_vendor);
        $this->makeJadwal($penugasanA->id_penugasan, '2026-08-01 08:00:00', '2026-08-01 12:00:00');

        $penugasanB = $this->makePenugasanVendor($kontrak->id_kontrak_vendor, $armadaVendor->id_armada_vendor);

        $res = $this->postJson('/api/v1/jadwal', [
            'id_penugasan'    => $penugasanB->id_penugasan,
            'waktu_berangkat' => '2026-08-01 10:00:00',
            'estimasi_tiba'   => '2026-08-01 14:00:00',
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsStringIgnoringCase('bentrok', (string) $res->json('message'));
    }

    public function test_create_jadwal_armada_vendor_beda_diterima_201(): void
    {
        $this->actingAsRole('ADMIN');

        $vendor = $this->makeVendor();
        $kontrak = $this->makeKontrak($vendor->id_vendor);
        $armadaVendorA = $this->makeArmadaVendor($vendor->id_vendor);
        $armadaVendorB = $this->makeArmadaVendor($vendor->id_vendor);

        $penugasanA = $this->makePenugasanVendor($kontrak->id_kontrak_vendor, $armadaVendorA->id_armada_vendor);
        $this->makeJadwal($penugasanA->id_penugasan, '2026-08-01 08:00:00', '2026-08-01 12:00:00');

        $penugasanB = $this->makePenugasanVendor($kontrak->id_kontrak_vendor, $armadaVendorB->id_armada_vendor);

        $res = $this->postJson('/api/v1/jadwal', [
            'id_penugasan'    => $penugasanB->id_penugasan,
            'waktu_berangkat' => '2026-08-01 10:00:00',
            'estimasi_tiba'   => '2026-08-01 14:00:00',
        ]);

        $res->assertStatus(201);
    }
}
