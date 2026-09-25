<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PeranUbahTest extends TestCase
{
    use RefreshDatabase;

    private function buatPeran(string $kode = 'SALES_UJI', string $nama = 'Sales Uji'): string
    {
        $this->ensurePerusahaan();
        $id = (string) Str::uuid();
        DB::table('peran')->insert([
            'id_peran' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => $kode,
            'nama_peran' => $nama, 'is_platform' => 0, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    public function test_superadmin_bisa_mengubah_nama_dan_status_peran(): void
    {
        $id = $this->buatPeran();
        $this->actingAsRole('SUPERADMIN');

        $this->putJson("/api/peran/{$id}", ['nama_peran' => 'Tim Sales Lapangan', 'aktif' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.nama_peran', 'Tim Sales Lapangan');

        $this->assertDatabaseHas('peran', ['id_peran' => $id, 'nama_peran' => 'Tim Sales Lapangan', 'aktif' => 0, 'kode_peran' => 'SALES_UJI']);
    }

    public function test_kode_peran_tidak_bisa_diganti(): void
    {
        $id = $this->buatPeran();
        $this->actingAsRole('SUPERADMIN');

        $this->putJson("/api/peran/{$id}", ['kode_peran' => 'SALES_BARU', 'nama_peran' => 'Coba'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Kode peran tidak bisa diubah karena dipakai akun pengguna dan izin akses');

        $this->assertDatabaseHas('peran', ['id_peran' => $id, 'kode_peran' => 'SALES_UJI', 'nama_peran' => 'Sales Uji']);
    }

    public function test_mengirim_kode_peran_yang_sama_tetap_boleh(): void
    {
        $id = $this->buatPeran();
        $this->actingAsRole('SUPERADMIN');

        $this->putJson("/api/peran/{$id}", ['kode_peran' => 'sales_uji', 'nama_peran' => 'Sales Kantor'])
            ->assertStatus(200);

        $this->assertDatabaseHas('peran', ['id_peran' => $id, 'kode_peran' => 'SALES_UJI', 'nama_peran' => 'Sales Kantor']);
    }
}
