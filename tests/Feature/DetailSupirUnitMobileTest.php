<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DetailSupirUnitMobileTest extends TestCase
{
    use RefreshDatabase;

    private function perusahaanLain(): string
    {
        $id = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $id, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        return $id;
    }

    private function armada(string $nopol, string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada' => $id, 'id_perusahaan' => $idPerusahaan, 'nopol' => $nopol, 'merk' => 'Hino',
            'model' => 'Dutro', 'status' => 'tersedia', 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function supir(string $nama, ?string $idArmadaDefault = null, ?string $idKaryawan = null, string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $id, 'id_perusahaan' => $idPerusahaan, 'id_karyawan' => $idKaryawan,
            'id_armada_default' => $idArmadaDefault, 'nama' => $nama, 'no_sim' => 'SIM-' . Str::random(4),
            'jenis_sim' => 'B2', 'telepon' => '0812', 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    public function test_detail_dan_list_supir_menyertakan_unit_tetap(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $unit = $this->armada('B 1111 AA');
        $idSupir = $this->supir('Budi', $unit);
        $this->supir('Tanpa Unit');

        $this->getJson("/api/supir/{$idSupir}")->assertStatus(200)
            ->assertJsonPath('data.armada_default.nopol', 'B 1111 AA')
            ->assertJsonPath('data.armada_default.model', 'Dutro');

        $list = $this->getJson('/api/supir?limit=50')->assertStatus(200)->json('data');
        $budi = array_values(array_filter($list, fn ($s) => $s['nama'] === 'Budi'))[0];
        $tanpa = array_values(array_filter($list, fn ($s) => $s['nama'] === 'Tanpa Unit'))[0];
        $this->assertSame('B 1111 AA', $budi['armada_default']['nopol']);
        $this->assertNull($tanpa['armada_default']);
    }

    public function test_dokumen_supir_by_id_dikelompokkan_dan_scoped_perusahaan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $idKaryawan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nik' => 'NIK-' . Str::random(5), 'nama_karyawan' => 'Budi', 'dibuat_pada' => now(),
        ]);
        foreach ([['2025-01-01', '2024-01-01 08:00:00'], ['2030-01-01', '2025-01-01 08:00:00']] as [$berlaku, $dibuat]) {
            DB::table('dokumen_karyawan')->insert([
                'id_dokumen_karyawan' => (string) Str::uuid(), 'id_karyawan' => $idKaryawan, 'jenis_dokumen' => 'SIM',
                'nomor' => 'S-' . $berlaku, 'berlaku_sampai' => $berlaku, 'dibuat_pada' => $dibuat,
            ]);
        }
        $idSupir = $this->supir('Budi', null, $idKaryawan);

        $this->getJson("/api/supir/{$idSupir}/dokumen")->assertStatus(200)
            ->assertJsonPath('data.terhubung_karyawan', true)
            ->assertJsonPath('data.dokumen.0.terbaru.berlaku_sampai', '2030-01-01')
            ->assertJsonCount(1, 'data.dokumen.0.riwayat');

        $supirLain = $this->supir('Lain', null, null, $this->perusahaanLain());
        $this->getJson("/api/supir/{$supirLain}/dokumen")->assertStatus(404);
    }

    public function test_detail_unit_menyertakan_supir_tetap(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $unit = $this->armada('B 2222 BB');
        $this->supir('Andi', $unit);
        $this->supir('Budi', $unit);

        $this->getJson("/api/armada/{$unit}")->assertStatus(200)
            ->assertJsonCount(2, 'data.supir_tetap')
            ->assertJsonPath('data.supir_tetap.0.nama', 'Andi');
    }

    public function test_dokumen_unit_dengan_riwayat_dan_scoped_perusahaan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $unit = $this->armada('B 3333 CC');
        $idLama = (string) Str::uuid();
        DB::table('dokumen_armada')->insert([
            ['id_dokumen_armada' => $idLama, 'id_armada' => $unit, 'jenis_dokumen' => 'STNK', 'berlaku_sampai' => '2025-01-01',
                'aktif' => 0, 'id_dokumen_sebelumnya' => null, 'dibuat_pada' => now()],
            ['id_dokumen_armada' => (string) Str::uuid(), 'id_armada' => $unit, 'jenis_dokumen' => 'STNK', 'berlaku_sampai' => '2026-01-01',
                'aktif' => 1, 'id_dokumen_sebelumnya' => $idLama, 'dibuat_pada' => now()],
        ]);

        $this->getJson("/api/armada/{$unit}/dokumen?dengan_riwayat=1")->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.berlaku_sampai', '2026-01-01')
            ->assertJsonPath('data.0.riwayat.0.id_dokumen_armada', $idLama);

        $unitLain = $this->armada('D 9999 ZZ', $this->perusahaanLain());
        $this->getJson("/api/armada/{$unitLain}/dokumen?dengan_riwayat=1")->assertStatus(404);
    }

    public function test_riwayat_perawatan_unit_perusahaan_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $unitSendiri = $this->armada('B 4444 DD');
        $unitLain = $this->armada('D 8888 YY', $this->perusahaanLain());

        $this->getJson("/api/armada/{$unitSendiri}/perawatan")->assertStatus(200);
        $this->getJson("/api/armada/{$unitLain}/perawatan")->assertStatus(404);
    }
}
