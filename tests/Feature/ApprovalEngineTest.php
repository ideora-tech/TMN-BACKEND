<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
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

    public function test_tambah_config_approver_duplikat_ditolak_409(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idEventType = $this->makeEventType('pinned', 'test_dup_approver');
        $idJabatan   = $this->makeJabatan('Direktur Operasional');

        $this->postJson("/api/approval-event-type/{$idEventType}/approver", [
            'tipe'       => 'jabatan',
            'id_jabatan' => $idJabatan,
        ])->assertStatus(201);

        $this->postJson("/api/approval-event-type/{$idEventType}/approver", [
            'tipe'       => 'jabatan',
            'id_jabatan' => $idJabatan,
        ])->assertStatus(409);

        $this->assertSame(1, DB::table('approval_config_approver')
            ->where('id_event_type', $idEventType)
            ->where('id_jabatan', $idJabatan)
            ->whereNull('dihapus_pada')
            ->count());
    }

    public function test_tambah_config_approver_ulang_setelah_dihapus_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idEventType = $this->makeEventType('pinned', 'test_readd_approver');
        $idJabatan   = $this->makeJabatan('Direktur Keuangan');

        $res = $this->postJson("/api/approval-event-type/{$idEventType}/approver", [
            'tipe'       => 'jabatan',
            'id_jabatan' => $idJabatan,
        ]);
        $res->assertStatus(201);
        $idConfig = $res->json('data.id_config');

        $this->deleteJson("/api/approval-event-type/{$idEventType}/approver/{$idConfig}")
            ->assertStatus(200);

        $this->postJson("/api/approval-event-type/{$idEventType}/approver", [
            'tipe'       => 'jabatan',
            'id_jabatan' => $idJabatan,
        ])->assertStatus(201);
    }

    public function test_tambah_config_approver_jabatan_lintas_perusahaan_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idEventType = $this->makeEventType('pinned', 'test_dummy_tenant_jabatan');

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $idJabatanLain = (string) Str::uuid();
        DB::table('jabatan')->insert([
            'id_jabatan'    => $idJabatanLain,
            'id_perusahaan' => $idPerusahaanLain,
            'kode_jabatan'  => 'JBT-LAIN',
            'nama_jabatan'  => 'Direktur Lain',
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);

        $this->postJson("/api/approval-event-type/{$idEventType}/approver", [
            'tipe'       => 'jabatan',
            'id_jabatan' => $idJabatanLain,
        ])->assertStatus(404);

        $this->assertDatabaseMissing('approval_config_approver', [
            'id_event_type' => $idEventType,
            'id_jabatan'    => $idJabatanLain,
        ]);
    }

    public function test_tambah_config_approver_pengguna_lintas_perusahaan_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idEventType = $this->makeEventType('pinned', 'test_dummy_tenant_pengguna');

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $penggunaLain = Pengguna::create([
            'id_pengguna'   => (string) Str::uuid(),
            'id_perusahaan' => $idPerusahaanLain,
            'kode_peran'    => 'KARYAWAN',
            'username'      => 'lain_' . Str::random(8),
            'email'         => Str::random(8) . '@lain.id',
            'kata_sandi'    => bcrypt('Password123!'),
            'aktif'         => 1,
        ]);

        $this->postJson("/api/approval-event-type/{$idEventType}/approver", [
            'tipe'        => 'pengguna',
            'id_pengguna' => $penggunaLain->id_pengguna,
        ])->assertStatus(404);

        $this->assertDatabaseMissing('approval_config_approver', [
            'id_event_type' => $idEventType,
            'id_pengguna'   => $penggunaLain->id_pengguna,
        ]);
    }

    public function test_tambah_config_approver_pengguna_satu_tenant_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idEventType = $this->makeEventType('pinned', 'test_dummy_tenant_ok');
        $idJabatan   = $this->makeJabatan('Direktur Tenant OK');
        $pengguna    = $this->makePenggunaDenganJabatan($idJabatan, 'Pengguna Tenant OK');

        $res = $this->postJson("/api/approval-event-type/{$idEventType}/approver", [
            'tipe'        => 'pengguna',
            'id_pengguna' => $pengguna->id_pengguna,
        ]);
        $res->assertStatus(201);

        $this->assertDatabaseHas('approval_config_approver', [
            'id_event_type' => $idEventType,
            'tipe'          => 'pengguna',
            'id_pengguna'   => $pengguna->id_pengguna,
        ]);
    }

    public function test_list_config_approver_hanya_punya_tenant_dan_nama_terisi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idEventType = $this->makeEventType('pinned', 'test_dummy_list_tenant');
        $idJabatan   = $this->makeJabatan('Direktur List Tenant');
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $idJabatanLain = (string) Str::uuid();
        DB::table('jabatan')->insert([
            'id_jabatan'    => $idJabatanLain,
            'id_perusahaan' => $idPerusahaanLain,
            'kode_jabatan'  => 'JBT-LAIN2',
            'nama_jabatan'  => 'Direktur Lain 2',
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        // Simulasi baris drift data (mis. sisa sebelum fix tenant-check) yang menunjuk jabatan lintas tenant.
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatanLain, 'dibuat_pada' => now(),
        ]);

        $res = $this->getJson("/api/approval-event-type/{$idEventType}/approver");
        $res->assertStatus(200);
        $data = collect($res->json('data'));

        $this->assertCount(2, $data);

        $barisSendiri = $data->firstWhere('id_jabatan', $idJabatan);
        $this->assertSame('Direktur List Tenant', $barisSendiri['nama']);

        $barisLintasTenant = $data->firstWhere('id_jabatan', $idJabatanLain);
        $this->assertNull($barisLintasTenant['nama']);
    }

    public function test_create_event_type_kode_duplikat_ditolak_409(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->postJson('/api/approval-event-type', [
            'kode' => 'test_dup_kode', 'nama' => 'Pertama', 'mode_resolusi' => 'pinned',
        ])->assertStatus(201);

        $res = $this->postJson('/api/approval-event-type', [
            'kode' => 'test_dup_kode', 'nama' => 'Kedua', 'mode_resolusi' => 'pinned',
        ]);

        $res->assertStatus(409);
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

    public function test_admin_bisa_hapus_event_type_tanpa_riwayat_pengajuan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idEventType = $this->makeEventType('pinned', 'test_hapus_kosong');
        $idJabatan   = $this->makeJabatan('Direktur Hapus Kosong');
        $idConfig    = (string) Str::uuid();
        DB::table('approval_config_approver')->insert([
            'id_config' => $idConfig, 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);

        $this->deleteJson("/api/approval-event-type/{$idEventType}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('approval_event_type', [
            'id_event_type' => $idEventType,
            'dihapus_pada'  => null,
        ]);
        $this->assertDatabaseMissing('approval_config_approver', [
            'id_config'    => $idConfig,
            'dihapus_pada' => null,
        ]);
    }

    public function test_hapus_event_type_dengan_riwayat_pengajuan_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idEventType = $this->makeEventType('pinned', 'test_hapus_ada_riwayat');
        $idJabatan   = $this->makeJabatan('Direktur Hapus Riwayat');
        $idConfig    = (string) Str::uuid();
        DB::table('approval_config_approver')->insert([
            'id_config' => $idConfig, 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);
        DB::table('approval_pengajuan')->insert([
            'id_approval'         => (string) Str::uuid(),
            'id_perusahaan'       => self::PERUSAHAAN_ID,
            'id_event_type'       => $idEventType,
            'id_referensi'        => (string) Str::uuid(),
            'id_pengguna_pengaju' => (string) Str::uuid(),
            'status'              => 'dibatalkan',
            'dibuat_pada'         => now(),
        ]);

        $this->deleteJson("/api/approval-event-type/{$idEventType}")
            ->assertStatus(422);

        $this->assertDatabaseHas('approval_event_type', [
            'id_event_type' => $idEventType,
            'dihapus_pada'  => null,
        ]);
        $this->assertDatabaseHas('approval_config_approver', [
            'id_config'    => $idConfig,
            'dihapus_pada' => null,
        ]);
    }

    public function test_reaktivasi_event_type_bentrok_kode_dengan_yang_masih_aktif_409(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idAktif = $this->makeEventType('pinned', 'test_kode_bentrok');

        $idNonaktif = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $idNonaktif,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode'          => 'test_kode_bentrok',
            'nama'          => 'Kode Bentrok Nonaktif',
            'mode_resolusi' => 'pinned',
            'aktif'         => 0,
            'dibuat_pada'   => now(),
        ]);

        $this->putJson("/api/approval-event-type/{$idNonaktif}", [
            'aktif' => true,
        ])->assertStatus(409);

        $this->assertDatabaseHas('approval_event_type', [
            'id_event_type' => $idNonaktif,
            'aktif'         => 0,
        ]);
        $this->assertNotSame($idAktif, $idNonaktif);
    }

    public function test_update_event_type_rename_nama_tersimpan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idEventType = $this->makeEventType('pinned', 'test_rename');

        $this->putJson("/api/approval-event-type/{$idEventType}", [
            'nama' => 'Nama Baru Setelah Rename',
        ])->assertStatus(200)
            ->assertJsonPath('data.nama', 'Nama Baru Setelah Rename');

        $this->assertDatabaseHas('approval_event_type', [
            'id_event_type' => $idEventType,
            'nama'          => 'Nama Baru Setelah Rename',
        ]);
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

    public function test_putuskan_setuju_semua_baru_final_disetujui(): void
    {
        Event::fake([\App\Events\ApprovalDiputuskan::class]);

        $idEventType = $this->makeEventType('pinned', 'test_dummy_2approver');
        $idJabatan   = $this->makeJabatan('Direktur Dua');
        $a1 = $this->makePenggunaDenganJabatan($idJabatan, 'A1');
        $a2 = $this->makePenggunaDenganJabatan($idJabatan, 'A2');
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);
        $pengaju = $this->actingAsRole('SALES');
        $service = app(\App\Modules\Approval\ApprovalService::class);
        $pengajuan = $service->ajukan('test_dummy_2approver', (string) Str::uuid(), $pengaju->id_pengguna, null, self::PERUSAHAAN_ID);

        $service->putuskan($pengajuan->id_approval, $a1->id_pengguna, 'setuju', null, self::PERUSAHAAN_ID);
        $this->assertSame('menunggu', $pengajuan->fresh()->status);
        Event::assertNotDispatched(\App\Events\ApprovalDiputuskan::class);

        $service->putuskan($pengajuan->id_approval, $a2->id_pengguna, 'setuju', null, self::PERUSAHAAN_ID);
        $this->assertSame('disetujui', $pengajuan->fresh()->status);
        Event::assertDispatched(\App\Events\ApprovalDiputuskan::class, function ($e) use ($pengajuan, $a2) {
            return $e->keputusan === 'disetujui'
                && $e->idPerusahaan === self::PERUSAHAAN_ID
                && $e->idApproval === $pengajuan->id_approval
                && $e->idPengguna === $a2->id_pengguna;
        });

        $this->assertDatabaseHas('notifikasi', [
            'id_pengguna'    => $pengaju->id_pengguna,
            'referensi_id'   => $pengajuan->id_approval,
            'referensi_tipe' => 'approval_pengajuan',
        ]);
    }

    public function test_putuskan_tolak_satu_langsung_final_ditolak(): void
    {
        Event::fake([\App\Events\ApprovalDiputuskan::class]);

        $idEventType = $this->makeEventType('pinned', 'test_dummy_tolak');
        $idJabatan   = $this->makeJabatan('Direktur Tolak');
        $a1 = $this->makePenggunaDenganJabatan($idJabatan, 'A1');
        $a2 = $this->makePenggunaDenganJabatan($idJabatan, 'A2');
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);
        $pengaju = $this->actingAsRole('SALES');
        $service = app(\App\Modules\Approval\ApprovalService::class);
        $pengajuan = $service->ajukan('test_dummy_tolak', (string) Str::uuid(), $pengaju->id_pengguna, null, self::PERUSAHAAN_ID);

        $service->putuskan($pengajuan->id_approval, $a1->id_pengguna, 'tolak', 'Tidak sesuai budget', self::PERUSAHAAN_ID);
        $this->assertSame('ditolak', $pengajuan->fresh()->status);
        $this->assertSame('Tidak sesuai budget', $pengajuan->fresh()->alasan_ditolak);
        Event::assertDispatched(\App\Events\ApprovalDiputuskan::class, function ($e) use ($pengajuan, $a1) {
            return $e->keputusan === 'ditolak'
                && $e->idPerusahaan === self::PERUSAHAAN_ID
                && $e->idApproval === $pengajuan->id_approval
                && $e->idPengguna === $a1->id_pengguna;
        });

        $this->assertDatabaseHas('notifikasi', [
            'id_pengguna'    => $pengaju->id_pengguna,
            'referensi_id'   => $pengajuan->id_approval,
            'referensi_tipe' => 'approval_pengajuan',
            'isi'            => 'Tidak sesuai budget',
        ]);
    }

    public function test_putuskan_oleh_bukan_approver_403(): void
    {
        $idEventType = $this->makeEventType('pinned', 'test_dummy_403');
        $idJabatan   = $this->makeJabatan('Direktur 403');
        $a1 = $this->makePenggunaDenganJabatan($idJabatan, 'A1');
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);
        $pengaju = $this->actingAsRole('SALES');
        $bukanApprover = $this->actingAsRole('DISPATCHER');
        $service = app(\App\Modules\Approval\ApprovalService::class);
        $pengajuan = $service->ajukan('test_dummy_403', (string) Str::uuid(), $pengaju->id_pengguna, null, self::PERUSAHAAN_ID);

        try {
            $service->putuskan($pengajuan->id_approval, $bukanApprover->id_pengguna, 'setuju', null, self::PERUSAHAAN_ID);
            $this->fail('Seharusnya melempar HttpException 403');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_status_untuk_null_sebelum_diajukan_terisi_setelah_diajukan(): void
    {
        $idEventType = $this->makeEventType('pinned', 'test_dummy_status');
        $idJabatan   = $this->makeJabatan('Direktur Status');
        $this->makePenggunaDenganJabatan($idJabatan, 'A1');
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);
        $pengaju = $this->actingAsRole('SALES');
        $idReferensi = (string) Str::uuid();
        $service = app(\App\Modules\Approval\ApprovalService::class);

        $this->assertNull($service->statusUntuk('test_dummy_status', $idReferensi, self::PERUSAHAAN_ID));

        $service->ajukan('test_dummy_status', $idReferensi, $pengaju->id_pengguna, null, self::PERUSAHAAN_ID);

        $status = $service->statusUntuk('test_dummy_status', $idReferensi, self::PERUSAHAAN_ID);
        $this->assertSame('menunggu', $status['status']);
        $this->assertSame(0, $status['approval_progress']['disetujui']);
        $this->assertSame(1, $status['approval_progress']['total']);
    }

    public function test_putuskan_dua_kali_oleh_approver_sama_409(): void
    {
        $idEventType = $this->makeEventType('pinned', 'test_dummy_409');
        $idJabatan   = $this->makeJabatan('Direktur 409');
        $a1 = $this->makePenggunaDenganJabatan($idJabatan, 'A1');
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);
        $pengaju = $this->actingAsRole('SALES');
        $service = app(\App\Modules\Approval\ApprovalService::class);
        $pengajuan = $service->ajukan('test_dummy_409', (string) Str::uuid(), $pengaju->id_pengguna, null, self::PERUSAHAAN_ID);

        $service->putuskan($pengajuan->id_approval, $a1->id_pengguna, 'setuju', null, self::PERUSAHAAN_ID);

        try {
            $service->putuskan($pengajuan->id_approval, $a1->id_pengguna, 'setuju', null, self::PERUSAHAAN_ID);
            $this->fail('Seharusnya melempar HttpException 409');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_putuskan_setelah_final_oleh_approver_lain_409_dan_event_hanya_sekali(): void
    {
        Event::fake([\App\Events\ApprovalDiputuskan::class]);

        $idEventType = $this->makeEventType('pinned', 'test_dummy_race');
        $idJabatan   = $this->makeJabatan('Direktur Race');
        $a1 = $this->makePenggunaDenganJabatan($idJabatan, 'A1');
        $a2 = $this->makePenggunaDenganJabatan($idJabatan, 'A2');
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);
        $pengaju = $this->actingAsRole('SALES');
        $service = app(\App\Modules\Approval\ApprovalService::class);
        $pengajuan = $service->ajukan('test_dummy_race', (string) Str::uuid(), $pengaju->id_pengguna, null, self::PERUSAHAAN_ID);

        $service->putuskan($pengajuan->id_approval, $a1->id_pengguna, 'tolak', 'reason-1', self::PERUSAHAAN_ID);
        $this->assertSame('ditolak', $pengajuan->fresh()->status);
        $this->assertSame('reason-1', $pengajuan->fresh()->alasan_ditolak);

        try {
            $service->putuskan($pengajuan->id_approval, $a2->id_pengguna, 'tolak', 'reason-2', self::PERUSAHAAN_ID);
            $this->fail('Seharusnya melempar HttpException 409 karena pengajuan sudah final');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertSame('reason-1', $pengajuan->fresh()->alasan_ditolak);
        $this->assertSame('ditolak', $pengajuan->fresh()->status);

        $this->assertSame('menunggu', DB::table('approval_keputusan')
            ->where('id_approval', $pengajuan->id_approval)
            ->where('id_pengguna', $a2->id_pengguna)
            ->value('status'));

        Event::assertDispatchedTimes(\App\Events\ApprovalDiputuskan::class, 1);
    }

    public function test_putuskan_pengajuan_milik_perusahaan_lain_404(): void
    {
        $idEventType = $this->makeEventType('pinned', 'test_dummy_tenant');
        $idJabatan   = $this->makeJabatan('Direktur Tenant');
        $a1 = $this->makePenggunaDenganJabatan($idJabatan, 'A1');
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);
        $pengaju = $this->actingAsRole('SALES');
        $service = app(\App\Modules\Approval\ApprovalService::class);
        $pengajuan = $service->ajukan('test_dummy_tenant', (string) Str::uuid(), $pengaju->id_pengguna, null, self::PERUSAHAAN_ID);

        $idPerusahaanLain = (string) Str::uuid();

        try {
            $service->putuskan($pengajuan->id_approval, $a1->id_pengguna, 'setuju', null, $idPerusahaanLain);
            $this->fail('Seharusnya melempar HttpException 404');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertSame('menunggu', $pengajuan->fresh()->status);
        $this->assertSame('menunggu', DB::table('approval_keputusan')
            ->where('id_approval', $pengajuan->id_approval)
            ->where('id_pengguna', $a1->id_pengguna)
            ->value('status'));
    }

    public function test_menunggu_approval_saya_menyertakan_nama_event_type_dan_nama_pengaju(): void
    {
        $idEventType = $this->makeEventType('pinned', 'test_kaya');
        $idJabatan   = $this->makeJabatan('Approver Kaya');
        $approver    = $this->makePenggunaDenganJabatan($idJabatan, 'Approver Data Kaya');
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);
        $pengaju = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => 'SALES',
            'username' => 'pengaju_kaya', 'email' => 'pengaju_kaya@test.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);

        $service = app(\App\Modules\Approval\ApprovalService::class);
        $service->ajukan('test_kaya', (string) Str::uuid(), $pengaju->id_pengguna, 750000.0, self::PERUSAHAAN_ID);

        \Laravel\Sanctum\Sanctum::actingAs($approver, ['*']);
        $res = $this->getJson('/api/approval-pengajuan/menunggu-saya');

        $res->assertStatus(200)
            ->assertJsonPath('data.0.nama_pengaju', 'pengaju_kaya')
            ->assertJsonPath('data.0.nomor_referensi', null);
        $this->assertNotEmpty($res->json('data.0.nama_event_type'));
    }

    public function test_menunggu_approval_saya_menyertakan_ringkasan_referensi(): void
    {
        $idEventType = $this->makeEventType('pinned', 'penawaran');
        $idJabatan   = $this->makeJabatan('Approver Penawaran');
        $approver    = $this->makePenggunaDenganJabatan($idJabatan, 'Approver Penawaran');
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);

        $idKlien = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien' => $idKlien, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien' => 'K-RINGKAS', 'nama_klien' => 'Klien Ringkasan Referensi', 'dibuat_pada' => now(),
        ]);
        $idPenawaran = (string) Str::uuid();
        DB::table('penawaran')->insert([
            'id_penawaran' => $idPenawaran, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_klien' => $idKlien,
            'nomor_penawaran' => 'PNW-RINGKAS-1', 'judul' => 'Penawaran Ringkasan', 'status' => 'draft',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        $pengaju = $this->actingAsRole('SALES');
        $service = app(\App\Modules\Approval\ApprovalService::class);
        $service->ajukan('penawaran', $idPenawaran, $pengaju->id_pengguna, null, self::PERUSAHAAN_ID);

        \Laravel\Sanctum\Sanctum::actingAs($approver, ['*']);
        $res = $this->getJson('/api/approval-pengajuan/menunggu-saya');

        $res->assertStatus(200)
            ->assertJsonPath('data.0.kode_event_type', 'penawaran')
            ->assertJsonPath('data.0.nomor_referensi', 'PNW-RINGKAS-1')
            ->assertJsonPath('data.0.pihak_referensi', 'Klien Ringkasan Referensi');
    }

    public function test_menunggu_approval_saya_menyertakan_ringkasan_referensi_kontrak_vendor(): void
    {
        $idEventType = $this->makeEventType('pinned', 'kontrak_vendor');
        $idJabatan   = $this->makeJabatan('Approver Kontrak Vendor');
        $approver    = $this->makePenggunaDenganJabatan($idJabatan, 'Approver Kontrak Vendor');
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);

        $idVendor = (string) Str::uuid();
        DB::table('vendor')->insert([
            'id_vendor' => $idVendor, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor' => 'V-RINGKAS', 'nama_vendor' => 'Vendor Ringkasan Referensi', 'dibuat_pada' => now(),
        ]);
        $idKontrak = (string) Str::uuid();
        DB::table('kontrak_vendor')->insert([
            'id_kontrak_vendor' => $idKontrak, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_vendor' => $idVendor,
            'nomor_kontrak' => 'KV-RINGKAS-1', 'mekanisme' => 'unit_only', 'status' => 'menunggu_approval',
            'nilai_kontrak' => 90000000, 'dibuat_pada' => now(),
        ]);

        $pengaju = $this->actingAsRole('SUPERADMIN');
        $service = app(\App\Modules\Approval\ApprovalService::class);
        $service->ajukan('kontrak_vendor', $idKontrak, $pengaju->id_pengguna, 90000000.0, self::PERUSAHAAN_ID);

        \Laravel\Sanctum\Sanctum::actingAs($approver, ['*']);
        $res = $this->getJson('/api/approval-pengajuan/menunggu-saya');

        $res->assertStatus(200)
            ->assertJsonPath('data.0.kode_event_type', 'kontrak_vendor')
            ->assertJsonPath('data.0.nomor_referensi', 'KV-RINGKAS-1')
            ->assertJsonPath('data.0.keterangan_referensi', 'Unit Only')
            ->assertJsonPath('data.0.pihak_referensi', 'Vendor Ringkasan Referensi');
    }

    public function test_endpoint_menunggu_saya_dan_keputusan(): void
    {
        $idEventType = $this->makeEventType('pinned', 'test_dummy_http');
        $idJabatan   = $this->makeJabatan('Direktur HTTP');
        $approver    = $this->makePenggunaDenganJabatan($idJabatan, 'Approver HTTP');
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);
        $pengaju = $this->actingAsRole('SALES');
        $service = app(\App\Modules\Approval\ApprovalService::class);
        $pengajuan = $service->ajukan('test_dummy_http', (string) Str::uuid(), $pengaju->id_pengguna, 750000.0, self::PERUSAHAAN_ID);

        \Laravel\Sanctum\Sanctum::actingAs($approver, ['*']);

        $list = $this->getJson('/api/approval-pengajuan/menunggu-saya');
        $list->assertStatus(200);
        $this->assertTrue(collect($list->json('data'))->contains('id_approval', $pengajuan->id_approval));

        $keputusan = $this->patchJson("/api/approval-pengajuan/{$pengajuan->id_approval}/keputusan", [
            'keputusan' => 'setuju',
        ]);
        $keputusan->assertStatus(200)->assertJsonPath('data.status', 'disetujui');
    }

    public function test_endpoint_keputusan_tolak_wajib_catatan(): void
    {
        $idEventType = $this->makeEventType('pinned', 'test_dummy_http_tolak');
        $idJabatan   = $this->makeJabatan('Direktur HTTP Tolak');
        $approver    = $this->makePenggunaDenganJabatan($idJabatan, 'Approver HTTP Tolak');
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);
        $pengaju = $this->actingAsRole('SALES');
        $service = app(\App\Modules\Approval\ApprovalService::class);
        $pengajuan = $service->ajukan('test_dummy_http_tolak', (string) Str::uuid(), $pengaju->id_pengguna, null, self::PERUSAHAAN_ID);

        \Laravel\Sanctum\Sanctum::actingAs($approver, ['*']);

        $this->patchJson("/api/approval-pengajuan/{$pengajuan->id_approval}/keputusan", [
            'keputusan' => 'tolak',
        ])->assertStatus(422);

        $this->patchJson("/api/approval-pengajuan/{$pengajuan->id_approval}/keputusan", [
            'keputusan' => 'tolak',
            'catatan'   => 'Alasan wajib ini',
        ])->assertStatus(200)->assertJsonPath('data.status', 'ditolak');
    }

    public function test_putuskan_untuk_referensi_delegasi_ke_putuskan(): void
    {
        $idEventType = $this->makeEventType('pinned', 'test_dummy_referensi');
        $idJabatan   = $this->makeJabatan('Direktur Referensi');
        $approver    = $this->makePenggunaDenganJabatan($idJabatan, 'Approver Referensi');
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);
        $pengaju = $this->actingAsRole('SALES');
        $idReferensi = (string) Str::uuid();
        $service = app(\App\Modules\Approval\ApprovalService::class);
        $service->ajukan('test_dummy_referensi', $idReferensi, $pengaju->id_pengguna, 100000.0, self::PERUSAHAAN_ID);

        $hasil = $service->putuskanUntukReferensi('test_dummy_referensi', $idReferensi, $approver->id_pengguna, 'setuju', null, self::PERUSAHAAN_ID);
        $this->assertSame('disetujui', $hasil->status);
    }

    public function test_putuskan_untuk_referensi_yang_tidak_ada_404(): void
    {
        $this->makeEventType('pinned', 'test_dummy_referensi_404');
        $pengguna = $this->actingAsRole('SALES');
        $service = app(\App\Modules\Approval\ApprovalService::class);

        try {
            $service->putuskanUntukReferensi('test_dummy_referensi_404', (string) Str::uuid(), $pengguna->id_pengguna, 'setuju', null, self::PERUSAHAAN_ID);
            $this->fail('Seharusnya melempar HttpException 404');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    public function test_batalkan_dan_ajukan_ulang_membatalkan_lama_dan_membuat_baru(): void
    {
        $idEventType = $this->makeEventType('pinned', 'test_dummy_batal');
        $idJabatan   = $this->makeJabatan('Direktur Batal');
        $approverLama = $this->makePenggunaDenganJabatan($idJabatan, 'Approver Lama');
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);
        $pengaju = $this->actingAsRole('SALES');
        $idReferensi = (string) Str::uuid();
        $service = app(\App\Modules\Approval\ApprovalService::class);
        $lama = $service->ajukan('test_dummy_batal', $idReferensi, $pengaju->id_pengguna, 100000.0, self::PERUSAHAAN_ID);

        $baru = $service->batalkanDanAjukanUlang('test_dummy_batal', $idReferensi, $pengaju->id_pengguna, 200000.0, self::PERUSAHAAN_ID);

        $this->assertSame('dibatalkan', $lama->fresh()->status);
        $this->assertNotSame($lama->id_approval, $baru->id_approval);
        $this->assertSame('menunggu', $baru->status);
        $this->assertSame(200000.0, (float) $baru->nominal);
        $this->assertSame(1, DB::table('approval_keputusan')->where('id_approval', $baru->id_approval)->count());
    }

    public function test_batalkan_dan_ajukan_ulang_tanpa_pengajuan_aktif_langsung_ajukan_biasa(): void
    {
        $this->makeEventType('pinned', 'test_dummy_batal_kosong');
        $idJabatan = $this->makeJabatan('Direktur Batal Kosong');
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => DB::table('approval_event_type')->where('kode', 'test_dummy_batal_kosong')->value('id_event_type'),
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);
        $this->makePenggunaDenganJabatan($idJabatan, 'Approver Kosong');
        $pengaju = $this->actingAsRole('SALES');
        $service = app(\App\Modules\Approval\ApprovalService::class);

        $baru = $service->batalkanDanAjukanUlang('test_dummy_batal_kosong', (string) Str::uuid(), $pengaju->id_pengguna, 50000.0, self::PERUSAHAAN_ID);
        $this->assertSame('menunggu', $baru->status);
    }

    public function test_batalkan_dan_ajukan_ulang_void_baris_keputusan_siklus_lama(): void
    {
        $idEventType = $this->makeEventType('pinned', 'test_dummy_batal_void');
        $idJabatan    = $this->makeJabatan('Direktur Batal Void');
        $approverLama = $this->makePenggunaDenganJabatan($idJabatan, 'Approver Lama Void');
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);
        $pengaju = $this->actingAsRole('SALES');
        $idReferensi = (string) Str::uuid();
        $service = app(\App\Modules\Approval\ApprovalService::class);
        $lama = $service->ajukan('test_dummy_batal_void', $idReferensi, $pengaju->id_pengguna, 100000.0, self::PERUSAHAAN_ID);

        $this->assertSame(1, DB::table('approval_keputusan')
            ->where('id_approval', $lama->id_approval)
            ->whereNull('dihapus_pada')
            ->count());

        $baru = $service->batalkanDanAjukanUlang('test_dummy_batal_void', $idReferensi, $pengaju->id_pengguna, 200000.0, self::PERUSAHAAN_ID);

        $this->assertSame('dibatalkan', $lama->fresh()->status);
        $this->assertNotSame($lama->id_approval, $baru->id_approval);

        $barisLama = DB::table('approval_keputusan')->where('id_approval', $lama->id_approval)->get();
        $this->assertCount(1, $barisLama);
        $this->assertNotNull($barisLama->first()->dihapus_pada);

        $this->assertSame(1, DB::table('approval_keputusan')
            ->where('id_approval', $baru->id_approval)
            ->whereNull('dihapus_pada')
            ->count());
    }

    public function test_batalkan_untuk_referensi_membatalkan_pengajuan_menunggu_dan_void_keputusan(): void
    {
        $idEventType = $this->makeEventType('pinned', 'test_batalkan_untuk_referensi');
        $idJabatan   = $this->makeJabatan('Direktur Batalkan Untuk Referensi');
        $this->makePenggunaDenganJabatan($idJabatan, 'Approver Batalkan Untuk Referensi');
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);
        $pengaju = $this->actingAsRole('SALES');
        $idReferensi = (string) Str::uuid();
        $service = app(\App\Modules\Approval\ApprovalService::class);
        $pengajuan = $service->ajukan('test_batalkan_untuk_referensi', $idReferensi, $pengaju->id_pengguna, 100000.0, self::PERUSAHAAN_ID);

        $service->batalkanUntukReferensi(['test_batalkan_untuk_referensi'], $idReferensi, self::PERUSAHAAN_ID);

        $this->assertSame('dibatalkan', $pengajuan->fresh()->status);
        $this->assertSame(1, DB::table('approval_keputusan')
            ->where('id_approval', $pengajuan->id_approval)
            ->whereNotNull('dihapus_pada')
            ->count());
    }

    public function test_batalkan_untuk_referensi_tanpa_pengajuan_aktif_no_op(): void
    {
        $this->makeEventType('pinned', 'test_batalkan_untuk_referensi_kosong');

        $service = app(\App\Modules\Approval\ApprovalService::class);

        $service->batalkanUntukReferensi(['test_batalkan_untuk_referensi_kosong'], (string) Str::uuid(), self::PERUSAHAAN_ID);

        $this->assertSame(0, DB::table('approval_pengajuan')->count());
    }

    public function test_menunggu_saya_untuk_kode_sparepart_menampilkan_nomor_referensi_pengajuan_pengeluaran(): void
    {
        $idEventType = $this->makeEventType('pinned', 'sparepart');
        $idJabatan   = $this->makeJabatan('Approver Sparepart');
        $approver    = $this->makePenggunaDenganJabatan($idJabatan, 'Approver Sparepart');
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);

        $idPengajuan = (string) Str::uuid();
        DB::table('pengajuan_pengeluaran')->insert([
            'id_pengajuan' => $idPengajuan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nomor_pengajuan' => 'PP-SPAREPART-1', 'kategori' => 'sparepart', 'nominal' => 100000,
            'tanggal_pengajuan' => now()->toDateString(), 'penerima' => 'Toko Sparepart Ringkasan',
            'status' => 'menunggu_approval', 'dibuat_pada' => now(),
        ]);

        $pengaju = $this->actingAsRole('SALES');
        $service = app(\App\Modules\Approval\ApprovalService::class);
        $service->ajukan('sparepart', $idPengajuan, $pengaju->id_pengguna, 100000.0, self::PERUSAHAAN_ID);

        \Laravel\Sanctum\Sanctum::actingAs($approver, ['*']);
        $res = $this->getJson('/api/approval-pengajuan/menunggu-saya');

        $res->assertStatus(200)
            ->assertJsonPath('data.0.kode_event_type', 'sparepart')
            ->assertJsonPath('data.0.nomor_referensi', 'PP-SPAREPART-1')
            ->assertJsonPath('data.0.pihak_referensi', 'Toko Sparepart Ringkasan');
    }

    public function test_upload_lampiran_dan_muncul_di_status_referensi(): void
    {
        Storage::fake('public');
        $this->actingAsRole('SUPERADMIN');
        $idEventType = $this->makeEventType('pinned', 'test_lampiran');
        $idReferensi = (string) Str::uuid();
        DB::table('approval_pengajuan')->insert([
            'id_approval'         => (string) Str::uuid(),
            'id_perusahaan'       => self::PERUSAHAAN_ID,
            'id_event_type'       => $idEventType,
            'id_referensi'        => $idReferensi,
            'id_pengguna_pengaju' => (string) Str::uuid(),
            'status'              => 'menunggu',
            'dibuat_pada'         => now(),
        ]);

        $this->post('/api/approval-pengajuan/lampiran', [
            'kode'         => 'test_lampiran',
            'id_referensi' => $idReferensi,
            'lampiran'     => [
                UploadedFile::fake()->create('penawaran-vendor.pdf', 50, 'application/pdf'),
                UploadedFile::fake()->create('foto-barang.jpg', 30, 'image/jpeg'),
            ],
        ])->assertStatus(201)->assertJsonCount(2, 'data');

        $status = $this->getJson('/api/approval-pengajuan/status-referensi?kode=test_lampiran&id_referensi=' . $idReferensi);
        $status->assertStatus(200);
        $this->assertCount(2, $status->json('data.lampiran'));
        $this->assertSame('penawaran-vendor.pdf', $status->json('data.lampiran.0.nama_file'));
        $this->assertNotEmpty($status->json('data.lampiran.0.url_file'));
    }

    public function test_upload_lampiran_tanpa_pengajuan_menunggu_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->makeEventType('pinned', 'test_lampiran_404');

        $this->post('/api/approval-pengajuan/lampiran', [
            'kode'         => 'test_lampiran_404',
            'id_referensi' => (string) Str::uuid(),
            'lampiran'     => [UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')],
        ])->assertStatus(404);
    }
}
