<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\ArusKas\Contracts\ArusKasRepositoryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApprovalKeuanganKonfigTest extends TestCase
{
    use RefreshDatabase;

    private function buatJabatan(string $nama = 'Manager Keuangan'): string
    {
        $id = (string) Str::uuid();
        DB::table('jabatan')->insert([
            'id_jabatan'    => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jabatan'  => 'JBT-' . Str::random(4),
            'nama_jabatan'  => $nama,
            'level'         => 1,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function buatKaryawan(string $idJabatan, string $nama = 'Budi Karyawan'): string
    {
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan'   => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_jabatan'    => $idJabatan,
            'nik'           => 'NIK-' . Str::random(6),
            'nama_karyawan' => $nama,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function buatPengguna(string $username, ?string $idKaryawan = null, ?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('pengguna')->insert([
            'id_pengguna'   => $id,
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'id_karyawan'   => $idKaryawan,
            'username'      => $username,
            'email'         => $username . '@test.id',
            'kata_sandi'    => bcrypt('Password123!'),
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function buatPerusahaanLain(): string
    {
        $id = (string) Str::uuid();
        DB::table('perusahaan')->insert([
            'id_perusahaan' => $id,
            'nama'          => 'Perusahaan Lain',
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function buatJabatanUntuk(string $idPerusahaan, string $nama = 'Manager Lain'): string
    {
        $id = (string) Str::uuid();
        DB::table('jabatan')->insert([
            'id_jabatan'    => $id,
            'id_perusahaan' => $idPerusahaan,
            'kode_jabatan'  => 'JBT-' . Str::random(4),
            'nama_jabatan'  => $nama,
            'level'         => 1,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function buatKaryawanUntuk(string $idPerusahaan, string $idJabatan, string $nama = 'Karyawan Lain'): string
    {
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan'   => $id,
            'id_perusahaan' => $idPerusahaan,
            'id_jabatan'    => $idJabatan,
            'nik'           => 'NIK-' . Str::random(6),
            'nama_karyawan' => $nama,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    public function test_tambah_approver_jabatan_dan_pengguna_muncul_di_list_dengan_nama(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idJabatan  = $this->buatJabatan('Manager Keuangan');
        $idPengguna = $this->buatPengguna('budi_approver');

        $this->postJson('/api/arus-kas/approver', [
            'tipe'       => 'jabatan',
            'id_jabatan' => $idJabatan,
        ])->assertStatus(201);

        $this->postJson('/api/arus-kas/approver', [
            'tipe'        => 'pengguna',
            'id_pengguna' => $idPengguna,
        ])->assertStatus(201);

        $res = $this->getJson('/api/arus-kas/approver');
        $res->assertStatus(200);

        $data = $res->json('data');
        $this->assertCount(2, $data);
        foreach ($data as $row) {
            $this->assertNotNull($row['nama']);
            $this->assertNotSame('', $row['nama']);
        }

        $namaList = array_column($data, 'nama');
        $this->assertContains('Manager Keuangan', $namaList);
        $this->assertContains('budi_approver', $namaList);
    }

    public function test_tambah_approver_duplikat_ditolak_409(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idJabatan = $this->buatJabatan();

        $this->postJson('/api/arus-kas/approver', [
            'tipe'       => 'jabatan',
            'id_jabatan' => $idJabatan,
        ])->assertStatus(201);

        $this->postJson('/api/arus-kas/approver', [
            'tipe'       => 'jabatan',
            'id_jabatan' => $idJabatan,
        ])->assertStatus(409);
    }

    public function test_validasi_field_wajib_approver(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $this->postJson('/api/arus-kas/approver', ['tipe' => 'jabatan'])->assertStatus(422);
        $this->postJson('/api/arus-kas/approver', ['tipe' => 'pengguna'])->assertStatus(422);
        $this->postJson('/api/arus-kas/approver', ['tipe' => 'tidak_dikenal'])->assertStatus(422);
    }

    public function test_hapus_approver_hilang_dari_list(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idJabatan = $this->buatJabatan();

        $this->postJson('/api/arus-kas/approver', [
            'tipe'       => 'jabatan',
            'id_jabatan' => $idJabatan,
        ])->assertStatus(201);

        $idApprover = DB::table('approver_keuangan')
            ->where('id_perusahaan', self::PERUSAHAAN_ID)
            ->where('id_jabatan', $idJabatan)
            ->value('id_approver');

        $this->deleteJson("/api/arus-kas/approver/{$idApprover}")->assertStatus(200);

        $data = $this->getJson('/api/arus-kas/approver')->json('data');
        $this->assertCount(0, $data);
        $this->assertSoftDeleted('approver_keuangan', ['id_approver' => $idApprover]);
    }

    public function test_hapus_approver_tidak_ada_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->deleteJson('/api/arus-kas/approver/' . Str::uuid())->assertStatus(404);
    }

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

    public function test_role_keuangan_akses_post_approver_ditolak_403(): void
    {
        $this->actingAsRole('KEUANGAN');
        $idJabatan = $this->buatJabatan();

        $this->postJson('/api/arus-kas/approver', [
            'tipe'       => 'jabatan',
            'id_jabatan' => $idJabatan,
        ])->assertStatus(403);
    }

    public function test_role_admin_bisa_akses_approver_dan_pengaturan(): void
    {
        $this->actingAsRole('ADMIN');
        $idJabatan = $this->buatJabatan();

        $this->postJson('/api/arus-kas/approver', [
            'tipe'       => 'jabatan',
            'id_jabatan' => $idJabatan,
        ])->assertStatus(201);

        $this->getJson('/api/arus-kas/approver')->assertStatus(200);
        $this->putJson('/api/arus-kas/pengaturan-approval', ['batas' => 1000])->assertStatus(200);
    }

    public function test_resolusi_approver_jabatan_melalui_karyawan_dan_pengguna_langsung(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idJabatan = $this->buatJabatan('Manager Keuangan');

        $idKaryawan = $this->buatKaryawan($idJabatan, 'Budi Karyawan');
        $idPenggunaJabatan = $this->buatPengguna('budi_pengguna', $idKaryawan);

        $idKaryawanTidakAktif = $this->buatKaryawan($idJabatan, 'Karyawan Nonaktif');
        DB::table('karyawan')->where('id_karyawan', $idKaryawanTidakAktif)->update(['aktif' => 0]);
        $this->buatPengguna('karyawan_nonaktif', $idKaryawanTidakAktif);

        $idPenggunaLangsung = $this->buatPengguna('siti_approver');

        $idPenggunaLangsungNonaktif = $this->buatPengguna('nonaktif_approver');
        DB::table('pengguna')->where('id_pengguna', $idPenggunaLangsungNonaktif)->update(['aktif' => 0]);

        $this->postJson('/api/arus-kas/approver', [
            'tipe'       => 'jabatan',
            'id_jabatan' => $idJabatan,
        ])->assertStatus(201);

        $this->postJson('/api/arus-kas/approver', [
            'tipe'        => 'pengguna',
            'id_pengguna' => $idPenggunaLangsung,
        ])->assertStatus(201);

        $this->postJson('/api/arus-kas/approver', [
            'tipe'        => 'pengguna',
            'id_pengguna' => $idPenggunaLangsungNonaktif,
        ])->assertStatus(201);

        $repo = app(ArusKasRepositoryInterface::class);
        $hasil = $repo->resolusiApprover(self::PERUSAHAAN_ID);

        $this->assertCount(2, $hasil);
        $this->assertContains($idPenggunaJabatan, $hasil);
        $this->assertContains($idPenggunaLangsung, $hasil);
        $this->assertNotContains($idPenggunaLangsungNonaktif, $hasil);
    }

    public function test_tambah_approver_jabatan_milik_perusahaan_lain_ditolak_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPerusahaanLain = $this->buatPerusahaanLain();
        $idJabatanLain = $this->buatJabatanUntuk($idPerusahaanLain);

        $this->postJson('/api/arus-kas/approver', [
            'tipe'       => 'jabatan',
            'id_jabatan' => $idJabatanLain,
        ])->assertStatus(404);

        $this->assertSame(0, DB::table('approver_keuangan')->where('id_perusahaan', self::PERUSAHAAN_ID)->count());
    }

    public function test_tambah_approver_pengguna_milik_perusahaan_lain_ditolak_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPerusahaanLain = $this->buatPerusahaanLain();
        $idPenggunaLain = $this->buatPengguna('pengguna_lain', null, $idPerusahaanLain);

        $this->postJson('/api/arus-kas/approver', [
            'tipe'        => 'pengguna',
            'id_pengguna' => $idPenggunaLain,
        ])->assertStatus(404);

        $this->assertSame(0, DB::table('approver_keuangan')->where('id_perusahaan', self::PERUSAHAAN_ID)->count());
    }

    public function test_resolusi_approver_tidak_menyertakan_karyawan_lintas_tenant(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idJabatan = $this->buatJabatan('Manager Keuangan');

        $this->postJson('/api/arus-kas/approver', [
            'tipe'       => 'jabatan',
            'id_jabatan' => $idJabatan,
        ])->assertStatus(201);

        $idPerusahaanLain = $this->buatPerusahaanLain();
        $idKaryawanLain = $this->buatKaryawanUntuk($idPerusahaanLain, $idJabatan, 'Karyawan Lintas Tenant');
        $idPenggunaLain = $this->buatPengguna('pengguna_lintas_tenant', $idKaryawanLain, $idPerusahaanLain);

        $repo = app(ArusKasRepositoryInterface::class);
        $hasil = $repo->resolusiApprover(self::PERUSAHAAN_ID);

        $this->assertNotContains($idPenggunaLain, $hasil);
    }
}
