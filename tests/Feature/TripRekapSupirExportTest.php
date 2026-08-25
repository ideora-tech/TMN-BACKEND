<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use App\Modules\ArmadaVendor\ArmadaVendorModel;
use App\Modules\JadwalKeberangkatan\JadwalKeberangkatanModel;
use App\Modules\KontrakVendor\KontrakVendorModel;
use App\Modules\LaporanOperasional\Contracts\LaporanOperasionalRepositoryInterface;
use App\Modules\LaporanPerjalanan\BiayaLainTripModel;
use App\Modules\LaporanPerjalanan\LaporanPerjalananModel;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use App\Modules\SupirVendor\SupirVendorModel;
use App\Modules\Trip\Exports\RekapTripSupirExport;
use App\Modules\Trip\TripModel;
use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TripRekapSupirExportTest extends TestCase
{
    use RefreshDatabase;

    private function makeKlien(): object
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'PT Klien Rekap',
            'dibuat_pada'   => now(),
        ]);
        return DB::table('klien')->where('id_klien', $id)->first();
    }

    private function makeArmada(): ArmadaModel
    {
        return ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B ' . random_int(1000, 9999) . ' RKP',
            'merk'          => 'Hino',
        ]);
    }

    private function makeSupir(string $nama): object
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => $nama,
            'no_sim'        => 'SIM-' . Str::random(8),
            'dibuat_pada'   => now(),
        ]);
        return DB::table('supir')->where('id_supir', $id)->first();
    }

    private function buatProyek(): ProyekModel
    {
        return ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $this->makeKlien()->id_klien,
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Rekap Supir',
        ]);
    }

    private function buatTripDariPenugasan(PenugasanModel $penugasan, string $waktuBerangkat, string $status): TripModel
    {
        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'waktu_berangkat' => $waktuBerangkat,
        ]);

        return TripModel::create([
            'id_jadwal' => $jadwal->id_jadwal,
            'status'    => $status,
        ]);
    }

    private function makeTrip(string $idArmada, string $idSupir, string $waktuBerangkat, string $status = 'selesai'): TripModel
    {
        $penugasan = PenugasanModel::create([
            'id_proyek' => $this->buatProyek()->id_proyek,
            'id_armada' => $idArmada,
            'id_supir'  => $idSupir,
        ]);

        return $this->buatTripDariPenugasan($penugasan, $waktuBerangkat, $status);
    }

    private function makeTripTanpaSupir(string $idArmada, string $waktuBerangkat): TripModel
    {
        $penugasan = PenugasanModel::create([
            'id_proyek' => $this->buatProyek()->id_proyek,
            'id_armada' => $idArmada,
        ]);

        return $this->buatTripDariPenugasan($penugasan, $waktuBerangkat, 'selesai');
    }

    private function makeTripVendor(string $namaSupirVendor, string $waktuBerangkat): TripModel
    {
        $vendor = VendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => 'Vendor Rekap',
        ]);
        $kontrak = KontrakVendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_vendor'     => $vendor->id_vendor,
            'mekanisme'     => 'unit_driver',
        ]);
        $armadaVendor = ArmadaVendorModel::create([
            'id_vendor' => $vendor->id_vendor,
            'nopol'     => 'V ' . random_int(1000, 9999) . ' RKP',
        ]);
        $supirVendor = SupirVendorModel::create([
            'id_vendor' => $vendor->id_vendor,
            'nama'      => $namaSupirVendor,
        ]);

        $penugasan = PenugasanModel::create([
            'id_proyek'         => $this->buatProyek()->id_proyek,
            'sumber'            => 'vendor',
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'id_armada_vendor'  => $armadaVendor->id_armada_vendor,
            'id_supir_vendor'   => $supirVendor->id_supir_vendor,
        ]);

        return $this->buatTripDariPenugasan($penugasan, $waktuBerangkat, 'selesai');
    }

    private function makeLaporan(string $idTrip, float $bbm, float $uangJalan, float $jarak): LaporanPerjalananModel
    {
        return LaporanPerjalananModel::create([
            'id_perusahaan'   => self::PERUSAHAAN_ID,
            'id_trip'         => $idTrip,
            'biaya_bbm'       => $bbm,
            'uang_jalan'      => $uangJalan,
            'jarak_tempuh_km' => $jarak,
        ]);
    }

    private function rekap(array $filter = []): Collection
    {
        return app(LaporanOperasionalRepositoryInterface::class)
            ->rekapTripPerSupir(self::PERUSAHAAN_ID, $filter);
    }

    private function seedRekap(): void
    {
        $armada = $this->makeArmada();
        $asep   = $this->makeSupir('Asep Rekap');

        $tripA1 = $this->makeTrip($armada->id_armada, $asep->id_supir, '2026-03-05 08:00:00');
        $laporanA1 = $this->makeLaporan($tripA1->id_trip, 100000, 50000, 100);
        BiayaLainTripModel::create([
            'id_laporan' => $laporanA1->id_laporan,
            'nama_biaya' => 'Tol',
            'nominal'    => 25000,
        ]);

        $tripA2 = $this->makeTrip($armada->id_armada, $asep->id_supir, '2026-03-20 08:00:00');
        $this->makeLaporan($tripA2->id_trip, 200000, 0, 150);

        $this->makeTrip($armada->id_armada, $asep->id_supir, '2026-03-25 08:00:00', 'dibatalkan');

        $this->makeTripVendor('Vendor Rekap Satu', '2026-03-10 09:00:00');
        $this->makeTripTanpaSupir($armada->id_armada, '2026-03-12 07:00:00');
    }

    public function test_rekap_menghitung_agregat_per_supir(): void
    {
        $this->seedRekap();

        $rows = $this->rekap()->keyBy('nama_supir');

        $this->assertCount(2, $rows);

        $asep = $rows['Asep Rekap'];
        $this->assertSame('internal', $asep->sumber);
        $this->assertSame(3, (int) $asep->jumlah_trip);
        $this->assertSame(2, (int) $asep->selesai);
        $this->assertSame(1, (int) $asep->dibatalkan);
        $this->assertEquals(250, (float) $asep->total_jarak_km);
        $this->assertEquals(375000, (float) $asep->total_biaya);
        $this->assertSame('2026-03-25 08:00:00', $asep->trip_terakhir);

        $vendor = $rows['Vendor Rekap Satu'];
        $this->assertSame('vendor', $vendor->sumber);
        $this->assertSame(1, (int) $vendor->jumlah_trip);
        $this->assertEquals(0, (float) $vendor->total_biaya);
    }

    public function test_filter_periode_sumber_dan_status_bekerja(): void
    {
        $this->seedRekap();

        $periode = $this->rekap(['dari' => '2026-03-01', 'sampai' => '2026-03-15'])->keyBy('nama_supir');
        $this->assertSame(1, (int) $periode['Asep Rekap']->jumlah_trip);
        $this->assertSame(1, (int) $periode['Vendor Rekap Satu']->jumlah_trip);

        $vendorSaja = $this->rekap(['sumber' => 'vendor']);
        $this->assertCount(1, $vendorSaja);
        $this->assertSame('Vendor Rekap Satu', $vendorSaja->first()->nama_supir);

        $selesaiSaja = $this->rekap(['status' => 'selesai'])->keyBy('nama_supir');
        $this->assertSame(2, (int) $selesaiSaja['Asep Rekap']->jumlah_trip);
        $this->assertSame(0, (int) $selesaiSaja['Asep Rekap']->dibatalkan);
    }

    public function test_export_excel_terunduh(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->seedRekap();

        $res = $this->get('/api/trip/rekap-supir/export/excel?dari=2026-03-01&sampai=2026-03-31');

        $res->assertStatus(200);
        $this->assertStringContainsString('spreadsheetml', (string) $res->headers->get('content-type'));
        $this->assertStringContainsString('rekap-trip-supir-', (string) $res->headers->get('content-disposition'));
    }

    public function test_export_pdf_terunduh(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->seedRekap();

        $res = $this->get('/api/trip/rekap-supir/export/pdf');

        $res->assertStatus(200);
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
        $this->assertStringContainsString('rekap-trip-supir-', (string) $res->headers->get('content-disposition'));
    }

    public function test_label_periode_menangani_rentang_satu_sisi(): void
    {
        $this->assertSame('Periode: 01/03/2026 — 31/03/2026', RekapTripSupirExport::labelPeriode('2026-03-01', '2026-03-31'));
        $this->assertSame('Periode: sejak 01/03/2026', RekapTripSupirExport::labelPeriode('2026-03-01', null));
        $this->assertSame('Periode: s.d. 31/03/2026', RekapTripSupirExport::labelPeriode(null, '2026-03-31'));
        $this->assertSame('Semua Periode', RekapTripSupirExport::labelPeriode(null, null));
    }
}
