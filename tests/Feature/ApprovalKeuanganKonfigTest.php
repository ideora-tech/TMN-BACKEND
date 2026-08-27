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
}
