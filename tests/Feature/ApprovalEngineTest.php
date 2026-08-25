<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApprovalEngineTest extends TestCase
{
    use RefreshDatabase;

    private function makeJabatan(string $nama, ?string $idJabatanInduk = null): string
    {
        $id = (string) Str::uuid();
        DB::table('jabatan')->insert([
            'id_jabatan'       => $id,
            'id_perusahaan'    => self::PERUSAHAAN_ID,
            'id_jabatan_induk' => $idJabatanInduk,
            'kode_jabatan'     => 'JBT-' . Str::random(6),
            'nama_jabatan'     => $nama,
            'aktif'            => 1,
            'dibuat_pada'      => now(),
        ]);
        return $id;
    }

    private function makePenggunaDenganJabatan(string $idJabatan, string $nama = 'Test User'): Pengguna
    {
        $idKaryawan = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan'   => $idKaryawan,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_jabatan'    => $idJabatan,
            'nik'           => 'NIK-' . Str::random(8),
            'nama_karyawan' => $nama,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);

        return Pengguna::create([
            'id_pengguna'   => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_karyawan'   => $idKaryawan,
            'kode_peran'    => 'KARYAWAN',
            'username'      => 'test_' . Str::random(8),
            'email'         => Str::random(8) . '@test.id',
            'kata_sandi'    => bcrypt('Password123!'),
            'aktif'         => 1,
        ]);
    }

    private function makeEventType(string $modeResolusi, string $kode = 'test_dummy'): string
    {
        $id = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode'          => $kode,
            'nama'          => 'Test Dummy Event',
            'mode_resolusi' => $modeResolusi,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    public function test_admin_bisa_buat_dan_daftar_event_type(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/approval-event-type', [
            'kode'          => 'test_dummy',
            'nama'          => 'Test Dummy Event',
            'mode_resolusi' => 'pinned',
        ]);
        $res->assertStatus(201)
            ->assertJsonPath('data.kode', 'test_dummy')
            ->assertJsonPath('data.mode_resolusi', 'pinned');

        $idEventType = $res->json('data.id_event_type');

        $list = $this->getJson('/api/approval-event-type');
        $list->assertStatus(200);
        $this->assertTrue(collect($list->json('data'))->contains('id_event_type', $idEventType));
    }

    public function test_admin_bisa_tambah_dan_hapus_config_approver(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idEventType = $this->makeEventType('pinned');
        $idJabatan   = $this->makeJabatan('Direktur');

        $res = $this->postJson("/api/approval-event-type/{$idEventType}/approver", [
            'tipe'       => 'jabatan',
            'id_jabatan' => $idJabatan,
        ]);
        $res->assertStatus(201);
        $idConfig = $res->json('data.id_config');

        $this->assertDatabaseHas('approval_config_approver', [
            'id_config'      => $idConfig,
            'id_event_type'  => $idEventType,
            'tipe'           => 'jabatan',
        ]);

        $this->deleteJson("/api/approval-event-type/{$idEventType}/approver/{$idConfig}")
            ->assertStatus(200);
        $this->assertDatabaseMissing('approval_config_approver', [
            'id_config'    => $idConfig,
            'dihapus_pada' => null,
        ]);
    }

    public function test_non_admin_tidak_bisa_buat_event_type(): void
    {
        $this->actingAsRole('DISPATCHER');

        $this->postJson('/api/approval-event-type', [
            'kode'          => 'test_dummy',
            'nama'          => 'Test Dummy Event',
            'mode_resolusi' => 'pinned',
        ])->assertStatus(403);
    }

    public function test_ajukan_pinned_tipe_jabatan_menghasilkan_baris_keputusan_untuk_semua_pemegang(): void
    {
        $idEventType = $this->makeEventType('pinned');
        $idJabatan   = $this->makeJabatan('Direktur Keuangan');
        $approver1   = $this->makePenggunaDenganJabatan($idJabatan, 'Direktur A');
        $approver2   = $this->makePenggunaDenganJabatan($idJabatan, 'Direktur B');
        DB::table('approval_config_approver')->insert([
            'id_config'     => (string) Str::uuid(),
            'id_event_type' => $idEventType,
            'tipe'          => 'jabatan',
            'id_jabatan'    => $idJabatan,
            'dibuat_pada'   => now(),
        ]);
        $pengaju = $this->actingAsRole('SALES');

        $service = app(\App\Modules\Approval\ApprovalService::class);
        $pengajuan = $service->ajukan('test_dummy', (string) Str::uuid(), $pengaju->id_pengguna, 500000.0, self::PERUSAHAAN_ID);

        $this->assertSame('menunggu', $pengajuan->status);
        $this->assertSame(2, DB::table('approval_keputusan')->where('id_approval', $pengajuan->id_approval)->count());
        $ids = DB::table('approval_keputusan')->where('id_approval', $pengajuan->id_approval)->pluck('id_pengguna')->all();
        $this->assertEqualsCanonicalizing([$approver1->id_pengguna, $approver2->id_pengguna], $ids);

        $this->assertSame(2, DB::table('notifikasi')
            ->where('referensi_id', $pengajuan->id_approval)
            ->where('referensi_tipe', 'approval_pengajuan')
            ->whereIn('id_pengguna', [$approver1->id_pengguna, $approver2->id_pengguna])
            ->count());
    }

    public function test_ajukan_pinned_tanpa_config_approver_ditolak_422(): void
    {
        $this->makeEventType('pinned', 'test_dummy_kosong');
        $pengaju = $this->actingAsRole('SALES');

        $service = app(\App\Modules\Approval\ApprovalService::class);

        try {
            $service->ajukan('test_dummy_kosong', (string) Str::uuid(), $pengaju->id_pengguna, null, self::PERUSAHAAN_ID);
            $this->fail('Expected HttpException dengan status 422');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_ajukan_relatif_lompat_ke_atasan_berikutnya_kalau_kosong(): void
    {
        $idEventType = $this->makeEventType('relatif', 'test_dummy_relatif');
        $idDireksi   = $this->makeJabatan('Direktur Utama');
        $idManagerKosong = $this->makeJabatan('Manager Operasional', $idDireksi); // tidak ada pemegangnya
        $idStaff     = $this->makeJabatan('Staff Operasional', $idManagerKosong);
        $direktur    = $this->makePenggunaDenganJabatan($idDireksi, 'Pak Direktur');
        $pengaju     = $this->makePenggunaDenganJabatan($idStaff, 'Staff Pengaju');

        $service = app(\App\Modules\Approval\ApprovalService::class);
        $pengajuan = $service->ajukan('test_dummy_relatif', (string) Str::uuid(), $pengaju->id_pengguna, null, self::PERUSAHAAN_ID);

        $ids = DB::table('approval_keputusan')->where('id_approval', $pengajuan->id_approval)->pluck('id_pengguna')->all();
        $this->assertEqualsCanonicalizing([$direktur->id_pengguna], $ids);
    }

    public function test_ajukan_relatif_rantai_habis_tanpa_hasil_ditolak_422_pesan_struktur_organisasi(): void
    {
        $this->makeEventType('relatif', 'test_dummy_relatif_kosong');
        $idJabatanRoot = $this->makeJabatan('Jabatan Tanpa Atasan');
        $pengaju = $this->makePenggunaDenganJabatan($idJabatanRoot, 'Pengaju Sendirian');

        $service = app(\App\Modules\Approval\ApprovalService::class);

        try {
            $service->ajukan('test_dummy_relatif_kosong', (string) Str::uuid(), $pengaju->id_pengguna, null, self::PERUSAHAAN_ID);
            $this->fail('Seharusnya melempar HttpException 422');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('struktur organisasi', $e->getMessage());
        }

        $this->assertSame(0, DB::table('approval_pengajuan')->count());
    }

    public function test_ajukan_relatif_pengaju_tanpa_karyawan_ditolak_422(): void
    {
        $this->makeEventType('relatif', 'test_dummy_relatif_tanpa_karyawan');
        $pengaju = $this->actingAsRole('SUPERADMIN'); // dibuat via actingAsRole, tidak ada id_karyawan

        $service = app(\App\Modules\Approval\ApprovalService::class);

        try {
            $service->ajukan('test_dummy_relatif_tanpa_karyawan', (string) Str::uuid(), $pengaju->id_pengguna, null, self::PERUSAHAAN_ID);
            $this->fail('Seharusnya melempar HttpException 422');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }
}
