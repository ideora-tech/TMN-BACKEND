<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KaryawanRiwayatJabatanTest extends TestCase
{
    use RefreshDatabase;

    private const PERUSAHAAN_LAIN = 'b8f3c1a2-0000-4000-8000-000000000099';

    private function makePerusahaanLain(): string
    {
        DB::table('perusahaan')->insertOrIgnore([
            'id_perusahaan' => self::PERUSAHAAN_LAIN, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now(),
        ]);
        return self::PERUSAHAAN_LAIN;
    }

    private function makeDepartemen(string $nama): string
    {
        $this->ensurePerusahaan();
        $id = (string) Str::uuid();
        DB::table('departemen')->insert([
            'id_departemen' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_departemen' => 'DEP-' . Str::random(6), 'nama_departemen' => $nama, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeJabatan(string $nama, array $extra = []): string
    {
        $this->ensurePerusahaan();
        $id = (string) Str::uuid();
        DB::table('jabatan')->insert(array_merge([
            'id_jabatan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jabatan' => 'JB-' . Str::random(6), 'nama_jabatan' => $nama, 'aktif' => 1, 'dibuat_pada' => now(),
        ], $extra));
        return $id;
    }

    private function makeKaryawan(?string $idJabatan = null, ?string $tanggalMasuk = '2024-01-10', ?string $idPerusahaan = null): string
    {
        $this->ensurePerusahaan();
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $id, 'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'id_jabatan' => $idJabatan, 'nik' => 'NIK-' . Str::random(8), 'nama_karyawan' => 'Andi',
            'tanggal_masuk' => $tanggalMasuk, 'status_kepegawaian' => 'tetap', 'gaji_pokok' => 4000000,
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function ubah(string $idKaryawan, array $payload)
    {
        return $this->patchJson("/api/karyawan/{$idKaryawan}/jabatan", $payload);
    }

    private function riwayat(string $idKaryawan): array
    {
        return $this->getJson("/api/karyawan/{$idKaryawan}/riwayat-jabatan")->assertStatus(200)->json('data');
    }

    private function setIzinKeuangan(string $aksi, int $diizinkan, string $path = '/karyawan'): void
    {
        $idMenu = DB::table('menu')->where('path', $path)->value('id_menu');
        if ($idMenu === null) {
            $idMenu = (string) Str::uuid();
            DB::table('menu')->insert(['id_menu' => $idMenu, 'nama_menu' => trim($path, '/'), 'path' => $path, 'aktif' => 1, 'dibuat_pada' => now()]);
        }
        DB::table('izin_peran')->where('id_menu', $idMenu)->where('kode_peran', 'KEUANGAN')->where('aksi', $aksi)->delete();
        DB::table('izin_peran')->insert([
            'id_izin' => (string) Str::uuid(), 'id_perusahaan' => null, 'kode_peran' => 'KEUANGAN',
            'id_menu' => $idMenu, 'aksi' => $aksi, 'diizinkan' => $diizinkan, 'dibuat_pada' => now(),
        ]);
    }

    public function test_ubah_jabatan_mengganti_jabatan_dan_mencatat_riwayat_lengkap(): void
    {
        $pengguna = $this->actingAsRole('SUPERADMIN');
        $idStaff = $this->makeJabatan('Staff Operasional', ['id_departemen' => $this->makeDepartemen('Operasional')]);
        $idSupervisor = $this->makeJabatan('Supervisor Operasional');
        $idKaryawan = $this->makeKaryawan($idStaff);

        $res = $this->ubah($idKaryawan, [
            'id_jabatan' => $idSupervisor, 'tanggal_efektif' => '2025-03-01', 'jenis' => 'promosi',
            'nomor_sk' => '012/SK-HR/III/2025', 'keterangan' => 'Kinerja baik',
        ])->assertStatus(200);

        $this->assertSame($idSupervisor, $res->json('data.jabatan.id_jabatan'));
        $this->assertSame($idSupervisor, DB::table('karyawan')->where('id_karyawan', $idKaryawan)->value('id_jabatan'));

        $riwayat = $this->riwayat($idKaryawan);
        $this->assertCount(2, $riwayat);

        $this->assertSame('Supervisor Operasional', $riwayat[0]['nama_jabatan']);
        $this->assertSame('2025-03-01', $riwayat[0]['tanggal_mulai']);
        $this->assertNull($riwayat[0]['tanggal_selesai']);
        $this->assertTrue($riwayat[0]['sedang_dijabat']);
        $this->assertSame('promosi', $riwayat[0]['jenis']);
        $this->assertSame('012/SK-HR/III/2025', $riwayat[0]['nomor_sk']);
        $this->assertSame('Kinerja baik', $riwayat[0]['keterangan']);
        $this->assertSame($pengguna->username, $riwayat[0]['dicatat_oleh']);
        $this->assertNotNull($riwayat[0]['id_riwayat']);

        $this->assertSame('Staff Operasional', $riwayat[1]['nama_jabatan']);
        $this->assertSame('Operasional', $riwayat[1]['nama_departemen']);
        $this->assertSame('2024-01-10', $riwayat[1]['tanggal_mulai']);
        $this->assertSame('2025-02-28', $riwayat[1]['tanggal_selesai']);
        $this->assertFalse($riwayat[1]['sedang_dijabat']);
        $this->assertSame('awal', $riwayat[1]['jenis']);
        $this->assertNull($riwayat[1]['id_riwayat']);
    }

    public function test_karyawan_yang_belum_pernah_berganti_menampilkan_jabatan_saat_ini(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan($this->makeJabatan('Staff Operasional'));

        $riwayat = $this->riwayat($idKaryawan);

        $this->assertCount(1, $riwayat);
        $this->assertSame('Staff Operasional', $riwayat[0]['nama_jabatan']);
        $this->assertSame('2024-01-10', $riwayat[0]['tanggal_mulai']);
        $this->assertTrue($riwayat[0]['sedang_dijabat']);
        $this->assertSame('awal', $riwayat[0]['jenis']);
    }

    public function test_karyawan_tanpa_jabatan_riwayatnya_kosong(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $this->assertSame([], $this->riwayat($this->makeKaryawan()));
    }

    public function test_penetapan_jabatan_pertama_otomatis_berjenis_penempatan_awal(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan();

        $this->ubah($idKaryawan, ['id_jabatan' => $this->makeJabatan('Staff Operasional'), 'tanggal_efektif' => '2024-02-01', 'jenis' => 'promosi'])
            ->assertStatus(200);

        $riwayat = $this->riwayat($idKaryawan);
        $this->assertCount(1, $riwayat);
        $this->assertSame('awal', $riwayat[0]['jenis']);
        $this->assertSame('2024-02-01', $riwayat[0]['tanggal_mulai']);
        $this->assertTrue($riwayat[0]['sedang_dijabat']);
    }

    public function test_jenis_wajib_bila_bukan_penempatan_awal(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan($this->makeJabatan('Staff Operasional'));

        $this->ubah($idKaryawan, ['id_jabatan' => $this->makeJabatan('Supervisor'), 'tanggal_efektif' => '2025-03-01'])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('riwayat_jabatan')->where('id_karyawan', $idKaryawan)->count());
    }

    public function test_jabatan_yang_sama_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idJabatan = $this->makeJabatan('Staff Operasional');
        $idKaryawan = $this->makeKaryawan($idJabatan);

        $this->ubah($idKaryawan, ['id_jabatan' => $idJabatan, 'tanggal_efektif' => '2025-03-01', 'jenis' => 'mutasi'])
            ->assertStatus(422);
    }

    public function test_jabatan_nonaktif_dan_jabatan_perusahaan_lain_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan($this->makeJabatan('Staff Operasional'));
        $idNonaktif = $this->makeJabatan('Jabatan Lama', ['aktif' => 0]);
        $idLain = $this->makeJabatan('Jabatan Lain', ['id_perusahaan' => $this->makePerusahaanLain()]);

        $this->ubah($idKaryawan, ['id_jabatan' => $idNonaktif, 'tanggal_efektif' => '2025-03-01', 'jenis' => 'mutasi'])->assertStatus(422);
        $this->ubah($idKaryawan, ['id_jabatan' => $idLain, 'tanggal_efektif' => '2025-03-01', 'jenis' => 'mutasi'])->assertStatus(422);
    }

    public function test_tanggal_efektif_tidak_boleh_ke_depan_sebelum_masuk_atau_sebelum_perubahan_terakhir(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan($this->makeJabatan('Staff Operasional'));
        $idSupervisor = $this->makeJabatan('Supervisor');
        $idManajer = $this->makeJabatan('Manajer');

        $this->ubah($idKaryawan, ['id_jabatan' => $idSupervisor, 'tanggal_efektif' => now()->addDay()->toDateString(), 'jenis' => 'promosi'])->assertStatus(422);
        $this->ubah($idKaryawan, ['id_jabatan' => $idSupervisor, 'tanggal_efektif' => '2024-01-09', 'jenis' => 'promosi'])->assertStatus(422);

        $this->ubah($idKaryawan, ['id_jabatan' => $idSupervisor, 'tanggal_efektif' => '2025-03-01', 'jenis' => 'promosi'])->assertStatus(200);
        $this->ubah($idKaryawan, ['id_jabatan' => $idManajer, 'tanggal_efektif' => '2025-02-28', 'jenis' => 'promosi'])->assertStatus(422);
        $this->ubah($idKaryawan, ['id_jabatan' => $idManajer, 'tanggal_efektif' => '2025-03-01', 'jenis' => 'promosi'])->assertStatus(200);

        $riwayat = $this->riwayat($idKaryawan);
        $this->assertSame(['Manajer', 'Supervisor', 'Staff Operasional'], array_column($riwayat, 'nama_jabatan'));
        $this->assertSame('2025-03-01', $riwayat[1]['tanggal_selesai']);
    }

    public function test_koreksi_catatan_mengubah_rincian_dan_menjaga_urutan_tanggal(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan($this->makeJabatan('Staff Operasional'));
        $this->ubah($idKaryawan, ['id_jabatan' => $this->makeJabatan('Supervisor'), 'tanggal_efektif' => '2025-03-01', 'jenis' => 'promosi'])->assertStatus(200);
        $this->ubah($idKaryawan, ['id_jabatan' => $this->makeJabatan('Manajer'), 'tanggal_efektif' => '2025-09-01', 'jenis' => 'promosi'])->assertStatus(200);

        $riwayat = $this->riwayat($idKaryawan);
        $idManajer = $riwayat[0]['id_riwayat'];
        $idSupervisor = $riwayat[1]['id_riwayat'];
        $url = fn (string $idRiwayat) => "/api/karyawan/{$idKaryawan}/riwayat-jabatan/{$idRiwayat}";

        $hasil = $this->patchJson($url($idSupervisor), [
            'tanggal_efektif' => '2025-04-01', 'jenis' => 'mutasi', 'nomor_sk' => 'SK-77', 'keterangan' => 'Koreksi tanggal',
        ])->assertStatus(200)->json('data');

        $this->assertSame('2025-04-01', $hasil[1]['tanggal_mulai']);
        $this->assertSame('2025-08-31', $hasil[1]['tanggal_selesai']);
        $this->assertSame('mutasi', $hasil[1]['jenis']);
        $this->assertSame('SK-77', $hasil[1]['nomor_sk']);
        $this->assertSame('2025-03-31', $hasil[2]['tanggal_selesai']);

        $this->patchJson($url($idSupervisor), ['tanggal_efektif' => '2025-10-01', 'jenis' => 'mutasi'])->assertStatus(422);
        $this->patchJson($url($idSupervisor), ['tanggal_efektif' => '2024-01-01', 'jenis' => 'mutasi'])->assertStatus(422);
        $this->patchJson($url($idManajer), ['tanggal_efektif' => '2025-03-15', 'jenis' => 'promosi'])->assertStatus(422);
        $this->patchJson($url($idManajer), ['tanggal_efektif' => now()->addDay()->toDateString(), 'jenis' => 'promosi'])->assertStatus(422);
        $this->patchJson($url($idManajer), ['tanggal_efektif' => '2025-09-10'])->assertStatus(422);
    }

    public function test_koreksi_catatan_milik_karyawan_lain_tidak_ditemukan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idSupervisor = $this->makeJabatan('Supervisor');
        $idKaryawanA = $this->makeKaryawan($this->makeJabatan('Staff Operasional'));
        $idKaryawanB = $this->makeKaryawan($this->makeJabatan('Staff Gudang'));
        $this->ubah($idKaryawanA, ['id_jabatan' => $idSupervisor, 'tanggal_efektif' => '2025-03-01', 'jenis' => 'promosi'])->assertStatus(200);
        $idRiwayatA = $this->riwayat($idKaryawanA)[0]['id_riwayat'];

        $this->patchJson("/api/karyawan/{$idKaryawanB}/riwayat-jabatan/{$idRiwayatA}", ['tanggal_efektif' => '2025-03-02', 'jenis' => 'mutasi'])
            ->assertStatus(404);
    }

    public function test_karyawan_perusahaan_lain_tidak_bisa_dibaca_atau_diubah(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idLain = $this->makePerusahaanLain();
        $idKaryawanLain = $this->makeKaryawan(null, '2024-01-10', $idLain);

        $this->getJson("/api/karyawan/{$idKaryawanLain}/riwayat-jabatan")->assertStatus(404);
        $this->getJson("/api/karyawan/{$idKaryawanLain}/exit-history")->assertStatus(404);
        $this->ubah($idKaryawanLain, ['id_jabatan' => $this->makeJabatan('Staff'), 'tanggal_efektif' => '2025-03-01'])->assertStatus(404);
    }

    public function test_nama_jabatan_di_riwayat_tidak_ikut_berubah_saat_master_diganti_atau_dihapus(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idStaff = $this->makeJabatan('Staff Operasional');
        $idSupervisor = $this->makeJabatan('Supervisor');
        $idKaryawan = $this->makeKaryawan($idStaff);
        $this->ubah($idKaryawan, ['id_jabatan' => $idSupervisor, 'tanggal_efektif' => '2025-03-01', 'jenis' => 'promosi'])->assertStatus(200);
        $this->ubah($idKaryawan, ['id_jabatan' => $this->makeJabatan('Manajer'), 'tanggal_efektif' => '2025-09-01', 'jenis' => 'promosi'])->assertStatus(200);

        DB::table('jabatan')->where('id_jabatan', $idStaff)->update(['nama_jabatan' => 'Admin Operasional']);
        DB::table('jabatan')->where('id_jabatan', $idSupervisor)->update(['dihapus_pada' => now()]);

        $this->assertSame(['Manajer', 'Supervisor', 'Staff Operasional'], array_column($this->riwayat($idKaryawan), 'nama_jabatan'));
    }

    public function test_koreksi_tanpa_menggeser_tanggal_tetap_bisa_untuk_catatan_lama(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idStaff = $this->makeJabatan('Staff Operasional');
        $idKaryawan = $this->makeKaryawan($idStaff);
        $idRiwayat = (string) Str::uuid();
        DB::table('riwayat_jabatan')->insert([
            'id_riwayat' => $idRiwayat, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_karyawan' => $idKaryawan,
            'id_jabatan_lama' => $this->makeJabatan('Magang'), 'id_jabatan_baru' => $idStaff,
            'nama_jabatan_lama' => 'Magang', 'nama_jabatan_baru' => 'Staff Operasional',
            'tanggal_efektif' => '2023-06-01', 'urutan' => 1, 'dibuat_pada' => now(),
        ]);
        $url = "/api/karyawan/{$idKaryawan}/riwayat-jabatan/{$idRiwayat}";

        $hasil = $this->patchJson($url, ['tanggal_efektif' => '2023-06-01', 'jenis' => 'promosi', 'nomor_sk' => 'SK-1'])
            ->assertStatus(200)->json('data');

        $this->assertSame('promosi', $hasil[0]['jenis']);
        $this->assertSame('SK-1', $hasil[0]['nomor_sk']);
        $this->patchJson($url, ['tanggal_efektif' => '2023-07-01', 'jenis' => 'promosi'])->assertStatus(422);
    }

    public function test_jabatan_yang_dilepas_lewat_jalur_lama_tampil_sebagai_tanpa_jabatan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan($this->makeJabatan('Staff Operasional'));

        $this->putJson("/api/karyawan/{$idKaryawan}", ['id_jabatan' => null])->assertStatus(200);

        $riwayat = $this->riwayat($idKaryawan);
        $this->assertCount(2, $riwayat);
        $this->assertNull($riwayat[0]['id_jabatan']);
        $this->assertNotNull($riwayat[0]['id_riwayat']);
        $this->assertFalse($riwayat[0]['sedang_dijabat']);
        $this->assertSame('Staff Operasional', $riwayat[1]['nama_jabatan']);
        $this->assertSame('2024-01-10', $riwayat[1]['tanggal_mulai']);
    }

    public function test_karyawan_yang_belum_mulai_bisa_ditetapkan_jabatannya_per_tanggal_masuk(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $besok = now()->addDay()->toDateString();
        $idKaryawan = $this->makeKaryawan(null, $besok);
        $idJabatan = $this->makeJabatan('Staff Operasional');

        $this->ubah($idKaryawan, ['id_jabatan' => $idJabatan, 'tanggal_efektif' => now()->addDays(2)->toDateString()])->assertStatus(422);
        $this->ubah($idKaryawan, ['id_jabatan' => $idJabatan, 'tanggal_efektif' => $besok])->assertStatus(200);
    }

    public function test_pemilik_izin_karyawan_bisa_membaca_daftar_jabatan_tanpa_izin_menu_jabatan(): void
    {
        $idJabatan = $this->makeJabatan('Staff Operasional');
        foreach (['lihat', 'tambah', 'ubah', 'hapus'] as $aksi) {
            $this->setIzinKeuangan($aksi, 0, '/jabatan');
            $this->setIzinKeuangan($aksi, 1, '/karyawan');
        }
        $this->actingAsRole('KEUANGAN');

        $this->getJson('/api/jabatan?limit=999')->assertStatus(200)->assertJsonFragment(['id_jabatan' => $idJabatan]);
        $this->getJson("/api/jabatan/{$idJabatan}")->assertStatus(200);
        $this->postJson('/api/jabatan', ['nama_jabatan' => 'Jabatan Baru'])->assertStatus(403);
        $this->deleteJson("/api/jabatan/{$idJabatan}")->assertStatus(403);
        $this->getJson('/api/jabatan/struktur-organisasi')->assertStatus(403);
    }

    public function test_ubah_jabatan_butuh_izin_ubah_karyawan(): void
    {
        $idKaryawan = $this->makeKaryawan($this->makeJabatan('Staff Operasional'));
        $payload = ['id_jabatan' => $this->makeJabatan('Supervisor'), 'tanggal_efektif' => '2025-03-01', 'jenis' => 'promosi'];
        $this->setIzinKeuangan('lihat', 1);
        $this->setIzinKeuangan('ubah', 0);
        $this->actingAsRole('KEUANGAN');

        $this->ubah($idKaryawan, $payload)->assertStatus(403);
        $this->getJson("/api/karyawan/{$idKaryawan}/riwayat-jabatan")->assertStatus(200);

        $this->setIzinKeuangan('ubah', 1);
        $this->ubah($idKaryawan, $payload)->assertStatus(200);
    }
}
