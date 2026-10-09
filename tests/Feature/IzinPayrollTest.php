<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class IzinPayrollTest extends TestCase
{
    use RefreshDatabase;

    private const AKSI = ['lihat', 'tambah', 'ubah', 'hapus'];

    private function idMenu(string $path): string
    {
        $id = DB::table('menu')->where('path', $path)->value('id_menu');
        if ($id === null) {
            $id = (string) Str::uuid();
            DB::table('menu')->insert([
                'id_menu' => $id, 'nama_menu' => trim($path, '/'), 'path' => $path,
                'aktif' => 1, 'dibuat_pada' => now(),
            ]);
        }
        return (string) $id;
    }

    private function setIzin(string $path, string $aksi, int $diizinkan): void
    {
        $idMenu = $this->idMenu($path);
        DB::table('izin_peran')->where('id_menu', $idMenu)->where('kode_peran', 'KEUANGAN')->where('aksi', $aksi)->delete();
        DB::table('izin_peran')->insert([
            'id_izin' => (string) Str::uuid(), 'id_perusahaan' => null, 'kode_peran' => 'KEUANGAN',
            'id_menu' => $idMenu, 'aksi' => $aksi, 'diizinkan' => $diizinkan, 'dibuat_pada' => now(),
        ]);
    }

    private function setSemuaIzin(string $path, int $diizinkan): void
    {
        foreach (self::AKSI as $aksi) {
            $this->setIzin($path, $aksi, $diizinkan);
        }
    }

    public function test_migrasi_memberi_izin_payroll_bawaan_untuk_keuangan_manager_admin(): void
    {
        $idMenu = DB::table('menu')->where('path', '/payroll')->value('id_menu');
        $this->assertNotNull($idMenu);

        foreach (['KEUANGAN', 'MANAGER', 'ADMIN'] as $kodePeran) {
            $aksi = DB::table('izin_peran')
                ->where('id_menu', $idMenu)->where('kode_peran', $kodePeran)
                ->whereNull('id_perusahaan')->where('diizinkan', 1)
                ->pluck('aksi')->sort()->values()->all();

            $this->assertSame(['hapus', 'lihat', 'tambah', 'ubah'], $aksi, "Izin payroll bawaan {$kodePeran} tidak lengkap");
        }
    }

    public function test_izin_payroll_cukup_tanpa_izin_karyawan(): void
    {
        $this->setSemuaIzin('/karyawan', 0);
        $this->setSemuaIzin('/kasbon', 0);
        $this->setSemuaIzin('/payroll', 1);
        $this->actingAsRole('KEUANGAN');

        $this->getJson('/api/payroll/periode')->assertStatus(200);
        $this->getJson('/api/payroll/pengaturan')->assertStatus(200);
        $this->getJson('/api/payroll/preview-rentang?bulan=2026-09')->assertStatus(200);

        $idPeriode = $this->postJson('/api/payroll/periode', ['bulan' => '2026-09'])->assertStatus(201)->json('data.id_periode');
        $this->getJson("/api/payroll/periode/{$idPeriode}")->assertStatus(200);
        $this->deleteJson("/api/payroll/periode/{$idPeriode}")->assertStatus(200);
    }

    public function test_izin_karyawan_tidak_membuka_payroll(): void
    {
        $this->setSemuaIzin('/karyawan', 1);
        $this->setSemuaIzin('/kasbon', 0);
        $this->setSemuaIzin('/payroll', 0);
        $this->actingAsRole('KEUANGAN');

        $this->getJson('/api/payroll/periode')->assertStatus(403);
        $this->getJson('/api/payroll/pengaturan')->assertStatus(403);
        $this->getJson('/api/payroll/preview-rentang?bulan=2026-09')->assertStatus(403);
        $this->postJson('/api/payroll/periode', ['bulan' => '2026-09'])->assertStatus(403);
    }

    public function test_aksi_payroll_mengikuti_centang_masing_masing(): void
    {
        $this->setSemuaIzin('/payroll', 0);
        $this->setIzin('/payroll', 'lihat', 1);
        $this->actingAsRole('KEUANGAN');

        $this->getJson('/api/payroll/periode')->assertStatus(200);
        $this->postJson('/api/payroll/periode', ['bulan' => '2026-09'])->assertStatus(403);
        $this->putJson('/api/payroll/pengaturan', [])->assertStatus(403);
    }

    public function test_preview_rentang_boleh_lewat_izin_kasbon(): void
    {
        $this->setSemuaIzin('/payroll', 0);
        $this->setSemuaIzin('/kasbon', 0);
        $this->setIzin('/kasbon', 'lihat', 1);
        $this->actingAsRole('KEUANGAN');

        $this->getJson('/api/payroll/preview-rentang?bulan=2026-09')->assertStatus(200);
        $this->getJson('/api/payroll/periode')->assertStatus(403);
    }
}
