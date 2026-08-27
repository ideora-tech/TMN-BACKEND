<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use App\Modules\ArusKas\ArusKasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ArusKasApprovalWiringTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): Pengguna
    {
        return $this->actingAsRole('KEUANGAN');
    }

    private function makeEventTypeDanApprover(string $idPengguna): void
    {
        $idJabatan = (string) Str::uuid();
        DB::table('jabatan')->insert([
            'id_jabatan' => $idJabatan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jabatan' => 'DIR', 'nama_jabatan' => 'Direktur', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $idKaryawan = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $idKaryawan, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_jabatan' => $idJabatan,
            'nik' => 'NIK-' . Str::random(6), 'nama_karyawan' => 'Direktur Test', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('pengguna')->where('id_pengguna', $idPengguna)->update(['id_karyawan' => $idKaryawan]);

        $idEventType = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $idEventType, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => 'pengajuan_pengeluaran', 'nama' => 'Pengajuan Pengeluaran', 'mode_resolusi' => 'pinned',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'kategori'          => 'lainnya',
            'tanggal_pengajuan' => now()->toDateString(),
            'penerima'          => 'Test',
            'keterangan'        => 'Wiring test',
        ], $override);
    }

    public function test_create_di_atas_threshold_membuat_approval_pengajuan_via_engine(): void
    {
        $approver = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => 'DIREKTUR',
            'username' => 'dir_' . Str::random(6), 'email' => Str::random(6) . '@test.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        $this->makeEventTypeDanApprover($approver->id_pengguna);

        $this->actingAsAdmin();
        app(ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 1000000);

        $res = $this->postJson('/api/arus-kas/pengajuan', $this->payload(['nominal' => 5000000]));
        $res->assertStatus(201)->assertJsonPath('data.status', 'menunggu_approval');

        $idPengajuan = $res->json('data.id_pengajuan');
        $this->assertDatabaseHas('approval_pengajuan', [
            'id_referensi' => $idPengajuan, 'status' => 'menunggu',
        ]);
    }

    public function test_create_di_bawah_threshold_auto_approve_tanpa_sentuh_engine(): void
    {
        $this->actingAsAdmin();
        app(ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 1000000);

        $res = $this->postJson('/api/arus-kas/pengajuan', $this->payload(['nominal' => 100000]));
        $res->assertStatus(201)->assertJsonPath('data.status', 'disetujui');

        $this->assertSame(0, DB::table('approval_pengajuan')->count());
    }

    public function test_proses_approval_setuju_via_engine_update_status_pengajuan_pengeluaran(): void
    {
        $approver = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => 'MANAGER',
            'username' => 'dir_' . Str::random(6), 'email' => Str::random(6) . '@test.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        $this->makeEventTypeDanApprover($approver->id_pengguna);
        $this->actingAsAdmin();
        app(ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 1000000);

        $idPengajuan = $this->postJson('/api/arus-kas/pengajuan', $this->payload(['nominal' => 5000000]))
            ->assertStatus(201)->json('data.id_pengajuan');

        Sanctum::actingAs($approver, ['*']);
        $res = $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/approval", ['keputusan' => 'setuju']);
        $res->assertStatus(200)->assertJsonPath('data.status', 'disetujui')->assertJsonPath('data.disetujui_oleh', $approver->id_pengguna);
    }

    public function test_proses_approval_bukan_approver_403(): void
    {
        $approver = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => 'MANAGER',
            'username' => 'dir_' . Str::random(6), 'email' => Str::random(6) . '@test.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        $this->makeEventTypeDanApprover($approver->id_pengguna);
        $this->actingAsAdmin();
        app(ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 1000000);

        $idPengajuan = $this->postJson('/api/arus-kas/pengajuan', $this->payload(['nominal' => 5000000]))
            ->assertStatus(201)->json('data.id_pengajuan');

        $this->actingAsRole('ADMIN');
        $res = $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/approval", ['keputusan' => 'setuju']);
        $res->assertStatus(403);
    }

    public function test_detail_pengajuan_menampilkan_approval_progress_dari_engine(): void
    {
        $approver = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => 'MANAGER',
            'username' => 'dir_' . Str::random(6), 'email' => Str::random(6) . '@test.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        $this->makeEventTypeDanApprover($approver->id_pengguna);
        $this->actingAsAdmin();
        app(ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 1000000);

        $idPengajuan = $this->postJson('/api/arus-kas/pengajuan', $this->payload(['nominal' => 5000000]))
            ->assertStatus(201)->json('data.id_pengajuan');

        $res = $this->getJson("/api/arus-kas/pengajuan/{$idPengajuan}");
        $res->assertStatus(200)
            ->assertJsonPath('data.approval_progress.disetujui', 0)
            ->assertJsonPath('data.approval_progress.total', 1)
            ->assertJsonPath('data.approval.0.id_pengguna', $approver->id_pengguna)
            ->assertJsonPath('data.approval.0.status', 'menunggu')
            ->assertJsonPath('data.approval.0.nama', $approver->username);

        Sanctum::actingAs($approver, ['*']);
        $resSelf = $this->getJson("/api/arus-kas/pengajuan/{$idPengajuan}");
        $resSelf->assertJsonPath('data.bisa_approve', true);
    }

    public function test_reset_snapshot_approval_tidak_ikut_hitung_baris_siklus_lama(): void
    {
        $approver = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => 'MANAGER',
            'username' => 'dir_' . Str::random(6), 'email' => Str::random(6) . '@test.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        $this->makeEventTypeDanApprover($approver->id_pengguna);
        $admin = $this->actingAsAdmin();
        app(ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 1000000);

        $idPengajuan = $this->postJson('/api/arus-kas/pengajuan', $this->payload(['nominal' => 5000000]))
            ->assertStatus(201)->json('data.id_pengajuan');

        app(\App\Modules\Approval\ApprovalService::class)->batalkanDanAjukanUlang(
            'pengajuan_pengeluaran',
            $idPengajuan,
            $admin->id_pengguna,
            7000000.0,
            self::PERUSAHAAN_ID,
        );

        $res = $this->getJson("/api/arus-kas/pengajuan/{$idPengajuan}");
        $res->assertStatus(200)
            ->assertJsonPath('data.approval_progress.total', 1)
            ->assertJsonPath('data.approval_progress.disetujui', 0)
            ->assertJsonPath('data.approval.0.id_pengguna', $approver->id_pengguna);
    }
}
