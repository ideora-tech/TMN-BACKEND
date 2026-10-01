<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\PermintaanVendor\PermintaanVendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PermintaanVendorJumlahAktifTest extends TestCase
{
    use RefreshDatabase;

    private function buatPermintaan(string $status, string $idPerusahaan = self::PERUSAHAAN_ID): void
    {
        PermintaanVendorModel::create([
            'id_perusahaan'    => $idPerusahaan,
            'nomor_permintaan' => 'PMV-' . Str::random(6),
            'jumlah_unit'      => 1,
            'mekanisme'        => 'unit_only',
            'status'           => $status,
        ]);
    }

    public function test_menghitung_hanya_status_disetujui_dan_diproses(): void
    {
        $this->actingAsRole('SUPERADMIN');

        foreach (['draft', 'menunggu_approval', 'disetujui', 'disetujui', 'diproses', 'dikontrakkan', 'selesai', 'dibatalkan', 'ditolak'] as $status) {
            $this->buatPermintaan($status);
        }

        $this->getJson('/api/permintaan-vendor/jumlah-aktif')
            ->assertOk()
            ->assertJsonPath('data.jumlah', 3)
            ->assertJsonPath('data.disetujui', 2)
            ->assertJsonPath('data.diproses', 1);
    }

    public function test_tidak_menghitung_permintaan_yang_sudah_dihapus(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->buatPermintaan('disetujui');
        PermintaanVendorModel::query()->update(['dihapus_pada' => now()]);

        $this->getJson('/api/permintaan-vendor/jumlah-aktif')
            ->assertOk()
            ->assertJsonPath('data.jumlah', 0);
    }

    public function test_dispatcher_bisa_melihat_jumlah_dan_menu_permintaan_vendor(): void
    {
        $this->actingAsRole('DISPATCHER');
        $this->buatPermintaan('diproses');

        $this->getJson('/api/permintaan-vendor/jumlah-aktif')
            ->assertOk()
            ->assertJsonPath('data.jumlah', 1);

        $this->postJson('/api/permintaan-vendor', [])->assertStatus(403);
    }

    public function test_peran_tanpa_izin_ditolak(): void
    {
        $this->actingAsRole('SUPIR');

        $this->getJson('/api/permintaan-vendor/jumlah-aktif')->assertStatus(403);
    }
}
