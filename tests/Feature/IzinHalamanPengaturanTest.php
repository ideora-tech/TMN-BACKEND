<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class IzinHalamanPengaturanTest extends TestCase
{
    use RefreshDatabase;

    private const PERAN = 'OPS_PENGATURAN';
    private const PERUSAHAAN_LAIN = 'b8f3c1a2-0000-4000-8000-000000000099';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePerusahaan();
        DB::table('perusahaan')->insertOrIgnore(['id_perusahaan' => self::PERUSAHAAN_LAIN, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
    }

    private function idMenu(string $path): string
    {
        $id = DB::table('menu')->where('path', $path)->whereNull('dihapus_pada')->value('id_menu');
        if ($id !== null) {
            return (string) $id;
        }

        $id = (string) Str::uuid();
        DB::table('menu')->insert(['id_menu' => $id, 'nama_menu' => ltrim($path, '/'), 'path' => $path, 'aktif' => 1, 'dibuat_pada' => now()]);

        return $id;
    }

    private function izin(string $path, array $aksi, string $kodePeran = self::PERAN): void
    {
        foreach ($aksi as $a) {
            DB::table('izin_peran')->insert([
                'id_izin' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => $kodePeran,
                'id_menu' => $this->idMenu($path), 'aksi' => $a, 'diizinkan' => 1, 'dibuat_pada' => now(),
            ]);
        }
    }

    private function penggunaLain(string $kodePeran): string
    {
        $id = (string) Str::uuid();
        Pengguna::create([
            'id_pengguna' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => $kodePeran,
            'username' => 'lain_' . Str::random(6), 'email' => Str::random(6) . '@lain.id',
            'kata_sandi' => bcrypt('Password123!'), 'aktif' => 1,
        ]);

        return $id;
    }

    private function peran(string $kode, ?string $idPerusahaan, bool $platform = false): string
    {
        $id = (string) Str::uuid();
        DB::table('peran')->insert([
            'id_peran' => $id, 'id_perusahaan' => $idPerusahaan, 'kode_peran' => $kode, 'nama_peran' => $kode,
            'is_platform' => $platform ? 1 : 0, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        return $id;
    }

    public function test_halaman_pengaturan_mengikuti_ceklis_bukan_kode_peran(): void
    {
        $this->actingAsRole(self::PERAN);

        $this->getJson('/api/pengguna')->assertStatus(403);
        $this->getJson('/api/peran')->assertStatus(403);
        $this->getJson('/api/izin-peran?kode_peran=SALES')->assertStatus(403);
        $this->getJson('/api/perusahaan')->assertStatus(403);
        $this->getJson('/api/menu')->assertStatus(403);
        $this->getJson('/api/pengaturan-kode')->assertStatus(403);
        $this->getJson('/api/approval-event-type')->assertStatus(403);
        $this->getJson('/api/arus-kas/pengaturan-approval')->assertStatus(403);

        $this->izin('/pengguna', ['lihat']);
        $this->izin('/peran', ['lihat']);
        $this->izin('/perusahaan', ['lihat']);
        $this->izin('/menu-admin', ['lihat']);
        $this->izin('/format-kode', ['lihat']);
        $this->izin('/konfigurasi-approval', ['lihat']);

        $this->getJson('/api/pengguna')->assertStatus(200);
        $this->getJson('/api/peran')->assertStatus(200);
        $this->getJson('/api/izin-peran?kode_peran=SALES')->assertStatus(200);
        $this->getJson('/api/perusahaan')->assertStatus(200);
        $this->getJson('/api/menu')->assertStatus(200);
        $this->getJson('/api/pengaturan-kode')->assertStatus(200);
        $this->getJson('/api/approval-event-type')->assertStatus(200);
        $this->getJson('/api/arus-kas/pengaturan-approval')->assertStatus(200);

        $this->putJson('/api/pengaturan-kode/rute', ['prefix' => 'RX', 'panjang_digit' => 4, 'reset' => 'tidak'])->assertStatus(403);
        $this->izin('/format-kode', ['ubah']);
        $this->putJson('/api/pengaturan-kode/rute', ['prefix' => 'RX', 'panjang_digit' => 4, 'reset' => 'tidak'])->assertStatus(200);
    }

    public function test_dropdown_halaman_lain_ikut_izin_halaman_pemakainya(): void
    {
        $this->actingAsRole(self::PERAN);
        $this->izin('/konfigurasi-approval', ['lihat']);
        $this->getJson('/api/pengguna')->assertStatus(200);
        $this->getJson('/api/jabatan')->assertStatus(200);
        $this->getJson('/api/pengguna/' . Str::uuid())->assertStatus(403);

        $this->izin('/pengguna', ['lihat']);
        $this->getJson('/api/peran')->assertStatus(200);
        $this->postJson('/api/peran', ['kode_peran' => 'X', 'nama_peran' => 'X'])->assertStatus(403);
    }

    public function test_akun_superadmin_hanya_bisa_dikelola_superadmin(): void
    {
        $this->actingAsRole(self::PERAN);
        $this->izin('/pengguna', ['lihat', 'tambah', 'ubah', 'hapus']);
        $this->peran('STAFF', self::PERUSAHAAN_ID);
        $superadmin = $this->penggunaLain('SUPERADMIN');
        $staf = $this->penggunaLain('STAFF');

        $this->postJson('/api/pengguna', [
            'kode_peran' => 'SUPERADMIN', 'username' => 'naik_pangkat', 'email' => 'naik@x.id', 'password' => 'Rahasia123',
        ])->assertStatus(403);
        $this->putJson("/api/pengguna/{$superadmin}", ['password' => 'DiambilAlih1'])->assertStatus(403);
        $this->putJson("/api/pengguna/{$staf}", ['kode_peran' => 'SUPERADMIN'])->assertStatus(403);
        $this->deleteJson("/api/pengguna/{$superadmin}")->assertStatus(403);
        $this->putJson("/api/pengguna/{$superadmin}/change-password", ['password_lama' => 'Password123!', 'password_baru' => 'DiambilAlih1', 'password_baru_confirmation' => 'DiambilAlih1'])
            ->assertStatus(403);

        $this->assertSame('SUPERADMIN', DB::table('pengguna')->where('id_pengguna', $superadmin)->value('kode_peran'));
        $this->assertNull(DB::table('pengguna')->where('id_pengguna', $superadmin)->value('dihapus_pada'));
        $this->assertSame('STAFF', DB::table('pengguna')->where('id_pengguna', $staf)->value('kode_peran'));

        $this->postJson('/api/pengguna', [
            'kode_peran' => 'STAFF', 'username' => 'staf_baru', 'email' => 'staf@x.id', 'password' => 'Rahasia123',
        ])->assertStatus(201);
        $this->putJson("/api/pengguna/{$staf}", ['aktif' => false])->assertStatus(200);
    }

    public function test_kode_peran_beraksen_dan_karyawan_perusahaan_lain_ditolak(): void
    {
        $pelaku = $this->actingAsRole(self::PERAN);
        $this->izin('/pengguna', ['lihat', 'tambah', 'ubah']);
        $this->peran('SUPERADMIN', null, true);
        $this->peran(self::PERAN, self::PERUSAHAAN_ID);

        $this->postJson('/api/pengguna', [
            'kode_peran' => 'SÚPERADMIN', 'username' => 'aksen', 'email' => 'aksen@x.id', 'password' => 'Rahasia123',
        ])->assertStatus(422);
        $this->putJson("/api/pengguna/{$pelaku->id_pengguna}", ['kode_peran' => 'SÚPERADMIN'])->assertStatus(422);
        $this->putJson("/api/pengguna/{$pelaku->id_pengguna}", ['kode_peran' => 'TIDAK_ADA'])->assertStatus(422);
        $this->assertSame(self::PERAN, DB::table('pengguna')->where('id_pengguna', $pelaku->id_pengguna)->value('kode_peran'));

        $karyawanLain = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $karyawanLain, 'id_perusahaan' => self::PERUSAHAAN_LAIN, 'nik' => 'LAIN-1',
            'nama_karyawan' => 'Orang Lain', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $this->putJson("/api/pengguna/{$pelaku->id_pengguna}", ['id_karyawan' => $karyawanLain])->assertStatus(422);
        $this->postJson('/api/pengguna', [
            'kode_peran' => self::PERAN, 'username' => 'tautan_lain', 'email' => 'tautan@x.id', 'password' => 'Rahasia123',
            'id_karyawan' => $karyawanLain,
        ])->assertStatus(422);
        $this->assertNull(DB::table('pengguna')->where('id_pengguna', $pelaku->id_pengguna)->value('id_karyawan'));

        $this->izin('/peran', ['tambah', 'ubah']);
        $this->postJson('/api/peran', ['kode_peran' => 'SÚPERADMIN', 'nama_peran' => 'Aksen'])->assertStatus(422);
        $this->postJson('/api/izin-peran/bulk', [
            'kode_peran' => 'SÚPERADMIN', 'permissions' => [['id_menu' => $this->idMenu('/supir'), 'aksi' => 'lihat', 'diizinkan' => true]],
        ])->assertStatus(422);
    }

    public function test_superadmin_tetap_bisa_mengelola_akun_superadmin(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $superadmin = $this->penggunaLain('SUPERADMIN');

        $this->putJson("/api/pengguna/{$superadmin}", ['aktif' => false])->assertStatus(200);
    }

    public function test_peran_platform_dan_milik_perusahaan_lain_terlindungi(): void
    {
        $this->actingAsRole(self::PERAN);
        $this->izin('/peran', ['lihat', 'tambah', 'ubah', 'hapus']);
        $platform = $this->peran('SUPERADMIN', null, true);
        $lain = $this->peran('LAIN', self::PERUSAHAAN_LAIN);
        $milik = $this->peran('MILIK', self::PERUSAHAAN_ID);

        $kode = collect($this->getJson('/api/peran?limit=100')->assertStatus(200)->json('data'))->pluck('kode_peran');
        $this->assertContains('MILIK', $kode);
        $this->assertNotContains('SUPERADMIN', $kode);
        $this->assertNotContains('LAIN', $kode);

        $this->putJson("/api/peran/{$platform}", ['nama_peran' => 'Ganti'])->assertStatus(403);
        $this->deleteJson("/api/peran/{$platform}")->assertStatus(403);
        $this->getJson("/api/peran/{$lain}")->assertStatus(404);
        $this->putJson("/api/peran/{$lain}", ['nama_peran' => 'Ganti'])->assertStatus(404);
        $this->putJson("/api/peran/{$milik}", ['nama_peran' => 'Milik Baru', 'is_platform' => true])->assertStatus(200);
        $this->assertSame(0, (int) DB::table('peran')->where('id_peran', $milik)->value('is_platform'));

        $this->postJson('/api/peran', ['kode_peran' => 'SUPERADMIN', 'nama_peran' => 'Palsu'])->assertStatus(403);
        $idBaru = $this->postJson('/api/peran', ['kode_peran' => 'GUDANG', 'nama_peran' => 'Gudang', 'is_platform' => true])
            ->assertStatus(201)->json('data.id_peran');
        $this->assertSame(0, (int) DB::table('peran')->where('id_peran', $idBaru)->value('is_platform'));
        $this->assertSame(self::PERUSAHAAN_ID, DB::table('peran')->where('id_peran', $idBaru)->value('id_perusahaan'));
    }

    public function test_simpan_ceklis_peran_butuh_izin_ubah_dan_tidak_boleh_untuk_superadmin(): void
    {
        $this->actingAsRole(self::PERAN);
        $idMenuSupir = $this->idMenu('/supir');
        $bulk = fn (string $kode) => $this->postJson('/api/izin-peran/bulk', [
            'kode_peran' => $kode, 'permissions' => [['id_menu' => $idMenuSupir, 'aksi' => 'lihat', 'diizinkan' => true]],
        ]);

        $this->izin('/peran', ['lihat', 'tambah']);
        $bulk('STAFF')->assertStatus(403);

        $this->izin('/peran', ['ubah']);
        $bulk('STAFF')->assertStatus(200);
        $this->assertSame(1, DB::table('izin_peran')->where('kode_peran', 'STAFF')->where('id_menu', $idMenuSupir)
            ->where('id_perusahaan', self::PERUSAHAAN_ID)->where('diizinkan', 1)->count());

        $bulk('SUPERADMIN')->assertStatus(403);

        $global = (string) Str::uuid();
        DB::table('izin_peran')->insert([
            'id_izin' => $global, 'id_perusahaan' => null, 'kode_peran' => 'STAFF', 'id_menu' => $idMenuSupir,
            'aksi' => 'hapus', 'diizinkan' => 0, 'dibuat_pada' => now(),
        ]);
        $this->putJson("/api/izin-peran/{$global}", ['diizinkan' => true])->assertStatus(404);
        $this->assertSame(0, (int) DB::table('izin_peran')->where('id_izin', $global)->value('diizinkan'));
    }

    public function test_perusahaan_hanya_milik_sendiri_dan_tambah_hapus_khusus_superadmin(): void
    {
        $this->actingAsRole(self::PERAN);
        $this->izin('/perusahaan', ['lihat', 'tambah', 'ubah', 'hapus']);

        $this->getJson('/api/perusahaan')->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id_perusahaan', self::PERUSAHAAN_ID);
        $this->getJson('/api/perusahaan/' . self::PERUSAHAAN_LAIN)->assertStatus(404);
        $this->putJson('/api/perusahaan/' . self::PERUSAHAAN_LAIN, ['nama' => 'Diambil'])->assertStatus(404);

        $this->putJson('/api/perusahaan/' . self::PERUSAHAAN_ID, ['nama' => 'PT Sulita Baru', 'aktif' => false])->assertStatus(200);
        $this->assertSame('PT Sulita Baru', DB::table('perusahaan')->where('id_perusahaan', self::PERUSAHAAN_ID)->value('nama'));
        $this->assertNotSame(0, (int) (DB::table('perusahaan')->where('id_perusahaan', self::PERUSAHAAN_ID)->value('aktif') ?? 1));

        $this->postJson('/api/perusahaan', ['nama' => 'Tenant Baru'])->assertStatus(403);
        $this->deleteJson('/api/perusahaan/' . self::PERUSAHAAN_ID)->assertStatus(403);
    }

    public function test_menu_bisa_dibaca_peran_akses_tapi_struktur_menu_khusus_superadmin(): void
    {
        $this->actingAsRole(self::PERAN);
        $this->izin('/peran', ['lihat', 'ubah']);
        $idTrip = $this->idMenu('/trip');

        $this->getJson('/api/menu')->assertStatus(200);
        $this->putJson("/api/menu/{$idTrip}", ['nama_menu' => 'Perjalanan'])->assertStatus(403);

        $this->izin('/menu-admin', ['lihat', 'tambah', 'ubah', 'hapus']);
        $this->putJson("/api/menu/{$idTrip}", ['nama_menu' => 'Perjalanan', 'path' => '/trip', 'tampil_sidebar' => false])->assertStatus(200);
        $this->assertSame('Perjalanan', DB::table('menu')->where('id_menu', $idTrip)->value('nama_menu'));

        $this->putJson("/api/menu/{$idTrip}", ['path' => '/pengguna'])->assertStatus(403);
        $this->putJson("/api/menu/{$idTrip}", ['id_menu_induk' => $this->idMenu('/grup-lain')])->assertStatus(403);
        $this->postJson('/api/menu', ['nama_menu' => 'Baru', 'path' => '/baru'])->assertStatus(403);
        $this->deleteJson("/api/menu/{$idTrip}")->assertStatus(403);
        $this->assertSame('/trip', DB::table('menu')->where('id_menu', $idTrip)->value('path'));
        $this->assertNull(DB::table('menu')->where('id_menu', $idTrip)->value('dihapus_pada'));
    }

    public function test_dropdown_parameter_bok_ikut_izin_parameter_bok(): void
    {
        $this->actingAsRole(self::PERAN);
        $this->getJson('/api/jenis-bbm')->assertStatus(403);
        $this->getJson('/api/jenis-kendaraan')->assertStatus(403);

        $this->izin('/parameter-bok', ['lihat']);
        $this->getJson('/api/jenis-bbm')->assertStatus(200);
        $this->getJson('/api/jenis-kendaraan')->assertStatus(200);
        $this->postJson('/api/jenis-bbm', ['nama' => 'Solar'])->assertStatus(403);
    }

    public function test_admin_tetap_bisa_membuka_konfigurasi_approval_dan_format_kode(): void
    {
        $this->actingAsRole('ADMIN');

        $this->getJson('/api/pengaturan-kode')->assertStatus(200);
        $this->getJson('/api/approval-event-type')->assertStatus(200);
        $this->getJson('/api/arus-kas/pengaturan-approval')->assertStatus(200);
    }
}
