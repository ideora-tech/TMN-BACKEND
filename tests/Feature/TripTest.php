<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use App\Modules\ArmadaVendor\ArmadaVendorModel;
use App\Modules\JadwalKeberangkatan\JadwalKeberangkatanModel;
use App\Modules\KontrakVendor\KontrakVendorModel;
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
        $this->actingAsRole('SUPERADMIN');

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
        $this->actingAsRole('SUPERADMIN');

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
        $this->actingAsRole('SUPERADMIN');

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
        $this->actingAsRole('SUPERADMIN');

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

    public function test_lifecycle_trip_menulis_riwayat_status(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $idArmada = ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B 2222 RS',
            'merk'          => 'Hino',
        ])->id_armada;
        $idSupir = $this->makeSupir('Joko Riwayat');

        $trip = $this->makeTrip($idArmada, $idSupir, null, null, 'Jakarta - Cirebon');

        $this->postJson("/api/v1/trip/{$trip->id_trip}/checkin")->assertStatus(200);
        $this->assertDatabaseHas('status_trip', ['id_trip' => $trip->id_trip, 'status' => 'berjalan']);

        $this->postJson("/api/v1/trip/{$trip->id_trip}/checkout")->assertStatus(200);
        $this->assertDatabaseHas('status_trip', ['id_trip' => $trip->id_trip, 'status' => 'selesai']);

        $res = $this->getJson("/api/v1/trip/{$trip->id_trip}/status");
        $res->assertStatus(200);
        $this->assertCount(2, $res->json('data'));
    }

    public function test_batalkan_trip_menulis_riwayat_status(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $idArmada = ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B 3333 RS',
            'merk'          => 'Hino',
        ])->id_armada;
        $idSupir = $this->makeSupir('Bambang Batal');

        $trip = $this->makeTrip($idArmada, $idSupir, null, null, 'Jakarta - Serang');

        $this->postJson("/api/v1/trip/{$trip->id_trip}/batalkan")->assertStatus(200);
        $this->assertDatabaseHas('status_trip', ['id_trip' => $trip->id_trip, 'status' => 'dibatalkan']);
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
        $this->actingAsRole('SUPERADMIN');

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
        $this->actingAsRole('SUPERADMIN');

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

    public function test_filter_status_multi_nilai_dipisah_koma(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $proyek = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $this->makeKlien(),
            'kode_proyek'   => 'PRJ-MULTI-' . Str::random(6),
            'nama_proyek'   => 'Proyek Multi Status',
        ]);
        $this->makeTripUntukProyek($proyek->id_proyek, 'Rute 1', 'belum_mulai');
        $this->makeTripUntukProyek($proyek->id_proyek, 'Rute 2', 'berjalan');
        $this->makeTripUntukProyek($proyek->id_proyek, 'Rute 3', 'selesai');
        $this->makeTripUntukProyek($proyek->id_proyek, 'Rute 4', 'dibatalkan');

        $resList = $this->getJson("/api/v1/trip?id_proyek={$proyek->id_proyek}&status=belum_mulai,berjalan");
        $resList->assertStatus(200);
        $this->assertCount(2, $resList->json('data'));

        $resRingkasan = $this->getJson('/api/v1/trip/ringkasan-proyek?status=selesai,dibatalkan');
        $resRingkasan->assertStatus(200);
        $row = collect($resRingkasan->json('data'))->firstWhere('id_proyek', $proyek->id_proyek);
        $this->assertSame(2, $row['jumlah_trip']);
    }

    public function test_list_trip_bisa_difilter_periode(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $proyek = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $this->makeKlien(),
            'kode_proyek'   => 'PRJ-PERIODE-' . Str::random(6),
            'nama_proyek'   => 'Proyek Periode',
        ]);
        $tripLama = $this->makeTripUntukProyek($proyek->id_proyek, 'Rute Lama', 'selesai');
        $tripBaru = $this->makeTripUntukProyek($proyek->id_proyek, 'Rute Baru', 'selesai');
        DB::table('jadwal_keberangkatan')->where('id_jadwal', $tripLama->id_jadwal)->update(['waktu_berangkat' => now()->subDays(60)]);
        DB::table('jadwal_keberangkatan')->where('id_jadwal', $tripBaru->id_jadwal)->update(['waktu_berangkat' => now()->subDay()]);

        $dari = now()->subDays(30)->toDateString();
        $res = $this->getJson("/api/v1/trip?id_proyek={$proyek->id_proyek}&tanggal_dari={$dari}");

        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Rute Baru', $res->json('data.0.rute'));
    }

    private function makeTripVendor(string $namaVendor = 'PT Vendor Ekspedisi', string $rute = 'Rute Vendor'): TripModel
    {
        $idVendor = VendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VEN-' . Str::random(8),
            'nama_vendor'   => $namaVendor,
        ])->id_vendor;

        $kontrak = KontrakVendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_vendor'     => $idVendor,
            'mekanisme'     => 'full',
        ]);

        $idArmadaVendor = ArmadaVendorModel::create([
            'id_vendor' => $idVendor,
            'nopol'     => 'B ' . random_int(1000, 9999) . ' VD',
        ])->id_armada_vendor;

        $idSupirVendor = SupirVendorModel::create([
            'id_vendor' => $idVendor,
            'nama'      => 'Supir Vendor ' . Str::random(4),
        ])->id_supir_vendor;

        $proyek = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $this->makeKlien(),
            'kode_proyek'   => 'PRJ-VEN-' . Str::random(6),
            'nama_proyek'   => 'Proyek Trip Vendor',
        ]);

        $penugasan = PenugasanModel::create([
            'id_proyek'         => $proyek->id_proyek,
            'sumber'            => 'vendor',
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
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
            'status'    => 'belum_mulai',
        ]);
    }

    public function test_list_dan_detail_trip_vendor_menyertakan_sumber_dan_vendor_nama(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $trip = $this->makeTripVendor('PT Vendor Ekspedisi');

        $res = $this->getJson('/api/v1/trip');
        $res->assertStatus(200);
        $item = collect($res->json('data'))->firstWhere('id_trip', $trip->id_trip);
        $this->assertNotNull($item);
        $this->assertSame('vendor', $item['sumber']);
        $this->assertSame('PT Vendor Ekspedisi', $item['vendor_nama']);

        $resDetail = $this->getJson("/api/v1/trip/{$trip->id_trip}");
        $resDetail->assertStatus(200)
            ->assertJsonPath('data.sumber', 'vendor')
            ->assertJsonPath('data.vendor_nama', 'PT Vendor Ekspedisi');
    }

    public function test_filter_sumber_memisahkan_trip_vendor_dan_internal(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $tripVendor = $this->makeTripVendor('PT Vendor Filter');
        $idSupir = $this->makeSupir('Supir Internal Filter');
        $tripInternal = $this->makeTrip(null, $idSupir, null, null, 'Rute Internal Filter');

        $resVendor = $this->getJson('/api/v1/trip?sumber=vendor');
        $resVendor->assertStatus(200);
        $idsVendor = collect($resVendor->json('data'))->pluck('id_trip');
        $this->assertTrue($idsVendor->contains($tripVendor->id_trip));
        $this->assertFalse($idsVendor->contains($tripInternal->id_trip));

        $resInternal = $this->getJson('/api/v1/trip?sumber=internal');
        $resInternal->assertStatus(200);
        $idsInternal = collect($resInternal->json('data'))->pluck('id_trip');
        $this->assertTrue($idsInternal->contains($tripInternal->id_trip));
        $this->assertFalse($idsInternal->contains($tripVendor->id_trip));
    }

    public function test_trip_internal_bersumber_internal_dengan_vendor_nama_null(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $idSupir = $this->makeSupir('Supir Internal Sumber');
        $trip = $this->makeTrip(null, $idSupir, null, null, 'Rute Internal Sumber');

        $res = $this->getJson('/api/v1/trip');
        $res->assertStatus(200);
        $item = collect($res->json('data'))->firstWhere('id_trip', $trip->id_trip);
        $this->assertNotNull($item);
        $this->assertSame('internal', $item['sumber']);
        $this->assertNull($item['vendor_nama']);

        $resDetail = $this->getJson("/api/v1/trip/{$trip->id_trip}");
        $resDetail->assertStatus(200)
            ->assertJsonPath('data.sumber', 'internal')
            ->assertJsonPath('data.vendor_nama', null);
    }

    public function test_list_trip_bisa_difilter_id_proyek(): void
    {
        $this->actingAsRole('SUPERADMIN');

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

    public function test_export_riwayat_trip_excel_mengembalikan_200(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->get('/api/v1/trip/riwayat/export/excel?dari=2026-08-01&sampai=2026-08-10');

        $res->assertStatus(200);
        $this->assertStringContainsString('spreadsheet', (string) $res->headers->get('content-type'));
    }

    public function test_export_riwayat_trip_pdf_mengembalikan_200(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->get('/api/v1/trip/riwayat/export/pdf');

        $res->assertStatus(200);
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
    }
}
