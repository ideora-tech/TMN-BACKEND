<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LogAktivitasApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function buatArmada(string $nopol = 'B 5501 LOG'): string
    {
        $id = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada'     => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => $nopol,
            'merk'          => 'Hino',
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function buatPerawatanBerbiaya(string $idArmada): string
    {
        $res = $this->postJson("/api/v1/armada/{$idArmada}/perawatan", [
            'tanggal'         => '2026-08-10',
            'jenis_perawatan' => 'Ganti Oli',
            'biaya'           => 250000,
            'status'          => 'selesai',
        ]);
        $res->assertStatus(201);
        return $res->json('data.id_perawatan');
    }

    private function pengajuanPerawatan(string $idPerawatan): object
    {
        return DB::table('pengajuan_pengeluaran')->where('id_perawatan', $idPerawatan)->first();
    }

    public function test_info_pengajuan_perawatan_berisi_riwayat(): void
    {
        $pengguna = $this->actingAsRole('SUPERADMIN');
        $idArmada = $this->buatArmada();
        $idPerawatan = $this->buatPerawatanBerbiaya($idArmada);

        $res = $this->getJson("/api/v1/armada/{$idArmada}/perawatan/{$idPerawatan}/pengajuan");
        $res->assertStatus(200);

        $this->assertNotNull($res->json('data.nomor_pengajuan'));
        $this->assertSame('diajukan', $res->json('data.status'));
        $this->assertSame('diajukan', $res->json('data.riwayat.0.status'));
        $this->assertSame($pengguna->username, $res->json('data.riwayat.0.oleh'));
    }

    public function test_info_pengajuan_perawatan_tanpa_pengajuan_mengembalikan_null(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idArmada = $this->buatArmada('B 5502 LOG');

        $res = $this->postJson("/api/v1/armada/{$idArmada}/perawatan", [
            'tanggal'         => '2026-08-10',
            'jenis_perawatan' => 'Cek Rutin',
            'biaya'           => 0,
            'status'          => 'terjadwal',
        ]);
        $res->assertStatus(201);
        $idPerawatan = $res->json('data.id_perawatan');

        $this->getJson("/api/v1/armada/{$idArmada}/perawatan/{$idPerawatan}/pengajuan")
            ->assertStatus(200)
            ->assertJsonPath('data', null);
    }

    public function test_info_pengajuan_periode_payroll_berisi_riwayat(): void
    {
        $pengguna = $this->actingAsRole('SUPERADMIN');
        $this->putJson('/api/v1/payroll/pengaturan', [
            'tanggal_mulai_cutoff'       => 1,
            'hari_kerja_per_bulan'       => 25,
            'persen_bpjs_kesehatan'      => 1,
            'persen_bpjs_jht'            => 2,
            'persen_bpjs_jp'             => 1,
            'plafon_gaji_bpjs_kesehatan' => 12000000,
        ])->assertStatus(200);
        DB::table('karyawan')->insert([
            'id_karyawan' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nik' => 'NIK-LOG-01', 'nama_karyawan' => 'Log Payroll', 'aktif' => 1,
            'gaji_pokok' => 5000000, 'dibuat_pada' => now(),
        ]);
        $idPeriode = $this->postJson('/api/v1/payroll/periode', ['bulan' => '2026-08'])
            ->assertStatus(201)->json('data.id_periode');
        $this->postJson("/api/v1/payroll/periode/{$idPeriode}/generate")->assertStatus(200);

        $this->getJson("/api/v1/payroll/periode/{$idPeriode}/pengajuan")
            ->assertStatus(200)
            ->assertJsonPath('data', null);

        $this->postJson("/api/v1/payroll/periode/{$idPeriode}/finalisasi")->assertStatus(200);

        $res = $this->getJson("/api/v1/payroll/periode/{$idPeriode}/pengajuan");
        $res->assertStatus(200);
        $this->assertSame('diajukan', $res->json('data.riwayat.0.status'));
        $this->assertSame($pengguna->username, $res->json('data.riwayat.0.oleh'));
    }

    public function test_tolak_mencatat_nama_penolak_di_riwayat(): void
    {
        $pengguna = $this->actingAsRole('SUPERADMIN');
        $idArmada = $this->buatArmada('B 5503 LOG');
        $idPerawatan = $this->buatPerawatanBerbiaya($idArmada);
        $idPengajuan = $this->pengajuanPerawatan($idPerawatan)->id_pengajuan;

        $this->patchJson("/api/v1/arus-kas/pengajuan/{$idPengajuan}/tolak", [
            'alasan' => 'Biaya tidak wajar',
        ])->assertStatus(200);

        $res = $this->getJson("/api/v1/armada/{$idArmada}/perawatan/{$idPerawatan}/pengajuan");
        $res->assertStatus(200);

        $entriTolak = collect($res->json('data.riwayat'))->firstWhere('status', 'ditolak');
        $this->assertNotNull($entriTolak);
        $this->assertSame('Biaya tidak wajar', $entriTolak['keterangan']);
        $this->assertSame($pengguna->username, $entriTolak['oleh']);
    }

    public function test_auto_approve_di_bawah_batas_mencatat_pengecek(): void
    {
        $pengguna = $this->actingAsRole('SUPERADMIN');
        $this->putJson('/api/v1/arus-kas/pengaturan-approval', ['batas' => 999999999])->assertStatus(200);
        $idArmada = $this->buatArmada('B 5504 LOG');
        $idPerawatan = $this->buatPerawatanBerbiaya($idArmada);
        $idPengajuan = $this->pengajuanPerawatan($idPerawatan)->id_pengajuan;

        $this->patchJson("/api/v1/arus-kas/pengajuan/{$idPengajuan}/cek")
            ->assertStatus(200)->assertJsonPath('data.status', 'disetujui');

        $res = $this->getJson("/api/v1/armada/{$idArmada}/perawatan/{$idPerawatan}/pengajuan");
        $entriSetuju = collect($res->json('data.riwayat'))->firstWhere('status', 'disetujui');
        $this->assertNotNull($entriSetuju);
        $this->assertSame($pengguna->username, $entriSetuju['oleh']);
    }
}
