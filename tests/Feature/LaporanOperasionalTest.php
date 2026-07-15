<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use App\Modules\ArmadaVendor\ArmadaVendorModel;
use App\Modules\Karyawan\KaryawanModel;
use App\Modules\Klien\KlienModel;
use App\Modules\JadwalKeberangkatan\JadwalKeberangkatanModel;
use App\Modules\KontrakVendor\KontrakVendorModel;
use App\Modules\LaporanPerjalanan\BiayaLainTripModel;
use App\Modules\LaporanPerjalanan\LaporanPerjalananModel;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use App\Modules\Supir\SupirModel;
use App\Modules\SupirVendor\SupirVendorModel;
use App\Modules\Trip\TripModel;
use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LaporanOperasionalTest extends TestCase
{
    use RefreshDatabase;

    private function makeKlien(string $nama = 'PT Klien Test'): KlienModel
    {
        return KlienModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => $nama,
        ]);
    }

    private function makeArmada(): ArmadaModel
    {
        return ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B ' . random_int(1000, 9999) . ' XYZ',
            'merk'          => 'Hino',
        ]);
    }

    private function makeSupir(string $nama = 'Budi Santoso'): SupirModel
    {
        return SupirModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => $nama,
            'no_sim'        => 'SIM-' . Str::random(8),
        ]);
    }

    private function makeTrip(
        string $idKlien,
        string $idArmada,
        string $idSupir,
        string $waktuBerangkat,
        string $status = 'selesai'
    ): TripModel {
        $proyek = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $idKlien,
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Laporan Operasional',
        ]);

        $penugasan = PenugasanModel::create([
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $idArmada,
            'id_supir'  => $idSupir,
        ]);

        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'waktu_berangkat' => $waktuBerangkat,
        ]);

        return TripModel::create([
            'id_jadwal' => $jadwal->id_jadwal,
            'status'    => $status,
        ]);
    }

    private function makeLaporan(string $idTrip, float $bbm, float $uangJalan, float $jarak = 100): LaporanPerjalananModel
    {
        return LaporanPerjalananModel::create([
            'id_perusahaan'   => self::PERUSAHAAN_ID,
            'id_trip'         => $idTrip,
            'biaya_bbm'       => $bbm,
            'uang_jalan'      => $uangJalan,
            'jarak_tempuh_km' => $jarak,
        ]);
    }

    private function makeVendor(string $nama = 'Vendor Laporan Test'): VendorModel
    {
        return VendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => $nama,
        ]);
    }

    private function makeKontrakVendor(string $idVendor, string $mekanisme = 'unit_driver'): KontrakVendorModel
    {
        return KontrakVendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_vendor'     => $idVendor,
            'mekanisme'     => $mekanisme,
        ]);
    }

    private function makeArmadaVendor(string $idVendor, string $nopol = 'V 1234 VD'): ArmadaVendorModel
    {
        return ArmadaVendorModel::create([
            'id_vendor' => $idVendor,
            'nopol'     => $nopol,
        ]);
    }

    private function makeSupirVendor(string $idVendor, string $nama = 'Supir Vendor Laporan'): SupirVendorModel
    {
        return SupirVendorModel::create([
            'id_vendor' => $idVendor,
            'nama'      => $nama,
        ]);
    }

    /**
     * Trip yang dihasilkan dari penugasan bersumber vendor (unit + supir vendor).
     */
    private function makeTripVendor(
        string $idKlien,
        string $idArmadaVendor,
        string $idSupirVendor,
        string $idKontrakVendor,
        string $waktuBerangkat,
        string $status = 'selesai'
    ): TripModel {
        $proyek = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $idKlien,
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Laporan Operasional Vendor',
        ]);

        $penugasan = PenugasanModel::create([
            'id_proyek'         => $proyek->id_proyek,
            'sumber'            => 'vendor',
            'id_kontrak_vendor' => $idKontrakVendor,
            'id_armada_vendor'  => $idArmadaVendor,
            'id_supir_vendor'   => $idSupirVendor,
        ]);

        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'waktu_berangkat' => $waktuBerangkat,
        ]);

        return TripModel::create([
            'id_jadwal' => $jadwal->id_jadwal,
            'status'    => $status,
        ]);
    }

    private function seedTrips(): array
    {
        $klien1  = $this->makeKlien('PT Klien Satu');
        $klien2  = $this->makeKlien('PT Klien Dua');
        $armada  = $this->makeArmada();
        $supir   = $this->makeSupir();

        $tripA = $this->makeTrip($klien1->id_klien, $armada->id_armada, $supir->id_supir, '2026-01-05 08:00:00');
        $tripB = $this->makeTrip($klien1->id_klien, $armada->id_armada, $supir->id_supir, '2026-01-15 08:00:00');
        $tripC = $this->makeTrip($klien2->id_klien, $armada->id_armada, $supir->id_supir, '2026-02-01 08:00:00');

        $laporanA = $this->makeLaporan($tripA->id_trip, 100000, 50000);
        BiayaLainTripModel::create([
            'id_laporan' => $laporanA->id_laporan,
            'nama_biaya' => 'Tol',
            'nominal'    => 25000,
        ]);
        $this->makeLaporan($tripB->id_trip, 200000, 0);
        $this->makeLaporan($tripC->id_trip, 300000, 0);

        return compact('klien1', 'klien2', 'armada', 'supir', 'tripA', 'tripB', 'tripC');
    }

    public function test_filter_dari_sampai_mempersempit_hasil(): void
    {
        $this->actingAsRole('ADMIN');
        $this->seedTrips();

        $res = $this->getJson('/api/v1/laporan/trip?dari=2026-01-01&sampai=2026-01-31');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(2, $data);

        $ids = array_column($data, 'id_trip');
        $waktu = array_column($data, 'waktu_berangkat');
        foreach ($waktu as $w) {
            $this->assertStringStartsWith('2026-01', $w);
        }
    }

    public function test_filter_id_klien_mempersempit_hasil(): void
    {
        $this->actingAsRole('ADMIN');
        $seed = $this->seedTrips();

        $res = $this->getJson('/api/v1/laporan/trip?id_klien=' . $seed['klien2']->id_klien);

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($seed['tripC']->id_trip, $data[0]['id_trip']);
        $this->assertSame('PT Klien Dua', $data[0]['nama_klien']);
        $this->assertSame($seed['supir']->nama, $data[0]['nama_supir']);
        $this->assertSame($seed['armada']->nopol, $data[0]['nopol']);
    }

    public function test_trip_row_membawa_total_biaya_gabungan(): void
    {
        $this->actingAsRole('ADMIN');
        $seed = $this->seedTrips();

        $res = $this->getJson('/api/v1/laporan/trip?id_klien=' . $seed['klien1']->id_klien);

        $res->assertStatus(200);
        $data = collect($res->json('data'))->keyBy('id_trip');

        // Trip A: bbm 100000 + uang_jalan 50000 + biaya_lain 25000 = 175000
        $this->assertEquals(175000, (float) $data[$seed['tripA']->id_trip]['total_biaya']);
        // Trip B: bbm 200000 + uang_jalan 0 = 200000
        $this->assertEquals(200000, (float) $data[$seed['tripB']->id_trip]['total_biaya']);
    }

    public function test_ringkasan_menghitung_jumlah_trip_dan_total_biaya(): void
    {
        $this->actingAsRole('ADMIN');
        $seed = $this->seedTrips();

        $res = $this->getJson('/api/v1/laporan/trip/ringkasan?dari=2026-01-01&sampai=2026-01-31');

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.jumlah_trip', 2)
            ->assertJsonPath('data.total_biaya', 375000);
    }

    public function test_ringkasan_dengan_filter_klien(): void
    {
        $this->actingAsRole('ADMIN');
        $seed = $this->seedTrips();

        $res = $this->getJson('/api/v1/laporan/trip/ringkasan?id_klien=' . $seed['klien2']->id_klien);

        $res->assertStatus(200)
            ->assertJsonPath('data.jumlah_trip', 1)
            ->assertJsonPath('data.total_biaya', 300000);
    }

    public function test_export_trip_excel_mengembalikan_200_dan_xlsx(): void
    {
        $this->actingAsRole('ADMIN');
        $this->seedTrips();

        $res = $this->get('/api/v1/laporan/trip/export/excel');

        $res->assertStatus(200);
        $contentType = $res->headers->get('content-type');
        $this->assertStringContainsString('spreadsheetml', $contentType);
    }

    public function test_export_trip_pdf_mengembalikan_200_dan_pdf(): void
    {
        $this->actingAsRole('ADMIN');
        $this->seedTrips();

        $res = $this->get('/api/v1/laporan/trip/export/pdf');

        $res->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $res->headers->get('content-type'));
    }

    public function test_export_karyawan_excel_dan_pdf_200(): void
    {
        $this->actingAsRole('ADMIN');
        KaryawanModel::create([
            'id_perusahaan'       => self::PERUSAHAAN_ID,
            'nik'                 => 'NIK-' . Str::random(8),
            'nama_karyawan'       => 'Siti Aminah',
            'status_kepegawaian'  => 'tetap',
            'aktif'               => 1,
        ]);

        $resExcel = $this->get('/api/v1/laporan/karyawan/export/excel');
        $resExcel->assertStatus(200);
        $this->assertStringContainsString('spreadsheetml', $resExcel->headers->get('content-type'));

        $resPdf = $this->get('/api/v1/laporan/karyawan/export/pdf');
        $resPdf->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $resPdf->headers->get('content-type'));
    }

    public function test_export_armada_excel_dan_pdf_200(): void
    {
        $this->actingAsRole('ADMIN');
        $this->makeArmada();

        $resExcel = $this->get('/api/v1/laporan/armada/export/excel');
        $resExcel->assertStatus(200);
        $this->assertStringContainsString('spreadsheetml', $resExcel->headers->get('content-type'));

        $resPdf = $this->get('/api/v1/laporan/armada/export/pdf');
        $resPdf->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $resPdf->headers->get('content-type'));
    }

    public function test_laporan_trip_tidak_bentrok_dengan_laporan_id_route(): void
    {
        $this->actingAsRole('ADMIN');
        $this->seedTrips();

        // Memastikan laporan/trip tidak ditangkap oleh laporan/{id} milik modul LaporanProyek.
        $res = $this->getJson('/api/v1/laporan/trip');

        $res->assertStatus(200);
        $this->assertArrayHasKey('meta', $res->json());
    }

    public function test_trip_dari_penugasan_vendor_muncul_dengan_nopol_dan_supir_vendor_serta_sumber_vendor(): void
    {
        $this->actingAsRole('ADMIN');
        $klien = $this->makeKlien('PT Klien Vendor');
        $vendor = $this->makeVendor();
        $kontrak = $this->makeKontrakVendor($vendor->id_vendor);
        $armadaVendor = $this->makeArmadaVendor($vendor->id_vendor, 'V 9999 VD');
        $supirVendor = $this->makeSupirVendor($vendor->id_vendor, 'Slamet Vendor');

        $tripVendor = $this->makeTripVendor(
            $klien->id_klien,
            $armadaVendor->id_armada_vendor,
            $supirVendor->id_supir_vendor,
            $kontrak->id_kontrak_vendor,
            '2026-03-01 08:00:00'
        );

        $res = $this->getJson('/api/v1/laporan/trip?id_klien=' . $klien->id_klien);

        $res->assertStatus(200);
        $data = collect($res->json('data'))->keyBy('id_trip');

        $this->assertTrue($data->has($tripVendor->id_trip));
        $row = $data[$tripVendor->id_trip];
        $this->assertSame($armadaVendor->nopol, $row['nopol']);
        $this->assertSame($supirVendor->nama, $row['nama_supir']);
        $this->assertSame('vendor', $row['sumber']);
    }

    public function test_filter_sumber_vendor_hanya_mengembalikan_trip_vendor(): void
    {
        $this->actingAsRole('ADMIN');
        $seed = $this->seedTrips(); // trip internal
        $vendor = $this->makeVendor();
        $kontrak = $this->makeKontrakVendor($vendor->id_vendor);
        $armadaVendor = $this->makeArmadaVendor($vendor->id_vendor);
        $supirVendor = $this->makeSupirVendor($vendor->id_vendor);

        $tripVendor = $this->makeTripVendor(
            $seed['klien1']->id_klien,
            $armadaVendor->id_armada_vendor,
            $supirVendor->id_supir_vendor,
            $kontrak->id_kontrak_vendor,
            '2026-03-01 08:00:00'
        );

        $res = $this->getJson('/api/v1/laporan/trip?sumber=vendor');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($tripVendor->id_trip, $data[0]['id_trip']);
        $this->assertSame('vendor', $data[0]['sumber']);
    }

    public function test_filter_sumber_internal_mengecualikan_trip_vendor(): void
    {
        $this->actingAsRole('ADMIN');
        $seed = $this->seedTrips(); // 3 trip internal
        $vendor = $this->makeVendor();
        $kontrak = $this->makeKontrakVendor($vendor->id_vendor);
        $armadaVendor = $this->makeArmadaVendor($vendor->id_vendor);
        $supirVendor = $this->makeSupirVendor($vendor->id_vendor);

        $this->makeTripVendor(
            $seed['klien1']->id_klien,
            $armadaVendor->id_armada_vendor,
            $supirVendor->id_supir_vendor,
            $kontrak->id_kontrak_vendor,
            '2026-03-01 08:00:00'
        );

        $res = $this->getJson('/api/v1/laporan/trip?sumber=internal');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(3, $data);
        foreach ($data as $row) {
            $this->assertSame('internal', $row['sumber']);
        }
    }
}
