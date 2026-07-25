<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use App\Modules\ArmadaVendor\ArmadaVendorModel;
use App\Modules\JadwalKeberangkatan\JadwalKeberangkatanModel;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use App\Modules\SupirVendor\SupirVendorModel;
use App\Modules\Trip\TripModel;
use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TripTest extends TestCase
{
    use RefreshDatabase;

    private function makeKlien(): string
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'Klien Trip Test',
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makeSupir(string $nama = 'Budi Santoso'): string
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => $nama,
            'no_sim'        => 'SIM-' . Str::random(8),
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makeTrip(
        ?string $idArmada = null,
        ?string $idSupir = null,
        ?string $idArmadaVendor = null,
        ?string $idSupirVendor = null,
        string $rute = 'Jakarta - Bandung',
        string $status = 'belum_mulai'
    ): TripModel {
        $proyek = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $this->makeKlien(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Trip Test',
        ]);

        $penugasan = PenugasanModel::create([
            'id_proyek'         => $proyek->id_proyek,
            'id_armada'         => $idArmada,
            'id_supir'          => $idSupir,
            'id_armada_vendor'  => $idArmadaVendor,
            'id_supir_vendor'   => $idSupirVendor,
        ]);

        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'waktu_berangkat' => now()->addDay(),
            'rute'            => $rute,
        ]);

        return TripModel::create([
            'id_jadwal' => $jadwal->id_jadwal,
            'status'    => $status,
        ]);
    }

    public function test_list_trip_menampilkan_rute_supir_dan_armada(): void
    {
        $this->actingAsRole('ADMIN');

        $idArmada = ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B 1234 XYZ',
            'merk'          => 'Hino',
        ])->id_armada;
        $idSupir = $this->makeSupir('Budi Santoso');

        $this->makeTrip($idArmada, $idSupir, null, null, 'Jakarta - Bandung');

        $res = $this->getJson('/api/v1/trip');

        $res->assertStatus(200);
        $item = $res->json('data.0');
        $this->assertSame('Jakarta - Bandung', $item['rute']);
        $this->assertSame('Budi Santoso', $item['supir_nama']);
        $this->assertSame('B 1234 XYZ', $item['armada_nopol']);
        $this->assertNotNull($item['waktu_berangkat']);
    }

    public function test_detail_trip_menampilkan_info_jadwal(): void
    {
        $this->actingAsRole('ADMIN');

        $idArmada = ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B 5678 ABC',
            'merk'          => 'Fuso',
        ])->id_armada;
        $idSupir = $this->makeSupir('Andi Wijaya');

        $trip = $this->makeTrip($idArmada, $idSupir, null, null, 'Surabaya - Malang');

        $res = $this->getJson("/api/v1/trip/{$trip->id_trip}");

        $res->assertStatus(200)
            ->assertJsonPath('data.rute', 'Surabaya - Malang')
            ->assertJsonPath('data.supir_nama', 'Andi Wijaya')
            ->assertJsonPath('data.armada_nopol', 'B 5678 ABC');
    }

    public function test_trip_dengan_armada_dan_supir_vendor_menampilkan_nama_vendor(): void
    {
        $this->actingAsRole('ADMIN');

        $idVendor = VendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VEN-' . Str::random(8),
            'nama_vendor'   => 'Vendor Trip Test',
        ])->id_vendor;

        $idArmadaVendor = ArmadaVendorModel::create([
            'id_vendor' => $idVendor,
            'nopol'     => 'D 9999 VEN',
        ])->id_armada_vendor;
        $idSupirVendor = SupirVendorModel::create([
            'id_vendor' => $idVendor,
            'nama'      => 'Supir Vendor Test',
        ])->id_supir_vendor;

        $trip = $this->makeTrip(null, null, $idArmadaVendor, $idSupirVendor, 'Bekasi - Cikampek');

        $res = $this->getJson("/api/v1/trip/{$trip->id_trip}");

        $res->assertStatus(200)
            ->assertJsonPath('data.supir_nama', 'Supir Vendor Test')
            ->assertJsonPath('data.armada_nopol', 'D 9999 VEN');
    }

    public function test_checkin_dan_checkout_trip_tidak_error_setelah_attach_jadwal_detail(): void
    {
        $this->actingAsRole('ADMIN');

        $idArmada = ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B 1111 QQQ',
            'merk'          => 'Hino',
        ])->id_armada;
        $idSupir = $this->makeSupir('Rudi Hartono');

        $trip = $this->makeTrip($idArmada, $idSupir, null, null, 'Jakarta - Bogor');

        $resCheckin = $this->postJson("/api/v1/trip/{$trip->id_trip}/checkin");
        $resCheckin->assertStatus(200)->assertJsonPath('data.status', 'berjalan');

        $resCheckout = $this->postJson("/api/v1/trip/{$trip->id_trip}/checkout");
        $resCheckout->assertStatus(200)->assertJsonPath('data.status', 'selesai');

        $this->assertDatabaseHas('trip', [
            'id_trip' => $trip->id_trip,
            'status'  => 'selesai',
        ]);
    }

    private function makeTripUntukProyek(string $idProyek, string $rute = 'Jakarta - Bandung', string $status = 'belum_mulai'): TripModel
    {
        $penugasan = PenugasanModel::create([
            'id_proyek' => $idProyek,
        ]);

        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'waktu_berangkat' => now()->addDay(),
            'rute'            => $rute,
        ]);

        return TripModel::create([
            'id_jadwal' => $jadwal->id_jadwal,
            'status'    => $status,
        ]);
    }

    public function test_ringkasan_proyek_mengelompokkan_trip_per_proyek(): void
    {
        $this->actingAsRole('ADMIN');

        $proyekA = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $this->makeKlien(),
            'kode_proyek'   => 'PRJ-A-' . Str::random(6),
            'nama_proyek'   => 'Proyek Ringkasan A',
        ]);
        $proyekB = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $this->makeKlien(),
            'kode_proyek'   => 'PRJ-B-' . Str::random(6),
            'nama_proyek'   => 'Proyek Ringkasan B',
        ]);

        $this->makeTripUntukProyek($proyekA->id_proyek, 'Rute A1');
        $this->makeTripUntukProyek($proyekA->id_proyek, 'Rute A2');
        $this->makeTripUntukProyek($proyekB->id_proyek, 'Rute B1');

        $res = $this->getJson('/api/v1/trip/ringkasan-proyek');
        $res->assertStatus(200);

        $data = collect($res->json('data'));
        $rowA = $data->firstWhere('id_proyek', $proyekA->id_proyek);
        $rowB = $data->firstWhere('id_proyek', $proyekB->id_proyek);

        $this->assertNotNull($rowA);
        $this->assertNotNull($rowB);
        $this->assertSame(2, $rowA['jumlah_trip']);
        $this->assertSame(1, $rowB['jumlah_trip']);
        $this->assertSame('Proyek Ringkasan A', $rowA['nama_proyek']);
    }

    public function test_ringkasan_proyek_bisa_difilter_status_dan_search(): void
    {
        $this->actingAsRole('ADMIN');

        $proyek = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $this->makeKlien(),
            'kode_proyek'   => 'PRJ-FILTER-' . Str::random(6),
            'nama_proyek'   => 'Proyek Filter Unik',
        ]);
        $this->makeTripUntukProyek($proyek->id_proyek, 'Rute 1', 'berjalan');
        $this->makeTripUntukProyek($proyek->id_proyek, 'Rute 2', 'selesai');

        $resStatus = $this->getJson('/api/v1/trip/ringkasan-proyek?status=berjalan');
        $resStatus->assertStatus(200);
        $rowStatus = collect($resStatus->json('data'))->firstWhere('id_proyek', $proyek->id_proyek);
        $this->assertSame(1, $rowStatus['jumlah_trip']);

        $resSearch = $this->getJson('/api/v1/trip/ringkasan-proyek?search=Filter Unik');
        $resSearch->assertStatus(200);
        $this->assertCount(1, $resSearch->json('data'));
        $this->assertSame($proyek->id_proyek, $resSearch->json('data.0.id_proyek'));
    }

    public function test_list_trip_bisa_difilter_id_proyek(): void
    {
        $this->actingAsRole('ADMIN');

        $proyekA = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $this->makeKlien(),
            'kode_proyek'   => 'PRJ-FA-' . Str::random(6),
            'nama_proyek'   => 'Proyek Filter A',
        ]);
        $proyekB = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $this->makeKlien(),
            'kode_proyek'   => 'PRJ-FB-' . Str::random(6),
            'nama_proyek'   => 'Proyek Filter B',
        ]);
        $this->makeTripUntukProyek($proyekA->id_proyek, 'Rute A');
        $this->makeTripUntukProyek($proyekB->id_proyek, 'Rute B');

        $res = $this->getJson("/api/v1/trip?id_proyek={$proyekA->id_proyek}");
        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Rute A', $res->json('data.0.rute'));
        $this->assertSame($proyekA->id_proyek, $res->json('data.0.id_proyek'));
    }
}
