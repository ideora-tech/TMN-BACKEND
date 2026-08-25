<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CutiSayaTest extends TestCase
{
    use RefreshDatabase;

    private function loginSebagaiKaryawan(): string
    {
        $user = $this->actingAsRole('SUPERADMIN');
        $idKaryawan = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $idKaryawan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nik' => 'NIK-CUTISAYA-' . Str::random(4), 'nama_karyawan' => 'Staff Cuti', 'aktif' => 1,
            'dibuat_pada' => now(),
        ]);
        DB::table('pengguna')->where('id_pengguna', $user->id_pengguna)->update(['id_karyawan' => $idKaryawan]);
        Sanctum::actingAs($user->fresh(), ['*']);
        return $idKaryawan;
    }

    private function makeJenisCuti(): string
    {
        $idJenis = (string) Str::uuid();
        DB::table('jenis_cuti')->insert([
            'id_jenis_cuti' => $idJenis, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama_jenis' => 'Cuti Tahunan', 'mengurangi_saldo' => 1, 'aktif' => 1,
            'dibuat_pada' => now(),
        ]);
        return $idJenis;
    }

    public function test_ajukan_cuti_dan_muncul_di_riwayat(): void
    {
        $this->loginSebagaiKaryawan();
        $idJenis = $this->makeJenisCuti();

        $res = $this->postJson('/api/pengajuan-cuti/saya', [
            'id_jenis_cuti' => $idJenis, 'tanggal_mulai' => '2026-08-10',
            'tanggal_selesai' => '2026-08-12', 'alasan' => 'Acara keluarga',
        ]);
        $res->assertStatus(201)->assertJsonPath('data.jumlah_hari', 3)->assertJsonPath('data.status', 'menunggu');

        $list = $this->getJson('/api/pengajuan-cuti/saya')->assertStatus(200)->json('data');
        $this->assertCount(1, $list);
        $this->assertSame('menunggu', $list[0]['status']);
        $this->assertArrayHasKey('nama_jenis', $list[0]);
    }

    public function test_ajukan_tumpang_tindih_ditolak(): void
    {
        $this->loginSebagaiKaryawan();
        $idJenis = $this->makeJenisCuti();

        $this->postJson('/api/pengajuan-cuti/saya', [
            'id_jenis_cuti' => $idJenis, 'tanggal_mulai' => '2026-08-10',
            'tanggal_selesai' => '2026-08-12', 'alasan' => 'Acara keluarga',
        ])->assertStatus(201);

        $this->postJson('/api/pengajuan-cuti/saya', [
            'id_jenis_cuti' => $idJenis, 'tanggal_mulai' => '2026-08-11',
            'tanggal_selesai' => '2026-08-13', 'alasan' => 'Acara lain',
        ])->assertStatus(422);
    }

    public function test_batalkan_milik_sendiri_yang_menunggu(): void
    {
        $this->loginSebagaiKaryawan();
        $idJenis = $this->makeJenisCuti();

        $id = $this->postJson('/api/pengajuan-cuti/saya', [
            'id_jenis_cuti' => $idJenis, 'tanggal_mulai' => '2026-08-10',
            'tanggal_selesai' => '2026-08-12', 'alasan' => 'Acara keluarga',
        ])->assertStatus(201)->json('data.id_pengajuan');

        $this->postJson("/api/pengajuan-cuti/saya/{$id}/batalkan")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'dibatalkan');

        $this->postJson("/api/pengajuan-cuti/saya/{$id}/batalkan")->assertStatus(422);
    }

    public function test_batalkan_pengajuan_orang_lain_404(): void
    {
        $this->loginSebagaiKaryawan();
        $idJenis = $this->makeJenisCuti();

        $idKaryawanLain = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $idKaryawanLain, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nik' => 'NIK-CUTILAIN-' . Str::random(4), 'nama_karyawan' => 'Staff Lain', 'aktif' => 1,
            'dibuat_pada' => now(),
        ]);

        $idPengajuanLain = (string) Str::uuid();
        DB::table('pengajuan_cuti')->insert([
            'id_pengajuan' => $idPengajuanLain, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_karyawan' => $idKaryawanLain, 'id_jenis_cuti' => $idJenis,
            'tanggal_mulai' => '2026-08-10', 'tanggal_selesai' => '2026-08-12',
            'jumlah_hari' => 3, 'status' => 'menunggu', 'dibuat_pada' => now(),
        ]);

        $this->postJson("/api/pengajuan-cuti/saya/{$idPengajuanLain}/batalkan")->assertStatus(404);
    }

    public function test_saldo_saya_tahun_berjalan(): void
    {
        $idKaryawan = $this->loginSebagaiKaryawan();

        $this->getJson('/api/saldo-cuti/saya')
            ->assertStatus(200)
            ->assertJsonPath('data.jatah', 12)
            ->assertJsonPath('data.sisa', 12);

        $this->postJson('/api/saldo-cuti/penyesuaian', [
            'id_karyawan' => $idKaryawan,
            'tahun'       => (int) now()->format('Y'),
            'jumlah_hari' => -2,
        ])->assertStatus(201);

        $this->getJson('/api/saldo-cuti/saya')
            ->assertStatus(200)
            ->assertJsonPath('data.sisa', 10);
    }

    public function test_akun_tanpa_tautan_karyawan_422(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $this->postJson('/api/pengajuan-cuti/saya', [
            'id_jenis_cuti' => (string) Str::uuid(), 'tanggal_mulai' => '2026-08-10',
            'tanggal_selesai' => '2026-08-12',
        ])->assertStatus(422);
    }

    public function test_update_bersyarat_menolak_status_yang_sudah_berubah(): void
    {
        $this->loginSebagaiKaryawan();
        $idJenis = $this->makeJenisCuti();
        $this->postJson('/api/pengajuan-cuti/saya', [
            'id_jenis_cuti' => $idJenis, 'tanggal_mulai' => '2026-08-20', 'tanggal_selesai' => '2026-08-21',
        ])->assertStatus(201);
        $id = DB::table('pengajuan_cuti')->value('id_pengajuan');

        DB::table('pengajuan_cuti')->where('id_pengajuan', $id)->update(['status' => 'disetujui']);

        $terdampak = app(\App\Modules\Cuti\Contracts\CutiRepositoryInterface::class)
            ->updatePengajuanJikaStatus($id, 'menunggu', ['status' => 'dibatalkan']);

        $this->assertSame(0, $terdampak);
        $this->assertSame('disetujui', DB::table('pengajuan_cuti')->where('id_pengajuan', $id)->value('status'));
    }
}
