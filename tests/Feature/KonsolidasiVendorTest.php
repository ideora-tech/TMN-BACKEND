<?php

declare(strict_types=1);

namespace Tests\Feature;

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

class KonsolidasiVendorTest extends TestCase
{
    use RefreshDatabase;

    private VendorModel $vendor;
    private KontrakVendorModel $kontrak;
    private ProyekModel $proyek;

    private function siapkanVendor(?float $rate = 500000, ?string $satuan = 'per trip'): void
    {
        $this->vendor = VendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VEN-' . Str::random(8),
            'nama_vendor'   => 'PT Vendor Konsolidasi',
        ]);

        $this->kontrak = KontrakVendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_vendor'     => $this->vendor->id_vendor,
            'mekanisme'     => 'full',
            'rate'          => $rate,
            'satuan'        => $satuan,
            'nilai_kontrak' => 30000000,
        ]);

        $this->proyek = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => (string) Str::uuid(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Konsolidasi',
        ]);
    }

    private function buatTripVendor(string $status = 'selesai', bool $denganLaporan = true, float $jarak = 100): TripModel
    {
        $idArmadaVendor = ArmadaVendorModel::create([
            'id_vendor' => $this->vendor->id_vendor,
            'nopol'     => 'B ' . random_int(1000, 9999) . ' KV',
        ])->id_armada_vendor;

        $idSupirVendor = SupirVendorModel::create([
            'id_vendor' => $this->vendor->id_vendor,
            'nama'      => 'Supir Vendor ' . Str::random(4),
        ])->id_supir_vendor;

        $penugasan = PenugasanModel::create([
            'id_proyek'         => $this->proyek->id_proyek,
            'sumber'            => 'vendor',
            'id_kontrak_vendor' => $this->kontrak->id_kontrak_vendor,
            'id_armada_vendor'  => $idArmadaVendor,
            'id_supir_vendor'   => $idSupirVendor,
        ]);

        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'waktu_berangkat' => now()->subDay(),
        ]);

        $trip = TripModel::create([
            'id_jadwal' => $jadwal->id_jadwal,
            'status'    => $status,
        ]);

        if ($denganLaporan) {
            DB::table('laporan_perjalanan')->insert([
                'id_laporan' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
                'id_trip' => $trip->id_trip, 'jarak_tempuh_km' => $jarak, 'dibuat_pada' => now(),
            ]);
        }

        return $trip;
    }

    public function test_rekap_menghitung_rit_jarak_dan_nilai_seharusnya(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanVendor(500000, 'per trip');

        $this->buatTripVendor('selesai', true, 120);
        $this->buatTripVendor('selesai', true, 80);
        $this->buatTripVendor('berjalan', true);
        $this->buatTripVendor('selesai', false);

        $res = $this->getJson("/api/konsolidasi-vendor?id_vendor={$this->vendor->id_vendor}");
        $res->assertStatus(200)
            ->assertJsonPath('data.vendor.nama_vendor', 'PT Vendor Konsolidasi')
            ->assertJsonPath('data.ringkasan.total_rit', 2)
            ->assertJsonPath('data.ringkasan.total_jarak_km', 200)
            ->assertJsonPath('data.ringkasan.kontrak.0.jumlah_rit', 2)
            ->assertJsonPath('data.ringkasan.kontrak.0.nilai_seharusnya', 1000000);

        $this->assertCount(2, $res->json('data.trips'));
    }

    public function test_satuan_bukan_per_trip_nilai_seharusnya_null(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanVendor(30000000, 'per bulan');

        $this->buatTripVendor();

        $res = $this->getJson("/api/konsolidasi-vendor?id_vendor={$this->vendor->id_vendor}");
        $res->assertStatus(200)
            ->assertJsonPath('data.ringkasan.kontrak.0.nilai_seharusnya', null)
            ->assertJsonPath('data.ringkasan.kontrak.0.satuan', 'per bulan');
    }

    public function test_vendor_perusahaan_lain_404_dan_export_200(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanVendor();
        $this->buatTripVendor();

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $vendorLain = VendorModel::create([
            'id_perusahaan' => $idPerusahaanLain,
            'kode_vendor'   => 'VEN-' . Str::random(8),
            'nama_vendor'   => 'Vendor Lain',
        ]);

        $this->getJson("/api/konsolidasi-vendor?id_vendor={$vendorLain->id_vendor}")->assertStatus(404);
        $this->get("/api/konsolidasi-vendor/export/excel?id_vendor={$this->vendor->id_vendor}")->assertStatus(200);
    }
}
