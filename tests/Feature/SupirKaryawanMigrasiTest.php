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

    private function makeSupir(string $nama, ?string $idKaryawan = null, string $status = 'aktif', ?string $id = null): string
    {
        $id ??= (string) Str::uuid();
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
        $this->assertSame('SPR-' . strtoupper(str_replace('-', '', $idSupirA)), $karyawanA->nik);
        $this->assertSame(1, (int) $karyawanA->aktif);

        $karyawanB = DB::table('karyawan')->where('id_karyawan', $supirB->id_karyawan)->first();
        $this->assertSame(0, (int) $karyawanB->aktif);
    }

    public function test_command_karyawan_dari_supir_menautkan_semua_dan_idempoten(): void
    {
        $this->makeSupir('Supir Command A');
        $this->makeSupir('Supir Command B', null, 'nonaktif');

        $this->artisan('karyawan:dari-supir')->assertSuccessful();
        $this->artisan('karyawan:dari-supir')->assertSuccessful();

        $this->assertSame(0, DB::table('supir')->whereNull('id_karyawan')->count());
        $this->assertSame(2, DB::table('karyawan')->count());
    }

    public function test_command_memperbaiki_banyak_supir_menunjuk_satu_karyawan(): void
    {
        $idSupirPemilik = $this->makeSupir('Supir Pemilik Sah');
        $nikPemilik = 'SPR-' . strtoupper(str_replace('-', '', $idSupirPemilik));

        $idKaryawan = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan'   => $idKaryawan,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nik'           => $nikPemilik,
            'nama_karyawan' => 'Supir Pemilik Sah',
            'dibuat_pada'   => now(),
        ]);

        DB::table('supir')->where('id_supir', $idSupirPemilik)->update(['id_karyawan' => $idKaryawan]);
        $idSupirNebeng1 = $this->makeSupir('Supir Nebeng Satu', $idKaryawan);
        $idSupirNebeng2 = $this->makeSupir('Supir Nebeng Dua', $idKaryawan);

        $this->artisan('karyawan:dari-supir')->assertSuccessful();

        $this->assertSame($idKaryawan, DB::table('supir')->where('id_supir', $idSupirPemilik)->value('id_karyawan'));

        $karyawanNebeng1 = DB::table('supir')->where('id_supir', $idSupirNebeng1)->value('id_karyawan');
        $karyawanNebeng2 = DB::table('supir')->where('id_supir', $idSupirNebeng2)->value('id_karyawan');
        $this->assertNotNull($karyawanNebeng1);
        $this->assertNotNull($karyawanNebeng2);
        $this->assertNotSame($idKaryawan, $karyawanNebeng1);
        $this->assertNotSame($idKaryawan, $karyawanNebeng2);
        $this->assertNotSame($karyawanNebeng1, $karyawanNebeng2);
        $this->assertSame(3, DB::table('karyawan')->count());
    }

    public function test_supir_dengan_prefix_uuid_sama_tidak_bentrok_nik(): void
    {
        // Pola nyata data seed/produksi: banyak id_supir berbagi 8 karakter
        // awal yang sama (mis. 'd2000001-0000-4000-8000-0000000000XX').
        // NIK harus tetap unik per supir meski prefix-nya sama.
        $idA = $this->makeSupir('Supir Prefix A', null, 'aktif', 'd2000001-0000-4000-8000-000000000001');
        $idB = $this->makeSupir('Supir Prefix B', null, 'aktif', 'd2000001-0000-4000-8000-000000000002');
        $idC = $this->makeSupir('Supir Prefix C', null, 'aktif', 'd2000001-0000-4000-8000-000000000003');

        $this->artisan('karyawan:dari-supir')->assertSuccessful();

        $karyawanA = DB::table('supir')->where('id_supir', $idA)->value('id_karyawan');
        $karyawanB = DB::table('supir')->where('id_supir', $idB)->value('id_karyawan');
        $karyawanC = DB::table('supir')->where('id_supir', $idC)->value('id_karyawan');

        $this->assertNotNull($karyawanA);
        $this->assertNotNull($karyawanB);
        $this->assertNotNull($karyawanC);
        $this->assertNotSame($karyawanA, $karyawanB);
        $this->assertNotSame($karyawanA, $karyawanC);
        $this->assertNotSame($karyawanB, $karyawanC);
        $this->assertSame(3, DB::table('karyawan')->count());
    }

    public function test_command_memperbaiki_tautan_orphan(): void
    {
        $idSupirOrphan = $this->makeSupir('Supir Orphan', (string) Str::uuid());

        $this->artisan('karyawan:dari-supir')->assertSuccessful();

        $idKaryawanBaru = DB::table('supir')->where('id_supir', $idSupirOrphan)->value('id_karyawan');
        $this->assertNotNull($idKaryawanBaru);
        $this->assertNotNull(DB::table('karyawan')->where('id_karyawan', $idKaryawanBaru)->first());
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
