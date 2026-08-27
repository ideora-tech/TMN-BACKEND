<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApprovalRiwayatExportTest extends TestCase
{
    use RefreshDatabase;

    private function makeJabatan(string $nama): string
    {
        $id = (string) Str::uuid();
        DB::table('jabatan')->insert([
            'id_jabatan'    => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jabatan'  => 'JBT-' . Str::random(6),
            'nama_jabatan'  => $nama,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makePenggunaDenganJabatan(string $idJabatan, string $nama = 'Test User', ?string $idPerusahaan = null): Pengguna
    {
        $idPerusahaan = $idPerusahaan ?? self::PERUSAHAAN_ID;
        $idKaryawan = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan'   => $idKaryawan,
            'id_perusahaan' => $idPerusahaan,
            'id_jabatan'    => $idJabatan,
            'nik'           => 'NIK-' . Str::random(8),
            'nama_karyawan' => $nama,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);

        return Pengguna::create([
            'id_pengguna'   => (string) Str::uuid(),
            'id_perusahaan' => $idPerusahaan,
            'id_karyawan'   => $idKaryawan,
            'kode_peran'    => 'KARYAWAN',
            'username'      => 'test_' . Str::random(8),
            'email'         => Str::random(8) . '@test.id',
            'kata_sandi'    => bcrypt('Password123!'),
            'aktif'         => 1,
        ]);
    }

    private function makeEventType(string $kode, ?string $idPerusahaan = null): string
    {
        $idPerusahaan = $idPerusahaan ?? self::PERUSAHAAN_ID;
        $id = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $id,
            'id_perusahaan' => $idPerusahaan,
            'kode'          => $kode,
            'nama'          => 'Test Riwayat Event',
            'mode_resolusi' => 'pinned',
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    public function test_riwayat_saya_menampilkan_keputusan_dengan_enrichment_dan_status_akhir(): void
    {
        $idEventType = $this->makeEventType('penawaran');
        $idJabatan   = $this->makeJabatan('Approver Riwayat');
        $approver    = $this->makePenggunaDenganJabatan($idJabatan, 'Approver Riwayat');
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);

        $idKlien = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien' => $idKlien, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien' => 'K-RIWAYAT', 'nama_klien' => 'Klien Riwayat', 'dibuat_pada' => now(),
        ]);
        $idPenawaran = (string) Str::uuid();
        DB::table('penawaran')->insert([
            'id_penawaran' => $idPenawaran, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_klien' => $idKlien,
            'nomor_penawaran' => 'PNW-RIWAYAT-1', 'judul' => 'Penawaran Riwayat', 'status' => 'draft',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        $pengaju = $this->actingAsRole('SALES');
        $service = app(\App\Modules\Approval\ApprovalService::class);
        $pengajuan = $service->ajukan('penawaran', $idPenawaran, $pengaju->id_pengguna, 1200000.0, self::PERUSAHAAN_ID);

        \Laravel\Sanctum\Sanctum::actingAs($approver, ['*']);
        $service->putuskan($pengajuan->id_approval, $approver->id_pengguna, 'setuju', 'Sesuai anggaran', self::PERUSAHAAN_ID);

        $res = $this->getJson('/api/approval-pengajuan/riwayat-saya');
        $res->assertStatus(200)
            ->assertJsonPath('data.0.id_approval', $pengajuan->id_approval)
            ->assertJsonPath('data.0.kode_event_type', 'penawaran')
            ->assertJsonPath('data.0.nomor_referensi', 'PNW-RIWAYAT-1')
            ->assertJsonPath('data.0.pihak_referensi', 'Klien Riwayat')
            ->assertJsonPath('data.0.nama_pengaju', $pengaju->username)
            ->assertJsonPath('data.0.keputusan_saya', 'disetujui')
            ->assertJsonPath('data.0.catatan_saya', 'Sesuai anggaran')
            ->assertJsonPath('data.0.status_pengajuan', 'disetujui');
        $this->assertNotNull($res->json('data.0.diputuskan_pada'));
        $this->assertNotNull($res->json('data.0.diajukan_pada'));
    }

    public function test_riwayat_saya_tidak_menampilkan_keputusan_yang_dibatalkan_void(): void
    {
        $idEventType = $this->makeEventType('test_riwayat_void');
        $idJabatan   = $this->makeJabatan('Approver Void');
        $approver    = $this->makePenggunaDenganJabatan($idJabatan, 'Approver Void');
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);

        $pengaju = $this->actingAsRole('SALES');
        $idReferensi = (string) Str::uuid();
        $service = app(\App\Modules\Approval\ApprovalService::class);
        $lama = $service->ajukan('test_riwayat_void', $idReferensi, $pengaju->id_pengguna, 100000.0, self::PERUSAHAAN_ID);

        \Laravel\Sanctum\Sanctum::actingAs($approver, ['*']);
        $service->putuskan($lama->id_approval, $approver->id_pengguna, 'setuju', null, self::PERUSAHAAN_ID);

        $this->getJson('/api/approval-pengajuan/riwayat-saya')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $service->batalkanDanAjukanUlang('test_riwayat_void', $idReferensi, $pengaju->id_pengguna, 200000.0, self::PERUSAHAAN_ID);

        $this->assertNotNull(DB::table('approval_keputusan')
            ->where('id_approval', $lama->id_approval)
            ->where('id_pengguna', $approver->id_pengguna)
            ->value('dihapus_pada'));

        $this->getJson('/api/approval-pengajuan/riwayat-saya')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_riwayat_saya_tenant_lain_tidak_bocor(): void
    {
        $idEventType = $this->makeEventType('test_riwayat_tenant');
        $idJabatan   = $this->makeJabatan('Approver Tenant');
        $approver    = $this->makePenggunaDenganJabatan($idJabatan, 'Approver Tenant');
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);
        $pengaju = $this->actingAsRole('SALES');
        $service = app(\App\Modules\Approval\ApprovalService::class);
        $pengajuan = $service->ajukan('test_riwayat_tenant', (string) Str::uuid(), $pengaju->id_pengguna, 300000.0, self::PERUSAHAAN_ID);

        \Laravel\Sanctum\Sanctum::actingAs($approver, ['*']);
        $service->putuskan($pengajuan->id_approval, $approver->id_pengguna, 'setuju', null, self::PERUSAHAAN_ID);

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $penggunaLain = Pengguna::create([
            'id_pengguna'   => (string) Str::uuid(),
            'id_perusahaan' => $idPerusahaanLain,
            'kode_peran'    => 'MANAGER',
            'username'      => 'lain_' . Str::random(8),
            'email'         => Str::random(8) . '@lain.id',
            'kata_sandi'    => bcrypt('Password123!'),
            'aktif'         => 1,
        ]);

        \Laravel\Sanctum\Sanctum::actingAs($penggunaLain, ['*']);
        $this->getJson('/api/approval-pengajuan/riwayat-saya')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_export_saya_mengembalikan_200_dengan_content_type_xlsx(): void
    {
        $idEventType = $this->makeEventType('test_export_xlsx');
        $idJabatan   = $this->makeJabatan('Approver Export');
        $approver    = $this->makePenggunaDenganJabatan($idJabatan, 'Approver Export');
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);
        $pengaju = $this->actingAsRole('SALES');
        $service = app(\App\Modules\Approval\ApprovalService::class);
        $pengajuanMenunggu = $service->ajukan('test_export_xlsx', (string) Str::uuid(), $pengaju->id_pengguna, 400000.0, self::PERUSAHAAN_ID);
        $pengajuanSelesai = $service->ajukan('test_export_xlsx', (string) Str::uuid(), $pengaju->id_pengguna, 500000.0, self::PERUSAHAAN_ID);

        \Laravel\Sanctum\Sanctum::actingAs($approver, ['*']);
        $service->putuskan($pengajuanSelesai->id_approval, $approver->id_pengguna, 'setuju', null, self::PERUSAHAAN_ID);

        $res = $this->get('/api/approval-pengajuan/export-saya');
        $res->assertStatus(200);
        $res->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
