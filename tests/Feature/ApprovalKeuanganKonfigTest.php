<?php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApprovalKeuanganKonfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_set_dan_get_batas_approval(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $this->getJson('/api/arus-kas/pengaturan-approval')
            ->assertStatus(200)
            ->assertJsonPath('data.batas', 0);

        $this->putJson('/api/arus-kas/pengaturan-approval', ['batas' => 5000000])
            ->assertStatus(200)
            ->assertJsonPath('data.batas', 5000000);

        $this->getJson('/api/arus-kas/pengaturan-approval')
            ->assertStatus(200)
            ->assertJsonPath('data.batas', 5000000);
    }

    public function test_batas_approval_validasi_numeric_min_nol(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $this->putJson('/api/arus-kas/pengaturan-approval', ['batas' => -100])->assertStatus(422);
        $this->putJson('/api/arus-kas/pengaturan-approval', ['batas' => 'bukan_angka'])->assertStatus(422);
    }

    public function test_role_admin_bisa_akses_pengaturan_approval(): void
    {
        $this->actingAsRole('ADMIN');

        $this->getJson('/api/arus-kas/pengaturan-approval')->assertStatus(200);
        $this->putJson('/api/arus-kas/pengaturan-approval', ['batas' => 1000])->assertStatus(200);
    }

    public function test_default_batas_realisasi_mandiri_adalah_500rb(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $this->getJson('/api/arus-kas/pengaturan-approval')
            ->assertStatus(200)
            ->assertJsonPath('data.batas_realisasi_mandiri', 500000);
    }

    public function test_set_dan_get_batas_realisasi_mandiri(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $this->putJson('/api/arus-kas/pengaturan-approval', ['batas' => 0, 'batas_realisasi_mandiri' => 1000000])
            ->assertStatus(200);

        $this->getJson('/api/arus-kas/pengaturan-approval')
            ->assertStatus(200)
            ->assertJsonPath('data.batas_realisasi_mandiri', 1000000);
    }

    public function test_batas_realisasi_mandiri_tidak_wajib_dikirim_dan_tidak_mengubah_nilai_lama(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $this->putJson('/api/arus-kas/pengaturan-approval', ['batas' => 0, 'batas_realisasi_mandiri' => 750000])->assertStatus(200);
        $this->putJson('/api/arus-kas/pengaturan-approval', ['batas' => 1000])->assertStatus(200);

        $this->getJson('/api/arus-kas/pengaturan-approval')
            ->assertStatus(200)
            ->assertJsonPath('data.batas_realisasi_mandiri', 750000);
    }

    public function test_batas_realisasi_mandiri_validasi_numeric_min_nol(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $this->putJson('/api/arus-kas/pengaturan-approval', ['batas' => 0, 'batas_realisasi_mandiri' => -50])->assertStatus(422);
    }
}
