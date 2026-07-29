<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupirKaryawanMigrasiTest extends TestCase
{
    use RefreshDatabase;

    private function makeSupir(string $nama, ?string $idKaryawan = null, string $status = 'aktif'): string
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_karyawan'   => $idKaryawan,
            'nama'          => $nama,
            'no_sim'        => 'SIM-' . Str::random(8),
            'telepon'       => '0812000001',
            'status'        => $status,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function jalankanMigrasi(): void
    {
        $migration = require base_path('database/migrations/2026_07_29_100010_isi_karyawan_dari_supir.php');
        $migration->up();
    }

    public function test_supir_tanpa_karyawan_dibuatkan_dan_ditautkan(): void
    {
        $idSupirA = $this->makeSupir('Supir Migrasi A');
        $idSupirB = $this->makeSupir('Supir Migrasi B', null, 'nonaktif');

        $this->jalankanMigrasi();

        $supirA = DB::table('supir')->where('id_supir', $idSupirA)->first();
        $supirB = DB::table('supir')->where('id_supir', $idSupirB)->first();
        $this->assertNotNull($supirA->id_karyawan);
        $this->assertNotNull($supirB->id_karyawan);

        $karyawanA = DB::table('karyawan')->where('id_karyawan', $supirA->id_karyawan)->first();
        $this->assertSame('Supir Migrasi A', $karyawanA->nama_karyawan);
        $this->assertSame(self::PERUSAHAAN_ID, $karyawanA->id_perusahaan);
        $this->assertSame('SPR-' . strtoupper(substr($idSupirA, 0, 8)), $karyawanA->nik);
        $this->assertSame(1, (int) $karyawanA->aktif);

        $karyawanB = DB::table('karyawan')->where('id_karyawan', $supirB->id_karyawan)->first();
        $this->assertSame(0, (int) $karyawanB->aktif);
    }

    public function test_supir_yang_sudah_tertaut_dilewati_dan_migrasi_idempoten(): void
    {
        $idKaryawanLama = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan'   => $idKaryawanLama,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nik'           => 'NIK-MANUAL-01',
            'nama_karyawan' => 'Karyawan Manual',
            'dibuat_pada'   => now(),
        ]);
        $idSupirTertaut = $this->makeSupir('Supir Sudah Tertaut', $idKaryawanLama);
        $this->makeSupir('Supir Belum Tertaut');

        $this->jalankanMigrasi();
        $this->jalankanMigrasi();

        $this->assertSame(
            $idKaryawanLama,
            DB::table('supir')->where('id_supir', $idSupirTertaut)->value('id_karyawan'),
        );
        $this->assertSame(2, DB::table('karyawan')->count());
    }
}
