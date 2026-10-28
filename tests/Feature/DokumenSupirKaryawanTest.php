<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DokumenSupirKaryawanTest extends TestCase
{
    use RefreshDatabase;

    private const PERAN = 'OPS_DOKUMEN';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePerusahaan();
    }

    private function izinMenu(string $path, array $aksi): void
    {
        $idMenu = DB::table('menu')->where('path', $path)->value('id_menu');
        if ($idMenu === null) {
            $idMenu = (string) Str::uuid();
            DB::table('menu')->insert(['id_menu' => $idMenu, 'nama_menu' => ltrim($path, '/'), 'path' => $path, 'aktif' => 1, 'dibuat_pada' => now()]);
        }
        foreach ($aksi as $a) {
            DB::table('izin_peran')->insert([
                'id_izin' => (string) Str::uuid(), 'id_perusahaan' => null, 'kode_peran' => self::PERAN,
                'id_menu' => $idMenu, 'aksi' => $a, 'diizinkan' => 1, 'dibuat_pada' => now(),
            ]);
        }
    }

    private function karyawan(string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $id, 'id_perusahaan' => $idPerusahaan, 'nik' => 'NIK-' . Str::random(6),
            'nama_karyawan' => 'Angga Alfiana', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        return $id;
    }

    private function supir(?string $idKaryawan): string
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_karyawan' => $idKaryawan,
            'nama' => 'Angga Alfiana', 'no_sim' => 'SIM-' . Str::random(6), 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);

        return $id;
    }

    private function dokumen(string $idKaryawan): string
    {
        $id = (string) Str::uuid();
        DB::table('dokumen_karyawan')->insert([
            'id_dokumen_karyawan' => $id, 'id_karyawan' => $idKaryawan, 'jenis_dokumen' => 'KTP', 'dibuat_pada' => now(),
        ]);

        return $id;
    }

    public function test_peran_dengan_izin_supir_saja_bisa_kelola_dokumen_supir(): void
    {
        $this->izinMenu('/supir', ['lihat', 'tambah', 'ubah', 'hapus']);
        $this->actingAsRole(self::PERAN);
        $idKaryawan = $this->karyawan();
        $idSupir = $this->supir($idKaryawan);

        $this->postJson("/api/karyawan/{$idKaryawan}/dokumen", ['jenis_dokumen' => 'SIM'])->assertStatus(403);

        $id = $this->postJson("/api/supir/{$idSupir}/dokumen-karyawan", [
            'jenis_dokumen' => 'SIM', 'nomor' => '12209301001349', 'berlaku_sampai' => '2031-06-17',
        ])->assertStatus(201)->json('data.id_dokumen_karyawan');

        $this->assertSame($idKaryawan, DB::table('dokumen_karyawan')->where('id_dokumen_karyawan', $id)->value('id_karyawan'));
        $this->assertSame('2031-06-17', substr((string) DB::table('supir')->where('id_supir', $idSupir)->value('tgl_kadaluarsa_sim'), 0, 10));

        $this->getJson("/api/supir/{$idSupir}/dokumen-karyawan")->assertStatus(200)->assertJsonCount(1, 'data');
        $this->putJson("/api/supir/{$idSupir}/dokumen-karyawan/{$id}", ['jenis_dokumen' => 'SIM', 'nomor' => 'BARU'])
            ->assertStatus(200)->assertJsonPath('data.nomor', 'BARU');
        $this->deleteJson("/api/supir/{$idSupir}/dokumen-karyawan/{$id}")->assertStatus(200);
        $this->getJson("/api/supir/{$idSupir}/dokumen-karyawan")->assertStatus(200)->assertJsonCount(0, 'data');
    }

    public function test_dokumen_karyawan_lain_tidak_bisa_disentuh_lewat_jalur_supir(): void
    {
        $this->izinMenu('/supir', ['lihat', 'tambah', 'ubah', 'hapus']);
        $this->actingAsRole(self::PERAN);
        $idSupir = $this->supir($this->karyawan());
        $dokumenOrangLain = $this->dokumen($this->karyawan());

        $this->putJson("/api/supir/{$idSupir}/dokumen-karyawan/{$dokumenOrangLain}", ['jenis_dokumen' => 'SIM'])->assertStatus(404);
        $this->deleteJson("/api/supir/{$idSupir}/dokumen-karyawan/{$dokumenOrangLain}")->assertStatus(404);
        $this->assertNull(DB::table('dokumen_karyawan')->where('id_dokumen_karyawan', $dokumenOrangLain)->value('dihapus_pada'));

        $tanpaKaryawan = $this->supir(null);
        $dokumenSupirLain = $this->postJson("/api/supir/{$this->supir(null)}/dokumen-karyawan", ['jenis_dokumen' => 'KTP'])->json('data.id_dokumen_karyawan');
        $this->deleteJson("/api/supir/{$tanpaKaryawan}/dokumen-karyawan/{$dokumenSupirLain}")->assertStatus(404);
        $this->putJson("/api/karyawan/" . Str::uuid() . "/dokumen/{$dokumenSupirLain}", ['jenis_dokumen' => 'SIM'])->assertStatus(403);
        $this->getJson('/api/supir/' . Str::uuid() . '/dokumen-karyawan')->assertStatus(404);
    }

    public function test_masa_berlaku_sim_supir_tertaut_mengikuti_dokumen_sim(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $denganSim = $this->karyawan();
        DB::table('dokumen_karyawan')->insert([
            'id_dokumen_karyawan' => (string) Str::uuid(), 'id_karyawan' => $denganSim, 'jenis_dokumen' => 'SIM',
            'berlaku_sampai' => '2031-06-17', 'dibuat_pada' => now(),
        ]);
        $tgl = fn (string $idSupir) => substr((string) DB::table('supir')->where('id_supir', $idSupir)->value('tgl_kadaluarsa_sim'), 0, 10);

        $idSupir = $this->postJson('/api/supir', [
            'nama' => 'Angga Alfiana', 'jenis_sim' => 'B1', 'id_karyawan' => $denganSim, 'tgl_kadaluarsa_sim' => '2026-01-01',
        ])->assertStatus(201)->json('data.id_supir');
        $this->assertSame('2031-06-17', $tgl($idSupir));

        $this->putJson("/api/supir/{$idSupir}", ['tgl_kadaluarsa_sim' => '2027-01-01'])->assertStatus(200);
        $this->assertSame('2031-06-17', $tgl($idSupir));

        $tanpaSim = $this->postJson('/api/supir', [
            'nama' => 'Budi', 'jenis_sim' => 'B1', 'id_karyawan' => $this->karyawan(), 'tgl_kadaluarsa_sim' => '2028-03-03',
        ])->assertStatus(201)->json('data.id_supir');
        $this->assertSame('2028-03-03', $tgl($tanpaSim));

        $tanpaKaryawan = $this->postJson('/api/supir', [
            'nama' => 'Cahyo', 'jenis_sim' => 'B1', 'tgl_kadaluarsa_sim' => '2029-04-04',
        ])->assertStatus(201)->json('data.id_supir');
        $this->putJson("/api/supir/{$tanpaKaryawan}", ['tgl_kadaluarsa_sim' => '2029-05-05'])->assertStatus(200);
        $this->assertSame('2029-05-05', $tgl($tanpaKaryawan));
    }

    public function test_supir_tanpa_karyawan_tetap_bisa_kelola_dokumen_dan_ikut_pindah_saat_ditautkan(): void
    {
        $this->izinMenu('/supir', ['lihat', 'tambah', 'ubah', 'hapus']);
        $this->actingAsRole(self::PERAN);
        $idSupir = $this->supir(null);
        $tgl = fn () => substr((string) DB::table('supir')->where('id_supir', $idSupir)->value('tgl_kadaluarsa_sim'), 0, 10);

        $idSim = $this->postJson("/api/supir/{$idSupir}/dokumen-karyawan", [
            'jenis_dokumen' => 'SIM', 'nomor' => '1212031601950001', 'berlaku_sampai' => '2031-07-08',
        ])->assertStatus(201)->assertJsonPath('data.id_karyawan', null)->assertJsonPath('data.id_supir', $idSupir)
            ->json('data.id_dokumen_karyawan');
        $this->assertSame('2031-07-08', $tgl());

        $this->putJson("/api/supir/{$idSupir}/dokumen-karyawan/{$idSim}", ['jenis_dokumen' => 'SIM', 'berlaku_sampai' => '2032-01-01'])
            ->assertStatus(200);
        $this->assertSame('2032-01-01', $tgl());
        $this->putJson("/api/supir/{$idSupir}", ['tgl_kadaluarsa_sim' => '2026-01-01'])->assertStatus(200);
        $this->assertSame('2032-01-01', $tgl());

        $idKtp = $this->postJson("/api/supir/{$idSupir}/dokumen-karyawan", ['jenis_dokumen' => 'KTP'])->assertStatus(201)->json('data.id_dokumen_karyawan');
        $this->getJson("/api/supir/{$idSupir}/dokumen-karyawan")->assertStatus(200)->assertJsonCount(2, 'data');

        $idKaryawan = $this->karyawan();
        $this->putJson("/api/supir/{$idSupir}", ['id_karyawan' => $idKaryawan])->assertStatus(200);
        $this->assertSame(2, DB::table('dokumen_karyawan')->where('id_karyawan', $idKaryawan)->whereIn('id_dokumen_karyawan', [$idSim, $idKtp])->count());
        $this->getJson("/api/supir/{$idSupir}/dokumen-karyawan")->assertStatus(200)->assertJsonCount(2, 'data');
        $this->assertSame('2032-01-01', $tgl());

        $this->actingAsRole('SUPERADMIN');
        $this->getJson("/api/karyawan/{$idKaryawan}/dokumen")->assertStatus(200)->assertJsonCount(2, 'data');
    }

    public function test_aplikasi_supir_menampilkan_dokumen_supir_tanpa_karyawan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idSupir = $this->supir(null);
        $this->postJson("/api/supir/{$idSupir}/dokumen-karyawan", ['jenis_dokumen' => 'SIM', 'berlaku_sampai' => '2031-07-08'])->assertStatus(201);

        $this->getJson("/api/supir/{$idSupir}/dokumen")->assertStatus(200)
            ->assertJsonPath('data.terhubung_karyawan', true)
            ->assertJsonCount(1, 'data.dokumen')
            ->assertJsonPath('data.dokumen.0.jenis_dokumen', 'SIM');

        $kosong = $this->supir(null);
        $this->getJson("/api/supir/{$kosong}/dokumen")->assertStatus(200)
            ->assertJsonPath('data.terhubung_karyawan', false)
            ->assertJsonCount(0, 'data.dokumen');
    }

    public function test_nomor_ktp_karyawan_dan_dokumen_ktp_selalu_sama(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $nik = '3201234567890001';
        $denganNik = $this->karyawan();
        DB::table('karyawan')->where('id_karyawan', $denganNik)->update(['nik_ktp' => $nik]);
        $idSupir = $this->supir($denganNik);

        $idKtp = $this->postJson("/api/supir/{$idSupir}/dokumen-karyawan", ['jenis_dokumen' => 'KTP'])
            ->assertStatus(201)->assertJsonPath('data.nomor', $nik)->json('data.id_dokumen_karyawan');
        $this->getJson("/api/supir/{$idSupir}")->assertStatus(200)
            ->assertJsonPath('data.no_ktp', $nik)
            ->assertJsonPath('data.nik_ktp_karyawan', $nik);

        $this->putJson("/api/karyawan/{$denganNik}", ['nik_ktp' => '3201234567899999'])->assertStatus(200);
        $this->assertSame('3201234567899999', DB::table('dokumen_karyawan')->where('id_dokumen_karyawan', $idKtp)->value('nomor'));

        $tanpaNik = $this->karyawan();
        $this->postJson("/api/karyawan/{$tanpaNik}/dokumen", ['jenis_dokumen' => 'KTP', 'nomor' => ' 3175000000000002 '])->assertStatus(201);
        $this->assertSame('3175000000000002', DB::table('karyawan')->where('id_karyawan', $tanpaNik)->value('nik_ktp'));
    }

    public function test_nomor_ktp_supir_tanpa_karyawan_ikut_mengisi_karyawan_saat_ditautkan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idSupir = $this->supir(null);
        $this->postJson("/api/supir/{$idSupir}/dokumen-karyawan", ['jenis_dokumen' => 'KTP', 'nomor' => '3201999988887777'])->assertStatus(201);
        $this->getJson("/api/supir/{$idSupir}")->assertStatus(200)
            ->assertJsonPath('data.no_ktp', '3201999988887777')
            ->assertJsonPath('data.nik_ktp_karyawan', null);

        $idKaryawan = $this->karyawan();
        $this->putJson("/api/supir/{$idSupir}", ['id_karyawan' => $idKaryawan])->assertStatus(200);
        $this->assertSame('3201999988887777', DB::table('karyawan')->where('id_karyawan', $idKaryawan)->value('nik_ktp'));
        $this->getJson("/api/supir/{$idSupir}")->assertStatus(200)->assertJsonPath('data.nik_ktp_karyawan', '3201999988887777');
    }

    public function test_ubah_no_ktp_di_supir_ikut_mengubah_karyawan_dan_dokumen_ktp(): void
    {
        $this->actingAsRole(self::PERAN);
        $this->izinMenu('/supir', ['lihat', 'ubah']);
        $idKaryawan = $this->karyawan();
        DB::table('karyawan')->where('id_karyawan', $idKaryawan)->update(['nik_ktp' => '3201000000000001']);
        $idSupir = $this->supir($idKaryawan);
        $idKtp = $this->dokumen($idKaryawan);

        $this->putJson("/api/supir/{$idSupir}", ['no_ktp' => ' 3201000000000999 '])->assertStatus(200)
            ->assertJsonPath('data.no_ktp', '3201000000000999')
            ->assertJsonPath('data.nik_ktp_karyawan', '3201000000000999');

        $this->assertSame('3201000000000999', DB::table('karyawan')->where('id_karyawan', $idKaryawan)->value('nik_ktp'));
        $this->assertSame('3201000000000999', DB::table('dokumen_karyawan')->where('id_dokumen_karyawan', $idKtp)->value('nomor'));

        $this->putJson("/api/supir/{$idSupir}", ['nama' => 'Angga A', 'no_ktp' => ''])->assertStatus(200)
            ->assertJsonPath('data.no_ktp', '3201000000000999');
        $this->assertSame('3201000000000999', DB::table('karyawan')->where('id_karyawan', $idKaryawan)->value('nik_ktp'));

        $this->putJson("/api/supir/{$idSupir}", ['no_ktp' => str_repeat('1', 21)])->assertStatus(422);
    }

    public function test_ubah_nomor_dokumen_ktp_ikut_mengubah_nik_ktp_karyawan(): void
    {
        $this->actingAsRole(self::PERAN);
        $this->izinMenu('/supir', ['lihat', 'tambah', 'ubah']);
        $idKaryawan = $this->karyawan();
        DB::table('karyawan')->where('id_karyawan', $idKaryawan)->update(['nik_ktp' => '3201000000000001']);
        $idSupir = $this->supir($idKaryawan);
        $ktpLama = $this->dokumen($idKaryawan);
        DB::table('dokumen_karyawan')->where('id_dokumen_karyawan', $ktpLama)->update(['nomor' => '3201000000000001']);
        $sim = $this->postJson("/api/supir/{$idSupir}/dokumen-karyawan", ['jenis_dokumen' => 'SIM', 'nomor' => 'SIM-777'])
            ->assertStatus(201)->json('data.id_dokumen_karyawan');
        $ktp = $this->postJson("/api/supir/{$idSupir}/dokumen-karyawan", ['jenis_dokumen' => 'KTP', 'nomor' => '3201000000000001'])
            ->assertStatus(201)->json('data.id_dokumen_karyawan');

        $this->putJson("/api/supir/{$idSupir}/dokumen-karyawan/{$ktp}", ['jenis_dokumen' => 'KTP', 'nomor' => '3202172006720001'])
            ->assertStatus(200)->assertJsonPath('data.nomor', '3202172006720001');

        $this->assertSame('3202172006720001', DB::table('karyawan')->where('id_karyawan', $idKaryawan)->value('nik_ktp'));
        $this->assertSame('3202172006720001', DB::table('dokumen_karyawan')->where('id_dokumen_karyawan', $ktpLama)->value('nomor'));
        $this->assertSame('SIM-777', DB::table('dokumen_karyawan')->where('id_dokumen_karyawan', $sim)->value('nomor'));
        $this->getJson("/api/supir/{$idSupir}")->assertJsonPath('data.no_ktp', '3202172006720001');

        $this->putJson("/api/supir/{$idSupir}/dokumen-karyawan/{$ktp}", ['jenis_dokumen' => 'KTP', 'berlaku_sampai' => '2100-06-20'])->assertStatus(200);
        $this->putJson("/api/supir/{$idSupir}/dokumen-karyawan/{$ktp}", ['jenis_dokumen' => 'KTP', 'nomor' => ''])
            ->assertStatus(200)->assertJsonPath('data.nomor', '3202172006720001');
        $this->assertSame('3202172006720001', DB::table('karyawan')->where('id_karyawan', $idKaryawan)->value('nik_ktp'));

        $this->putJson("/api/supir/{$idSupir}/dokumen-karyawan/{$ktp}", ['jenis_dokumen' => 'KTP', 'nomor' => str_repeat('3', 21)])
            ->assertStatus(422)->assertJsonValidationErrors('nomor');
        $this->assertSame('3202172006720001', DB::table('karyawan')->where('id_karyawan', $idKaryawan)->value('nik_ktp'));
    }

    public function test_no_ktp_supir_tanpa_karyawan_ditolak_tanpa_mengubah_data(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idSupir = $this->supir(null);

        $this->putJson("/api/supir/{$idSupir}", ['nama' => 'Nama Baru', 'no_ktp' => '3201000000000002'])
            ->assertStatus(422)->assertJsonValidationErrors('no_ktp');

        $this->assertSame('Angga Alfiana', DB::table('supir')->where('id_supir', $idSupir)->value('nama'));
    }

    public function test_izin_mengikuti_aksi_di_menu_supir(): void
    {
        $this->izinMenu('/supir', ['lihat']);
        $this->actingAsRole(self::PERAN);
        $idSupir = $this->supir($this->karyawan());

        $this->getJson("/api/supir/{$idSupir}/dokumen-karyawan")->assertStatus(200);
        $this->postJson("/api/supir/{$idSupir}/dokumen-karyawan", ['jenis_dokumen' => 'SIM'])->assertStatus(403);
    }
}
