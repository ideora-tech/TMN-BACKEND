<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DokumenSupirSayaTest extends TestCase
{
    use RefreshDatabase;

    private function izinSupir(): void
    {
        $idMenu = DB::table('menu')->where('path', '/supir')->value('id_menu');
        if ($idMenu === null) {
            $idMenu = (string) Str::uuid();
            DB::table('menu')->insert([
                'id_menu' => $idMenu, 'nama_menu' => 'Supir', 'path' => '/supir', 'aktif' => 1, 'dibuat_pada' => now(),
            ]);
        }
        DB::table('izin_peran')->insert([
            'id_izin' => (string) Str::uuid(), 'id_perusahaan' => null, 'kode_peran' => 'SUPIR',
            'id_menu' => $idMenu, 'aksi' => 'lihat', 'diizinkan' => 1, 'dibuat_pada' => now(),
        ]);
    }

    private function loginSupir(bool $denganKaryawan = true, ?string $idArmadaDefault = null): object
    {
        $this->ensurePerusahaan();
        $this->izinSupir();

        $idKaryawan = null;
        if ($denganKaryawan) {
            $idKaryawan = (string) Str::uuid();
            DB::table('karyawan')->insert([
                'id_karyawan' => $idKaryawan, 'id_perusahaan' => self::PERUSAHAAN_ID,
                'nik' => 'NIK-' . Str::random(6), 'nama_karyawan' => 'Budi Supir', 'dibuat_pada' => now(),
            ]);
        }

        $pengguna = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran' => 'SUPIR', 'username' => 'supir_' . Str::random(6),
            'email' => Str::random(6) . '@test.id', 'kata_sandi' => bcrypt('Password123!'), 'aktif' => 1,
        ]);

        $idSupir = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $idSupir, 'id_pengguna' => $pengguna->id_pengguna, 'id_karyawan' => $idKaryawan,
            'id_perusahaan' => self::PERUSAHAAN_ID, 'id_armada_default' => $idArmadaDefault,
            'nama' => 'Budi Supir', 'no_sim' => 'SIM-777', 'jenis_sim' => 'B2', 'tgl_kadaluarsa_sim' => '2030-01-01',
            'status' => 'aktif', 'dibuat_pada' => now(),
        ]);

        Sanctum::actingAs($pengguna, ['*']);
        return (object) ['id_supir' => $idSupir, 'id_karyawan' => $idKaryawan];
    }

    private function dokumenKaryawan(string $idKaryawan, string $jenis, ?string $berlaku, string $dibuat): void
    {
        DB::table('dokumen_karyawan')->insert([
            'id_dokumen_karyawan' => (string) Str::uuid(), 'id_karyawan' => $idKaryawan, 'jenis_dokumen' => $jenis,
            'nomor' => $jenis . '-' . Str::random(4), 'berlaku_sampai' => $berlaku, 'url_file' => 'dokumen/x.pdf',
            'dibuat_pada' => $dibuat,
        ]);
    }

    private function armada(string $nopol, string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada' => $id, 'id_perusahaan' => $idPerusahaan, 'nopol' => $nopol, 'merk' => 'Hino',
            'status' => 'tersedia', 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function dokumenArmada(string $idArmada, string $jenis, string $berlaku, int $aktif, ?string $idSebelumnya = null): string
    {
        $id = (string) Str::uuid();
        DB::table('dokumen_armada')->insert([
            'id_dokumen_armada' => $id, 'id_armada' => $idArmada, 'jenis_dokumen' => $jenis, 'nomor' => 'N-' . $berlaku,
            'berlaku_sampai' => $berlaku, 'url_file' => 'dokumen/a.pdf', 'aktif' => $aktif,
            'id_dokumen_sebelumnya' => $idSebelumnya, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function penugasan(string $idSupir, string $idArmada, string $tanggal): void
    {
        DB::table('penugasan')->insert([
            'id_penugasan' => (string) Str::uuid(), 'id_proyek' => (string) Str::uuid(), 'id_supir' => $idSupir,
            'id_armada' => $idArmada, 'status' => 'aktif', 'tanggal_tugas' => $tanggal, 'dibuat_pada' => now(),
        ]);
    }

    private function cariJenis(array $dokumen, string $jenis): array
    {
        return array_values(array_filter($dokumen, fn ($d) => $d['jenis_dokumen'] === $jenis));
    }

    public function test_dokumen_saya_dikelompokkan_per_jenis_beserta_riwayat(): void
    {
        $ctx = $this->loginSupir();
        $this->dokumenKaryawan($ctx->id_karyawan, 'SIM', '2025-01-01', '2024-01-01 08:00:00');
        $this->dokumenKaryawan($ctx->id_karyawan, 'SIM', '2030-01-01', '2025-01-02 08:00:00');
        $this->dokumenKaryawan($ctx->id_karyawan, 'KTP', null, '2024-01-01 08:00:00');
        $this->dokumenKaryawan($ctx->id_karyawan, 'Sertifikat', '2027-01-01', '2024-02-01 08:00:00');
        $this->dokumenKaryawan($ctx->id_karyawan, 'Sertifikat', '2028-01-01', '2024-03-01 08:00:00');

        $res = $this->getJson('/api/supir/me/dokumen')->assertStatus(200);
        $res->assertJsonPath('data.tipe_supir', 'internal')
            ->assertJsonPath('data.terhubung_karyawan', true)
            ->assertJsonPath('data.sim.no_sim', 'SIM-777')
            ->assertJsonPath('data.sim.jenis_sim', 'B2');

        $dokumen = $res->json('data.dokumen');
        $this->assertCount(4, $dokumen);

        $sim = $this->cariJenis($dokumen, 'SIM');
        $this->assertCount(1, $sim);
        $this->assertSame('2030-01-01', $sim[0]['terbaru']['berlaku_sampai']);
        $this->assertCount(1, $sim[0]['riwayat']);
        $this->assertSame('2025-01-01', $sim[0]['riwayat'][0]['berlaku_sampai']);

        $this->assertCount(2, $this->cariJenis($dokumen, 'Sertifikat'));
        $this->assertCount(0, $this->cariJenis($dokumen, 'KTP')[0]['riwayat']);
    }

    public function test_supir_belum_terhubung_karyawan_dokumen_kosong(): void
    {
        $this->loginSupir(false);

        $this->getJson('/api/supir/me/dokumen')->assertStatus(200)
            ->assertJsonPath('data.terhubung_karyawan', false)
            ->assertJsonCount(0, 'data.dokumen')
            ->assertJsonPath('data.sim.berlaku_sampai', '2030-01-01');
    }

    public function test_unit_saya_berisi_unit_tetap_dan_unit_hari_ini_beserta_riwayat_dokumen(): void
    {
        $unitTetap = $this->armada('B 1000 TT');
        $unitHariIni = $this->armada('B 2000 HI');
        $unitBesok = $this->armada('B 3000 BS');
        $ctx = $this->loginSupir(true, $unitTetap);

        $stnkLama = $this->dokumenArmada($unitTetap, 'STNK', '2025-06-01', 0);
        $this->dokumenArmada($unitTetap, 'STNK', '2026-06-01', 1, $stnkLama);
        $this->dokumenArmada($unitHariIni, 'KIR', '2026-12-01', 1);

        $this->penugasan($ctx->id_supir, $unitHariIni, now()->toDateString());
        $this->penugasan($ctx->id_supir, $unitBesok, now()->addDay()->toDateString());

        $unit = $this->getJson('/api/supir/me/unit')->assertStatus(200)->json('data');
        $this->assertCount(2, $unit);

        $tetap = array_values(array_filter($unit, fn ($u) => $u['nopol'] === 'B 1000 TT'))[0];
        $this->assertSame(['tetap'], $tetap['sumber']);
        $this->assertCount(1, $tetap['dokumen']);
        $this->assertSame('2026-06-01', $tetap['dokumen'][0]['berlaku_sampai']);
        $this->assertCount(1, $tetap['dokumen'][0]['riwayat']);
        $this->assertSame('2025-06-01', $tetap['dokumen'][0]['riwayat'][0]['berlaku_sampai']);

        $hariIni = array_values(array_filter($unit, fn ($u) => $u['nopol'] === 'B 2000 HI'))[0];
        $this->assertSame(['hari_ini'], $hariIni['sumber']);
        $this->assertSame('KIR', $hariIni['dokumen'][0]['jenis_dokumen']);
    }

    public function test_unit_tetap_yang_juga_ditugaskan_hari_ini_tidak_dobel(): void
    {
        $unit = $this->armada('B 1000 TT');
        $ctx = $this->loginSupir(true, $unit);
        $this->penugasan($ctx->id_supir, $unit, now()->toDateString());

        $this->getJson('/api/supir/me/unit')->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sumber', ['tetap', 'hari_ini']);
    }

    public function test_unit_milik_perusahaan_lain_tidak_ditampilkan(): void
    {
        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $unitLain = $this->armada('D 9999 ZZ', $idLain);
        $this->loginSupir(true, $unitLain);

        $this->getJson('/api/supir/me/unit')->assertStatus(200)->assertJsonCount(0, 'data');
    }

    public function test_pengguna_bukan_supir_404(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $this->getJson('/api/supir/me/dokumen')->assertStatus(404);
        $this->getJson('/api/supir/me/unit')->assertStatus(404);
    }
}
