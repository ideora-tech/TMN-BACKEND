<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IzinCutiAbsensiTest extends TestCase
{
    use RefreshDatabase;

    private const AKSI = ['lihat', 'tambah', 'ubah', 'hapus'];

    private const MENU = ['/karyawan', '/cuti', '/absensi', '/trip', '/penugasan', '/supir'];

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

    private function hanyaIzin(array $diizinkan): void
    {
        foreach (self::MENU as $path) {
            foreach (self::AKSI as $aksi) {
                $this->setIzin($path, $aksi, in_array($aksi, $diizinkan[$path] ?? [], true) ? 1 : 0);
            }
        }
    }

    private function makeKaryawan(string $nama = 'Staff Uji'): string
    {
        $this->ensurePerusahaan();
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nik' => 'NIK-' . Str::random(8), 'nama_karyawan' => $nama, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeJenisCuti(): string
    {
        $this->ensurePerusahaan();
        $id = (string) Str::uuid();
        DB::table('jenis_cuti')->insert([
            'id_jenis_cuti' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama_jenis' => 'Cuti Tahunan', 'mengurangi_saldo' => 1, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function loginKeuangan(bool $tertautKaryawan = false): ?string
    {
        $user = $this->actingAsRole('KEUANGAN');
        if (!$tertautKaryawan) {
            return null;
        }
        $idKaryawan = $this->makeKaryawan();
        DB::table('pengguna')->where('id_pengguna', $user->id_pengguna)->update(['id_karyawan' => $idKaryawan]);
        Sanctum::actingAs($user->fresh(), ['*']);
        return $idKaryawan;
    }

    public function test_migrasi_memberi_izin_cuti_dan_absensi_bawaan_untuk_manager_dan_admin(): void
    {
        foreach (['/cuti', '/absensi'] as $path) {
            $idMenu = DB::table('menu')->where('path', $path)->value('id_menu');
            $this->assertNotNull($idMenu);

            foreach (['MANAGER', 'ADMIN'] as $kodePeran) {
                $aksi = DB::table('izin_peran')
                    ->where('id_menu', $idMenu)->where('kode_peran', $kodePeran)
                    ->whereNull('id_perusahaan')->where('diizinkan', 1)
                    ->pluck('aksi')->sort()->values()->all();

                $this->assertSame(['hapus', 'lihat', 'tambah', 'ubah'], $aksi, "Izin {$path} bawaan {$kodePeran} tidak lengkap");
            }
        }
    }

    public function test_absen_dan_cuti_milik_sendiri_tidak_butuh_izin_menu_apa_pun(): void
    {
        $this->hanyaIzin([]);
        $idJenis = $this->makeJenisCuti();
        $this->loginKeuangan(true);

        $this->getJson('/api/absensi/saya/hari-ini')->assertStatus(200);
        $this->postJson('/api/absensi/saya/masuk', ['latitude' => -6.2, 'longitude' => 106.8, 'alamat' => 'Kantor'])->assertStatus(200);
        $this->postJson('/api/absensi/saya/pulang', ['latitude' => -6.2, 'longitude' => 106.8, 'alamat' => 'Kantor'])->assertStatus(200);

        $this->getJson('/api/jenis-cuti?limit=999')->assertStatus(200);
        $this->getJson('/api/saldo-cuti/saya')->assertStatus(200);
        $idPengajuan = $this->postJson('/api/pengajuan-cuti/saya', [
            'id_jenis_cuti' => $idJenis, 'tanggal_mulai' => '2026-11-10', 'tanggal_selesai' => '2026-11-11', 'alasan' => 'Acara keluarga',
        ])->assertStatus(201)->json('data.id_pengajuan');
        $this->getJson('/api/pengajuan-cuti/saya')->assertStatus(200);
        $this->postJson("/api/pengajuan-cuti/saya/{$idPengajuan}/batalkan")->assertStatus(200);
    }

    public function test_akun_tanpa_karyawan_tidak_bisa_absen_sendiri(): void
    {
        $this->hanyaIzin([]);
        $this->loginKeuangan();

        $this->postJson('/api/absensi/saya/masuk', ['latitude' => -6.2, 'longitude' => 106.8])->assertStatus(422);
    }

    public function test_tanpa_izin_absensi_dan_cuti_layar_hr_tertutup_walau_punya_izin_karyawan(): void
    {
        $this->hanyaIzin(['/karyawan' => self::AKSI]);
        $idKaryawan = $this->makeKaryawan();
        $idJenis = $this->makeJenisCuti();
        $this->loginKeuangan();

        $this->getJson('/api/absensi/harian?tanggal=2026-10-09')->assertStatus(403);
        $this->postJson('/api/absensi/harian', [])->assertStatus(403);
        $this->getJson('/api/absensi/rekap?bulan=2026-10')->assertStatus(403);
        $this->getJson('/api/absensi/rekap/export/excel?bulan=2026-10')->assertStatus(403);
        $this->getJson('/api/absensi/pengaturan')->assertStatus(403);
        $this->putJson('/api/absensi/pengaturan', [])->assertStatus(403);

        $this->getJson('/api/pengajuan-cuti')->assertStatus(403);
        $this->getJson('/api/pengajuan-cuti/opsi-pemohon')->assertStatus(403);
        $this->postJson('/api/pengajuan-cuti', ['id_karyawan' => $idKaryawan, 'id_jenis_cuti' => $idJenis])->assertStatus(403);
        $this->getJson("/api/saldo-cuti?id_karyawan={$idKaryawan}")->assertStatus(403);
        $this->getJson('/api/saldo-cuti/rekap')->assertStatus(403);
        $this->postJson('/api/saldo-cuti/penyesuaian', [])->assertStatus(403);
        $this->postJson('/api/jenis-cuti', ['nama_jenis' => 'Cuti Baru'])->assertStatus(403);
        $this->putJson("/api/jenis-cuti/{$idJenis}", ['nama_jenis' => 'Ubah'])->assertStatus(403);
        $this->deleteJson("/api/jenis-cuti/{$idJenis}")->assertStatus(403);
    }

    public function test_izin_absensi_membuka_layar_absensi_tanpa_izin_karyawan(): void
    {
        $this->hanyaIzin(['/absensi' => self::AKSI]);
        $this->makeKaryawan();
        $this->loginKeuangan();

        $this->putJson('/api/absensi/pengaturan', [
            'jam_masuk' => '08:00', 'jam_pulang' => '17:00', 'toleransi_terlambat_menit' => 15,
        ])->assertStatus(200);
        $this->getJson('/api/absensi/pengaturan')->assertStatus(200);
        $this->getJson('/api/absensi/harian?tanggal=2026-10-09')->assertStatus(200);
        $this->getJson('/api/absensi/rekap?bulan=2026-10')->assertStatus(200);

        $this->getJson('/api/pengajuan-cuti')->assertStatus(403);
        $this->getJson('/api/karyawan')->assertStatus(403);
    }

    public function test_izin_cuti_membuka_layar_cuti_tanpa_izin_karyawan(): void
    {
        $this->hanyaIzin(['/cuti' => self::AKSI]);
        $idKaryawan = $this->makeKaryawan('Rina');
        $idJenis = $this->makeJenisCuti();
        $this->loginKeuangan();

        $opsi = $this->getJson('/api/pengajuan-cuti/opsi-pemohon')->assertStatus(200)->json('data');
        $this->assertSame([$idKaryawan], array_column($opsi['karyawan'], 'id_karyawan'));
        $this->assertSame(['id_karyawan', 'nik', 'nama_karyawan'], array_keys($opsi['karyawan'][0]));
        $this->assertSame([], $opsi['supir']);

        $this->getJson('/api/pengajuan-cuti')->assertStatus(200);
        $this->getJson('/api/saldo-cuti/rekap')->assertStatus(200);
        $this->getJson("/api/saldo-cuti?id_karyawan={$idKaryawan}")->assertStatus(200);
        $idPengajuan = $this->postJson('/api/pengajuan-cuti', [
            'id_karyawan' => $idKaryawan, 'id_jenis_cuti' => $idJenis,
            'tanggal_mulai' => '2026-11-10', 'tanggal_selesai' => '2026-11-11', 'alasan' => 'Acara keluarga',
        ])->assertStatus(201)->json('data.id_pengajuan');
        $this->postJson("/api/pengajuan-cuti/{$idPengajuan}/setujui")->assertStatus(200);
        $this->postJson('/api/jenis-cuti', ['nama_jenis' => 'Cuti Khusus'])->assertStatus(201);

        $this->getJson('/api/absensi/harian?tanggal=2026-10-09')->assertStatus(403);
        $this->getJson('/api/karyawan')->assertStatus(403);
    }

    public function test_aksi_cuti_mengikuti_centang_masing_masing(): void
    {
        $this->hanyaIzin(['/cuti' => ['lihat']]);
        $this->loginKeuangan();

        $this->getJson('/api/pengajuan-cuti')->assertStatus(200);
        $this->postJson('/api/pengajuan-cuti', [])->assertStatus(403);
        $this->postJson('/api/jenis-cuti', ['nama_jenis' => 'Cuti Khusus'])->assertStatus(403);
    }

    public function test_daftar_orang_cuti_hari_ini_terbaca_dari_izin_trip_penugasan_karyawan_atau_cuti(): void
    {
        foreach (['/trip', '/penugasan', '/karyawan', '/cuti'] as $path) {
            $this->hanyaIzin([$path => ['lihat']]);
            $this->loginKeuangan();

            $this->getJson('/api/pengajuan-cuti/aktif')->assertStatus(200);
        }

        $this->hanyaIzin(['/absensi' => self::AKSI, '/supir' => self::AKSI]);
        $this->loginKeuangan();

        $this->getJson('/api/pengajuan-cuti/aktif')->assertStatus(403);
    }
}
