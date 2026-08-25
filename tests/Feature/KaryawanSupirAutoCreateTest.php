<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KaryawanSupirAutoCreateTest extends TestCase
{
    use RefreshDatabase;

    private function makeJabatan(bool $isSupir, ?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('jabatan')->insert([
            'id_jabatan' => $id, 'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'kode_jabatan' => 'J-' . Str::random(5), 'nama_jabatan' => $isSupir ? 'Supir' : 'Staf',
            'is_supir' => $isSupir ? 1 : 0, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function buatKaryawan(array $override = []): array
    {
        $payload = array_merge([
            'nik' => 'K-' . Str::random(8), 'nama_karyawan' => 'Budi Santoso', 'telepon' => '0812000111',
        ], $override);

        $res = $this->postJson('/api/karyawan', $payload);
        $res->assertStatus(201);
        return $res->json('data');
    }

    public function test_create_karyawan_jabatan_supir_membuat_supir_tertaut(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idJabatan = $this->makeJabatan(true);

        $karyawan = $this->buatKaryawan(['id_jabatan' => $idJabatan]);

        $supir = DB::table('supir')->where('id_karyawan', $karyawan['id_karyawan'])->first();
        $this->assertNotNull($supir);
        $this->assertSame('Budi Santoso', $supir->nama);
        $this->assertSame('0812000111', $supir->telepon);
        $this->assertSame('aktif', $supir->status);
        $this->assertNull($supir->no_sim);
        $this->assertSame(self::PERUSAHAAN_ID, $supir->id_perusahaan);
    }

    public function test_create_karyawan_jabatan_biasa_tidak_membuat_supir(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idJabatan = $this->makeJabatan(false);

        $karyawan = $this->buatKaryawan(['id_jabatan' => $idJabatan]);

        $this->assertSame(0, DB::table('supir')->where('id_karyawan', $karyawan['id_karyawan'])->count());
    }

    public function test_mutasi_ke_jabatan_supir_membuat_supir(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $biasa = $this->makeJabatan(false);
        $supirJab = $this->makeJabatan(true);
        $karyawan = $this->buatKaryawan(['id_jabatan' => $biasa]);

        $res = $this->putJson('/api/karyawan/' . $karyawan['id_karyawan'], ['id_jabatan' => $supirJab]);
        $res->assertStatus(200);

        $this->assertSame(1, DB::table('supir')->where('id_karyawan', $karyawan['id_karyawan'])->count());
    }

    public function test_mutasi_keluar_dari_jabatan_supir_membiarkan_supir(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $supirJab = $this->makeJabatan(true);
        $biasa = $this->makeJabatan(false);
        $karyawan = $this->buatKaryawan(['id_jabatan' => $supirJab]);

        $res = $this->putJson('/api/karyawan/' . $karyawan['id_karyawan'], ['id_jabatan' => $biasa]);
        $res->assertStatus(200);

        $supir = DB::table('supir')->where('id_karyawan', $karyawan['id_karyawan'])->first();
        $this->assertNotNull($supir);
        $this->assertSame('aktif', $supir->status);
    }

    public function test_karyawan_sudah_tertaut_tidak_dibuatkan_duplikat(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $supirJab = $this->makeJabatan(true);
        $biasa = $this->makeJabatan(false);
        $karyawan = $this->buatKaryawan(['id_jabatan' => $supirJab]);
        $this->assertSame(1, DB::table('supir')->where('id_karyawan', $karyawan['id_karyawan'])->count());

        $this->putJson('/api/karyawan/' . $karyawan['id_karyawan'], ['id_jabatan' => $biasa])->assertStatus(200);
        $this->putJson('/api/karyawan/' . $karyawan['id_karyawan'], ['id_jabatan' => $supirJab])->assertStatus(200);

        $this->assertSame(1, DB::table('supir')->where('id_karyawan', $karyawan['id_karyawan'])->count());
    }

    public function test_jabatan_perusahaan_lain_tidak_memicu_auto_create(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $lain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $lain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $jabatanLain = $this->makeJabatan(true, $lain);

        $karyawan = $this->buatKaryawan(['id_jabatan' => $jabatanLain]);

        $this->assertSame(0, DB::table('supir')->where('id_karyawan', $karyawan['id_karyawan'])->count());
    }
}
