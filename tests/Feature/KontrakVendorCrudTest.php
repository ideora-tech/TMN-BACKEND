<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\ArmadaVendor\ArmadaVendorModel;
use App\Modules\KontrakVendor\KontrakVendorModel;
use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KontrakVendorCrudTest extends TestCase
{
    use RefreshDatabase;

    private function makeVendor(?string $idPerusahaan = null): VendorModel
    {
        return VendorModel::create([
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => 'Vendor Test',
        ]);
    }

    private function makeVendorPerusahaanLain(): VendorModel
    {
        $idPerusahaanLain = (string) Str::uuid();

        DB::table('perusahaan')->insert([
            'id_perusahaan' => $idPerusahaanLain,
            'nama'          => 'Perusahaan Lain Test',
            'dibuat_pada'   => now(),
        ]);

        return $this->makeVendor($idPerusahaanLain);
    }

    private function makeKontrak(VendorModel $vendor): KontrakVendorModel
    {
        return KontrakVendorModel::create([
            'id_perusahaan' => $vendor->id_perusahaan,
            'id_vendor'     => $vendor->id_vendor,
            'mekanisme'     => 'unit_only',
        ]);
    }

    public function test_update_kontrak_vendor_menyimpan_field_baru(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor  = $this->makeVendor();
        $kontrak = $this->makeKontrak($vendor);

        $res = $this->putJson("/api/kontrak-vendor/{$kontrak->id_kontrak_vendor}", [
            'nomor_kontrak' => 'KV-2026-001',
            'jenis_layanan' => 'Angkutan kontainer',
            'rate'          => 150000,
            'satuan'        => 'per trip',
            'pajak_persen'  => 11,
            'termin_pembayaran_hari' => 30,
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.nomor_kontrak', 'KV-2026-001')
            ->assertJsonPath('data.satuan', 'per trip')
            ->assertJsonPath('data.vendor.nama_vendor', 'Vendor Test');

        $this->assertDatabaseHas('kontrak_vendor', [
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'nomor_kontrak'     => 'KV-2026-001',
            'jenis_layanan'     => 'Angkutan kontainer',
            'satuan'            => 'per trip',
            'termin_pembayaran_hari' => 30,
        ]);
    }

    public function test_soft_delete_kontrak_vendor_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor  = $this->makeVendor();
        $kontrak = $this->makeKontrak($vendor);

        $this->deleteJson("/api/kontrak-vendor/{$kontrak->id_kontrak_vendor}")
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $row = DB::table('kontrak_vendor')
            ->where('id_kontrak_vendor', $kontrak->id_kontrak_vendor)
            ->first();
        $this->assertNotNull($row->dihapus_pada);
    }

    public function test_hapus_kontrak_tidak_membuat_unit_meminjam_kontrak_lain_milik_vendor(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $kontrakLain = $this->makeKontrak($vendor);
        $kontrakDihapus = $this->makeKontrak($vendor);
        $unit = ArmadaVendorModel::create([
            'id_vendor'         => $vendor->id_vendor,
            'id_kontrak_vendor' => $kontrakDihapus->id_kontrak_vendor,
            'nopol'             => 'B 9999 TS',
        ]);

        $this->deleteJson("/api/kontrak-vendor/{$kontrakDihapus->id_kontrak_vendor}")->assertStatus(200);

        $this->assertDatabaseHas('armada_vendor', [
            'id_armada_vendor'  => $unit->id_armada_vendor,
            'id_kontrak_vendor' => null,
        ]);

        $res = $this->getJson('/api/penugasan/board?dari=2026-09-01&sampai=2026-09-02')->assertStatus(200);
        $baris = collect($res->json('data.units'))->firstWhere('id_armada_vendor', $unit->id_armada_vendor);
        $this->assertNotNull($baris);
        $this->assertNull($baris['id_kontrak_vendor']);
        $this->assertNull($baris['mekanisme']);
        $this->assertNotSame($kontrakLain->id_kontrak_vendor, $baris['id_kontrak_vendor']);
    }

    public function test_kontrak_baru_mengadopsi_unit_lama_yang_kontraknya_sudah_dihapus(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $kontrakLama = $this->makeKontrak($vendor);
        $unitLama = ArmadaVendorModel::create([
            'id_vendor'         => $vendor->id_vendor,
            'id_kontrak_vendor' => $kontrakLama->id_kontrak_vendor,
            'nopol'             => 'B 1213 VND',
        ]);

        $this->deleteJson("/api/kontrak-vendor/{$kontrakLama->id_kontrak_vendor}")->assertStatus(200);

        $res = $this->postJson('/api/kontrak-vendor', [
            'id_vendor'     => $vendor->id_vendor,
            'mekanisme'     => 'unit_only',
            'nomor_kontrak' => 'KV-ADOPSI-1',
            'unit'          => [[
                'nopol'             => 'B 1213 VND',
                'merk'              => 'Hino Baru',
                'masa_berlaku_stnk' => '2027-03-01',
                'masa_berlaku_kir'  => '2026-12-15',
            ]],
        ]);
        $res->assertStatus(201);
        $idKontrakBaru = $res->json('data.id_kontrak_vendor');

        $this->assertDatabaseHas('armada_vendor', [
            'id_armada_vendor'  => $unitLama->id_armada_vendor,
            'id_kontrak_vendor' => $idKontrakBaru,
            'merk'              => 'Hino Baru',
        ]);
        $this->assertSame(1, DB::table('armada_vendor')
            ->where('nopol', 'B 1213 VND')->whereNull('dihapus_pada')->count());
    }

    public function test_kontrak_baru_tetap_menolak_nopol_yang_masih_terikat_kontrak_lain(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $kontrakAktif = $this->makeKontrak($vendor);
        ArmadaVendorModel::create([
            'id_vendor'         => $vendor->id_vendor,
            'id_kontrak_vendor' => $kontrakAktif->id_kontrak_vendor,
            'nopol'             => 'B 7777 VND',
        ]);

        $this->postJson('/api/kontrak-vendor', [
            'id_vendor'     => $vendor->id_vendor,
            'mekanisme'     => 'unit_only',
            'nomor_kontrak' => 'KV-ADOPSI-2',
            'unit'          => [[
                'nopol'             => 'B 7777 VND',
                'masa_berlaku_stnk' => '2027-03-01',
                'masa_berlaku_kir'  => '2026-12-15',
            ]],
        ])->assertStatus(422);
    }

    public function test_show_kontrak_vendor_perusahaan_lain_mengembalikan_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $kontrakLain = $this->makeKontrak($this->makeVendorPerusahaanLain());

        $this->getJson("/api/kontrak-vendor/{$kontrakLain->id_kontrak_vendor}")
            ->assertStatus(404);
    }

    public function test_menolak_update_dan_hapus_kontrak_vendor_perusahaan_lain(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $kontrakLain = $this->makeKontrak($this->makeVendorPerusahaanLain());

        $this->putJson("/api/kontrak-vendor/{$kontrakLain->id_kontrak_vendor}", [
            'rate' => 1,
        ])->assertStatus(404);

        $this->deleteJson("/api/kontrak-vendor/{$kontrakLain->id_kontrak_vendor}")
            ->assertStatus(404);

        $row = DB::table('kontrak_vendor')
            ->where('id_kontrak_vendor', $kontrakLain->id_kontrak_vendor)
            ->first();
        $this->assertNull($row->dihapus_pada);
        $this->assertNull($row->rate);
    }

    public function test_menolak_membuat_kontrak_untuk_vendor_perusahaan_lain(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendorLain = $this->makeVendorPerusahaanLain();

        $this->postJson('/api/kontrak-vendor', [
            'id_vendor' => $vendorLain->id_vendor,
            'mekanisme' => 'unit_only',
        ])->assertStatus(404);

        $this->assertDatabaseMissing('kontrak_vendor', [
            'id_vendor' => $vendorLain->id_vendor,
        ]);
    }

    public function test_satuan_di_luar_daftar_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $this->postJson('/api/kontrak-vendor', [
            'id_vendor' => $vendor->id_vendor,
            'mekanisme' => 'unit_only',
            'satuan'    => 'per kilo',
        ])->assertStatus(422);

        $this->postJson('/api/kontrak-vendor', [
            'id_vendor' => $vendor->id_vendor,
            'mekanisme' => 'unit_only',
            'satuan'    => 'per trip',
        ])->assertStatus(201);
    }

    public function test_membuat_kontrak_dengan_nilai_kontrak_null_tersimpan_nol(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $res = $this->postJson('/api/kontrak-vendor', [
            'id_vendor'     => $vendor->id_vendor,
            'nomor_kontrak' => 'KV-NULL-001',
            'mekanisme'     => 'unit_driver',
            'jenis_layanan' => 'Angkutan kontainer',
            'nilai_kontrak' => null,
            'rate'          => null,
            'satuan'        => 'per trip',
            'pajak_persen'  => null,
            'termin_pembayaran_hari' => null,
            'tanggal_mulai' => null,
            'tanggal_selesai' => null,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.nilai_kontrak', 0);

        $this->assertDatabaseHas('kontrak_vendor', [
            'nomor_kontrak' => 'KV-NULL-001',
            'nilai_kontrak' => 0,
        ]);
    }

    public function test_update_kontrak_dengan_nilai_kontrak_null_tersimpan_nol(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor  = $this->makeVendor();
        $kontrak = $this->makeKontrak($vendor);

        $this->putJson("/api/kontrak-vendor/{$kontrak->id_kontrak_vendor}", [
            'nilai_kontrak' => null,
        ])->assertStatus(200)
            ->assertJsonPath('data.nilai_kontrak', 0);
    }

    public function test_show_dan_hapus_vendor_perusahaan_lain_mengembalikan_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendorLain = $this->makeVendorPerusahaanLain();

        $this->getJson("/api/vendor/{$vendorLain->id_vendor}")
            ->assertStatus(404);

        $this->deleteJson("/api/vendor/{$vendorLain->id_vendor}")
            ->assertStatus(404);

        $row = DB::table('vendor')->where('id_vendor', $vendorLain->id_vendor)->first();
        $this->assertNull($row->dihapus_pada);
    }
}
