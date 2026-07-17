<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use App\Modules\JadwalKeberangkatan\JadwalKeberangkatanModel;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use App\Modules\Trip\TripModel;
use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardStatsTest extends TestCase
{
    use RefreshDatabase;

    private function makeArmada(string $status = 'tersedia'): ArmadaModel
    {
        return ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B ' . random_int(1000, 9999) . ' ' . Str::random(3),
            'status'        => $status,
        ]);
    }

    private function makeVendor(): VendorModel
    {
        return VendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => 'Vendor Dashboard Test',
        ]);
    }

    private function makeKlien(): object
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'Klien Dashboard Test',
            'dibuat_pada'   => now(),
        ]);
        return DB::table('klien')->where('id_klien', $id)->first();
    }

    private function makeTripBerjalan(string $waktuCheckin, ?string $waktuCheckout = null): TripModel
    {
        $klien  = $this->makeKlien();
        $armada = $this->makeArmada();

        $proyek = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $klien->id_klien,
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Dashboard Test',
        ]);

        $penugasan = PenugasanModel::create([
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $armada->id_armada,
        ]);

        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'waktu_berangkat' => now(),
        ]);

        return TripModel::create([
            'id_jadwal'      => $jadwal->id_jadwal,
            'status'         => 'berjalan',
            'waktu_checkin'  => $waktuCheckin,
            'waktu_checkout' => $waktuCheckout,
        ]);
    }

    public function test_armada_dihitung_tersedia_dan_beroperasi_terpisah(): void
    {
        $this->actingAsRole('ADMIN');
        $this->makeArmada('tersedia');
        $this->makeArmada('tersedia');
        $this->makeArmada('digunakan');

        $res = $this->getJson('/api/v1/dashboard/stats');

        $res->assertStatus(200)
            ->assertJsonPath('data.armadaTersedia', 2)
            ->assertJsonPath('data.armadaBeroperasi', 1);

        $this->assertArrayNotHasKey('armadaAktif', $res->json('data'));
    }

    public function test_alerts_dokumen_expiring_menggabungkan_armada_dan_vendor(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada();
        $vendor = $this->makeVendor();

        DB::table('dokumen_armada')->insert([
            'id_dokumen_armada' => (string) Str::uuid(),
            'id_armada'         => $armada->id_armada,
            'jenis_dokumen'     => 'STNK',
            'berlaku_sampai'    => now()->addDays(10)->toDateString(),
            'dibuat_pada'       => now(),
        ]);

        DB::table('dokumen_vendor')->insert([
            'id_dokumen_vendor' => (string) Str::uuid(),
            'id_vendor'         => $vendor->id_vendor,
            'jenis_dokumen'     => 'SIUP',
            'berlaku_sampai'    => now()->addDays(5)->toDateString(),
            'dibuat_pada'       => now(),
        ]);

        // Di luar rentang 30 hari -> tidak ikut dihitung
        DB::table('dokumen_armada')->insert([
            'id_dokumen_armada' => (string) Str::uuid(),
            'id_armada'         => $armada->id_armada,
            'jenis_dokumen'     => 'KIR',
            'berlaku_sampai'    => now()->addDays(60)->toDateString(),
            'dibuat_pada'       => now(),
        ]);

        $res = $this->getJson('/api/v1/dashboard/stats');

        $res->assertStatus(200)
            ->assertJsonPath('data.alerts.dokumenExpiring.total', 2);

        $items = $res->json('data.alerts.dokumenExpiring.items');
        $this->assertCount(2, $items);

        // Urut ascending oleh berlaku_sampai -> dokumen vendor (5 hari) tampil lebih dulu
        $this->assertSame('vendor', $items[0]['tipe']);
        $this->assertSame($vendor->nama_vendor, $items[0]['pemilik']);
        $this->assertSame('SIUP', $items[0]['jenis_dokumen']);
        $this->assertArrayHasKey('berlaku_sampai', $items[0]);

        $this->assertSame('armada', $items[1]['tipe']);
        $this->assertSame($armada->nopol, $items[1]['pemilik']);
        $this->assertSame('STNK', $items[1]['jenis_dokumen']);
    }

    public function test_alerts_trip_terlambat_dihitung_dari_checkin_lebih_24_jam(): void
    {
        $this->actingAsRole('ADMIN');
        $trip = $this->makeTripBerjalan(now()->subHours(30)->toDateTimeString());

        $res = $this->getJson('/api/v1/dashboard/stats');

        $res->assertStatus(200)
            ->assertJsonPath('data.alerts.tripTerlambat.total', 1);

        $items = $res->json('data.alerts.tripTerlambat.items');
        $this->assertCount(1, $items);
        $this->assertSame($trip->id_trip, $items[0]['id_trip']);
        $this->assertSame('Proyek Dashboard Test', $items[0]['nama_proyek']);
        $this->assertGreaterThanOrEqual(29, $items[0]['jam_berjalan']);
        $this->assertLessThanOrEqual(31, $items[0]['jam_berjalan']);
    }

    public function test_alerts_trip_terlambat_tidak_termasuk_checkin_baru_atau_sudah_checkout(): void
    {
        $this->actingAsRole('ADMIN');
        // Checkin baru 2 jam lalu -> belum melewati batas 24 jam
        $this->makeTripBerjalan(now()->subHours(2)->toDateTimeString());
        // Checkin 30 jam lalu tapi sudah checkout -> tidak lagi dianggap terlambat
        $this->makeTripBerjalan(now()->subHours(30)->toDateTimeString(), now()->subHour()->toDateTimeString());

        $res = $this->getJson('/api/v1/dashboard/stats');

        $res->assertStatus(200)
            ->assertJsonPath('data.alerts.tripTerlambat.total', 0);
    }
}
