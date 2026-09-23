<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\ArmadaVendor\ArmadaVendorModel;
use App\Modules\KontrakVendor\KontrakVendorModel;
use App\Modules\SupirVendor\SupirVendorModel;
use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class VendorRingkasanListTest extends TestCase
{
    use RefreshDatabase;

    private function makeVendor(string $nama = 'Vendor Ringkas'): VendorModel
    {
        return VendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => $nama,
        ]);
    }

    private function makeKontrak(string $idVendor, string $status, float $nilai, ?string $selesai = null): KontrakVendorModel
    {
        return KontrakVendorModel::create([
            'id_perusahaan'   => self::PERUSAHAAN_ID,
            'id_vendor'       => $idVendor,
            'mekanisme'       => 'unit_only',
            'nomor_kontrak'   => 'KV-' . Str::random(6),
            'status'          => $status,
            'nilai_kontrak'   => $nilai,
            'tanggal_selesai' => $selesai,
        ]);
    }

    public function test_daftar_vendor_menyertakan_ringkasan_unit_driver_dan_kontrak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        ArmadaVendorModel::create(['id_vendor' => $vendor->id_vendor, 'nopol' => 'B 1 AA', 'merk' => 'Hino']);
        ArmadaVendorModel::create(['id_vendor' => $vendor->id_vendor, 'nopol' => 'B 2 AA', 'merk' => 'Hino']);
        $dihapus = ArmadaVendorModel::create(['id_vendor' => $vendor->id_vendor, 'nopol' => 'B 3 AA', 'merk' => 'Hino']);
        DB::table('armada_vendor')->where('id_armada_vendor', $dihapus->id_armada_vendor)->update(['dihapus_pada' => now()]);

        SupirVendorModel::create(['id_vendor' => $vendor->id_vendor, 'nama' => 'Tono']);

        $this->makeKontrak($vendor->id_vendor, 'aktif', 100000000, now()->addDays(10)->toDateString());
        $this->makeKontrak($vendor->id_vendor, 'aktif', 50000000, now()->addDays(40)->toDateString());
        $this->makeKontrak($vendor->id_vendor, 'draft', 999999999, now()->addDays(1)->toDateString());
        $this->makeKontrak($vendor->id_vendor, 'selesai', 777777777, now()->subDays(1)->toDateString());

        $res = $this->getJson('/api/vendor?limit=50');

        $res->assertStatus(200);
        $baris = collect($res->json('data'))->firstWhere('id_vendor', $vendor->id_vendor);

        $this->assertSame(2, $baris['jumlah_unit']);
        $this->assertSame(1, $baris['jumlah_driver']);
        $this->assertSame(2, $baris['jumlah_kontrak_aktif']);
        $this->assertSame(150000000.0, (float) $baris['nilai_kontrak_aktif']);
        $this->assertSame(now()->addDays(10)->toDateString(), $baris['kontrak_berakhir_terdekat']);
    }

    public function test_vendor_tanpa_relasi_mengembalikan_nol_dan_null(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor('Vendor Kosong');

        $res = $this->getJson('/api/vendor?limit=50');

        $baris = collect($res->json('data'))->firstWhere('id_vendor', $vendor->id_vendor);
        $this->assertSame(0, $baris['jumlah_unit']);
        $this->assertSame(0, $baris['jumlah_driver']);
        $this->assertSame(0, $baris['jumlah_kontrak_aktif']);
        $this->assertSame(0.0, (float) $baris['nilai_kontrak_aktif']);
        $this->assertArrayHasKey('kontrak_berakhir_terdekat', $baris);
        $this->assertNull($baris['kontrak_berakhir_terdekat']);
    }

    public function test_ringkasan_tidak_bocor_antar_vendor(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $a = $this->makeVendor('Vendor A');
        $b = $this->makeVendor('Vendor B');
        ArmadaVendorModel::create(['id_vendor' => $a->id_vendor, 'nopol' => 'B 9 AA', 'merk' => 'Hino']);
        $this->makeKontrak($a->id_vendor, 'aktif', 1000);

        $res = $this->getJson('/api/vendor?limit=50');

        $barisB = collect($res->json('data'))->firstWhere('id_vendor', $b->id_vendor);
        $this->assertSame(0, $barisB['jumlah_unit']);
        $this->assertSame(0, $barisB['jumlah_kontrak_aktif']);
    }
}
