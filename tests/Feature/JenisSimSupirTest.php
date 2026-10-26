<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\JenisSim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class JenisSimSupirTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalisasi_menerima_berbagai_penulisan(): void
    {
        $this->assertSame('B1 Umum', JenisSim::normalisasi('b1 umum'));
        $this->assertSame('B1 Umum', JenisSim::normalisasi('B I Umum'));
        $this->assertSame('B2 Umum', JenisSim::normalisasi('BII-UMUM'));
        $this->assertSame('A Umum', JenisSim::normalisasi('A UMUM'));
        $this->assertSame('B2', JenisSim::normalisasi(' b2 '));
        $this->assertNull(JenisSim::normalisasi('C'));
        $this->assertNull(JenisSim::normalisasi('B3'));
    }

    public function test_form_supir_hanya_menerima_jenis_sim_resmi(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $id = $this->postJson('/api/supir', ['nama' => 'Angga', 'jenis_sim' => 'b1 umum'])
            ->assertStatus(201)->assertJsonPath('data.jenis_sim', 'B1 Umum')->json('data.id_supir');

        $this->postJson('/api/supir', ['nama' => 'Budi', 'jenis_sim' => 'C'])
            ->assertStatus(422)->assertJsonValidationErrors(['jenis_sim']);

        $this->putJson("/api/supir/{$id}", ['jenis_sim' => 'B2 Umum'])->assertStatus(200)->assertJsonPath('data.jenis_sim', 'B2 Umum');
        $this->putJson("/api/supir/{$id}", ['jenis_sim' => 'D'])->assertStatus(422);
    }

    public function test_supir_lama_dengan_golongan_lama_tetap_bisa_disimpan_tanpa_mengganti(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nama' => 'Supir Lama',
            'no_sim' => 'SIM-LAMA', 'jenis_sim' => 'C', 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);

        $this->putJson("/api/supir/{$id}", ['jenis_sim' => 'C', 'telepon' => '0812'])->assertStatus(200);
        $this->assertSame('C', DB::table('supir')->where('id_supir', $id)->value('jenis_sim'));
        $this->assertSame('0812', DB::table('supir')->where('id_supir', $id)->value('telepon'));
    }
}
