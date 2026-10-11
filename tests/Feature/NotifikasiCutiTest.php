<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotifikasiCutiTest extends TestCase
{
    use RefreshDatabase;

    private function buatPengelolaCuti(): Pengguna
    {
        $idMenu = DB::table('menu')->where('path', '/cuti')->value('id_menu');
        if ($idMenu === null) {
            $idMenu = (string) Str::uuid();
            DB::table('menu')->insert(['id_menu' => $idMenu, 'nama_menu' => 'cuti', 'path' => '/cuti', 'aktif' => 1, 'dibuat_pada' => now()]);
        }
        $sudahAda = DB::table('izin_peran')->where('id_menu', $idMenu)->where('kode_peran', 'HR_TEST')->where('aksi', 'tambah')->exists();
        if (!$sudahAda) {
            DB::table('izin_peran')->insert([
                'id_izin' => (string) Str::uuid(), 'id_perusahaan' => null, 'kode_peran' => 'HR_TEST',
                'id_menu' => $idMenu, 'aksi' => 'tambah', 'diizinkan' => 1, 'dibuat_pada' => now(),
            ]);
        }

        return $this->buatPengguna('HR_TEST');
    }

    private function buatPengguna(string $kodePeran, ?string $idKaryawan = null): Pengguna
    {
        $this->ensurePerusahaan();
        return Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran' => $kodePeran, 'id_karyawan' => $idKaryawan, 'username' => 'u_' . Str::random(8),
            'email' => Str::random(8) . '@test.id', 'kata_sandi' => bcrypt('Password123!'), 'aktif' => 1,
        ]);
    }

    private function makeKaryawan(string $nama): string
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

    private function payload(string $idJenis, array $extra = []): array
    {
        return array_merge([
            'id_jenis_cuti' => $idJenis, 'tanggal_mulai' => '2026-11-10', 'tanggal_selesai' => '2026-11-11', 'alasan' => 'Acara keluarga',
        ], $extra);
    }

    private function notif(string $tipe, string $idPengajuan): Collection
    {
        return DB::table('notifikasi')->where('tipe', $tipe)->where('referensi_id', $idPengajuan)->get();
    }

    public function test_pengajuan_cuti_dari_karyawan_memberi_notifikasi_ke_pengelola_cuti(): void
    {
        $hr = $this->buatPengelolaCuti();
        $idJenis = $this->makeJenisCuti();
        $pemohon = $this->buatPengguna('STAF_TEST', $this->makeKaryawan('Rina Wati'));
        Sanctum::actingAs($pemohon, ['*']);

        $idPengajuan = $this->postJson('/api/pengajuan-cuti/saya', $this->payload($idJenis))->assertStatus(201)->json('data.id_pengajuan');

        $notif = $this->notif('cuti_diajukan', $idPengajuan);
        $milik = $notif->firstWhere('id_pengguna', $hr->id_pengguna);
        $this->assertNotNull($milik);
        $this->assertSame('Pengajuan cuti baru: Rina Wati', $milik->judul);
        $this->assertStringContainsString('Cuti Tahunan', $milik->isi);
        $this->assertStringContainsString('2 hari', $milik->isi);
        $this->assertSame('/cuti', $milik->link);
        $this->assertSame(0, $notif->where('id_pengguna', $pemohon->id_pengguna)->count());
    }

    public function test_pengajuan_yang_diinput_hr_memberi_notifikasi_ke_pengelola_lain_saja(): void
    {
        $hrPenginput = $this->buatPengelolaCuti();
        $hrLain = $this->buatPengelolaCuti();
        $idKaryawan = $this->makeKaryawan('Rina Wati');
        $idJenis = $this->makeJenisCuti();
        Sanctum::actingAs($hrPenginput, ['*']);

        $idPengajuan = $this->postJson('/api/pengajuan-cuti', $this->payload($idJenis, ['id_karyawan' => $idKaryawan]))
            ->assertStatus(201)->json('data.id_pengajuan');

        $notif = $this->notif('cuti_diajukan', $idPengajuan);
        $this->assertSame(1, $notif->where('id_pengguna', $hrLain->id_pengguna)->count());
        $this->assertSame(0, $notif->where('id_pengguna', $hrPenginput->id_pengguna)->count());
    }

    public function test_cuti_disetujui_memberi_notifikasi_ke_akun_karyawan(): void
    {
        $idKaryawan = $this->makeKaryawan('Rina Wati');
        $akun = $this->buatPengguna('STAF_TEST', $idKaryawan);
        $idJenis = $this->makeJenisCuti();
        $this->actingAsRole('SUPERADMIN');
        $idPengajuan = $this->postJson('/api/pengajuan-cuti', $this->payload($idJenis, ['id_karyawan' => $idKaryawan]))
            ->assertStatus(201)->json('data.id_pengajuan');

        $this->postJson("/api/pengajuan-cuti/{$idPengajuan}/setujui")->assertOk();

        $notif = $this->notif('cuti_disetujui', $idPengajuan);
        $this->assertCount(1, $notif);
        $this->assertSame($akun->id_pengguna, $notif->first()->id_pengguna);
        $this->assertSame('Pengajuan cuti Anda disetujui', $notif->first()->judul);
        $this->assertStringContainsString('10/11/2026 s/d 11/11/2026', $notif->first()->isi);
    }

    public function test_cuti_ditolak_memberi_notifikasi_beserta_catatan(): void
    {
        $idKaryawan = $this->makeKaryawan('Rina Wati');
        $akun = $this->buatPengguna('STAF_TEST', $idKaryawan);
        $idJenis = $this->makeJenisCuti();
        $this->actingAsRole('SUPERADMIN');
        $idPengajuan = $this->postJson('/api/pengajuan-cuti', $this->payload($idJenis, ['id_karyawan' => $idKaryawan]))
            ->assertStatus(201)->json('data.id_pengajuan');

        $this->postJson("/api/pengajuan-cuti/{$idPengajuan}/tolak", ['catatan' => 'Bentrok dengan jadwal audit'])->assertOk();

        $notif = $this->notif('cuti_ditolak', $idPengajuan);
        $this->assertCount(1, $notif);
        $this->assertSame($akun->id_pengguna, $notif->first()->id_pengguna);
        $this->assertStringContainsString('Bentrok dengan jadwal audit', $notif->first()->isi);
    }

    public function test_cuti_supir_disetujui_memberi_notifikasi_ke_akun_supir(): void
    {
        $akunSupir = $this->buatPengguna('SUPIR');
        $idSupir = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $idSupir, 'id_pengguna' => $akunSupir->id_pengguna, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => 'Budi Supir', 'no_sim' => 'SIM-' . Str::random(8), 'jenis_sim' => 'B2', 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);
        $idJenis = $this->makeJenisCuti();
        $this->actingAsRole('SUPERADMIN');
        $idPengajuan = $this->postJson('/api/pengajuan-cuti', $this->payload($idJenis, ['id_supir' => $idSupir]))
            ->assertStatus(201)->json('data.id_pengajuan');

        $this->postJson("/api/pengajuan-cuti/{$idPengajuan}/setujui")->assertOk();

        $notif = $this->notif('cuti_disetujui', $idPengajuan);
        $this->assertCount(1, $notif);
        $this->assertSame($akunSupir->id_pengguna, $notif->first()->id_pengguna);
    }
}
