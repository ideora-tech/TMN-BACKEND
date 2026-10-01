<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\KontrakVendor\KontrakVendorModel;
use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class KontrakVendorRateTest extends TestCase
{
    use RefreshDatabase;

    private function makeVendor(): VendorModel
    {
        return VendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => 'Vendor Rate',
        ]);
    }

    private function makeKontrak(VendorModel $vendor, array $atribut = []): KontrakVendorModel
    {
        return KontrakVendorModel::create(array_merge([
            'id_perusahaan' => $vendor->id_perusahaan,
            'id_vendor'     => $vendor->id_vendor,
            'mekanisme'     => 'unit_only',
        ], $atribut));
    }

    public function test_buat_kontrak_per_trip_wajib_rate_lebih_dari_nol(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $dasar = ['id_vendor' => $vendor->id_vendor, 'mekanisme' => 'unit_only', 'satuan' => 'per trip'];

        $this->postJson('/api/kontrak-vendor', $dasar)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Rate wajib diisi untuk kontrak dengan satuan Per Trip');
        $this->postJson('/api/kontrak-vendor', $dasar + ['rate' => null])->assertStatus(422);
        $this->postJson('/api/kontrak-vendor', $dasar + ['rate' => 0])->assertStatus(422);

        $this->postJson('/api/kontrak-vendor', $dasar + ['rate' => 150000])
            ->assertStatus(201)
            ->assertJsonPath('data.satuan', 'per trip');
    }

    public function test_buat_kontrak_satuan_lain_atau_tanpa_satuan_rate_boleh_kosong(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        foreach (['per ton', 'per hari', 'per bulan', 'lumpsum'] as $satuan) {
            $this->postJson('/api/kontrak-vendor', [
                'id_vendor' => $vendor->id_vendor, 'mekanisme' => 'unit_only', 'satuan' => $satuan, 'rate' => null,
            ])->assertStatus(201)->assertJsonPath('data.rate', null);
        }

        $this->postJson('/api/kontrak-vendor', ['id_vendor' => $vendor->id_vendor, 'mekanisme' => 'unit_only'])
            ->assertStatus(201);
    }

    public function test_rate_tetap_tidak_boleh_melebihi_nilai_kontrak_bila_diisi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $this->postJson('/api/kontrak-vendor', [
            'id_vendor' => $vendor->id_vendor, 'mekanisme' => 'unit_only', 'satuan' => 'per hari',
            'rate' => 2000000, 'nilai_kontrak' => 1000000,
        ])->assertStatus(422)->assertJsonPath('message', 'Rate tidak boleh lebih besar dari nilai kontrak');
    }

    public function test_ubah_satuan_ke_per_trip_atau_kosongkan_rate_pada_per_trip_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $perHari = $this->makeKontrak($vendor, ['satuan' => 'per hari']);
        $perTrip = $this->makeKontrak($vendor, ['satuan' => 'per trip', 'rate' => 100000]);

        $this->putJson("/api/kontrak-vendor/{$perHari->id_kontrak_vendor}", ['satuan' => 'per trip'])
            ->assertStatus(422);
        $this->putJson("/api/kontrak-vendor/{$perHari->id_kontrak_vendor}", ['satuan' => 'per trip', 'rate' => 90000])
            ->assertOk();

        $this->putJson("/api/kontrak-vendor/{$perTrip->id_kontrak_vendor}", ['rate' => null])->assertStatus(422);
        $this->putJson("/api/kontrak-vendor/{$perTrip->id_kontrak_vendor}", ['satuan' => 'per trip', 'rate' => null])->assertStatus(422);
        $this->putJson("/api/kontrak-vendor/{$perTrip->id_kontrak_vendor}", ['satuan' => 'per bulan', 'rate' => null])
            ->assertOk()
            ->assertJsonPath('data.rate', null);
    }

    public function test_ubah_field_lain_pada_kontrak_per_trip_lama_tanpa_rate_tidak_diblokir(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $lama = $this->makeKontrak($vendor, ['satuan' => 'per trip']);

        $this->putJson("/api/kontrak-vendor/{$lama->id_kontrak_vendor}", ['nomor_kontrak' => 'KV-LAMA-1'])
            ->assertOk()
            ->assertJsonPath('data.nomor_kontrak', 'KV-LAMA-1');

        $this->putJson("/api/kontrak-vendor/{$lama->id_kontrak_vendor}", ['satuan' => 'per trip', 'rate' => null])
            ->assertStatus(422);
    }
}
