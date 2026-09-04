<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProyekApprovalWiringTest extends TestCase
{
    use RefreshDatabase;

    private function makeKlien(): string
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien' => 'KL-' . Str::random(6), 'nama_klien' => 'Klien Proyek Approval',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function pasangGerbangApproval(): string
    {
        $idPengguna = (string) Str::uuid();
        DB::table('pengguna')->insert([
            'id_pengguna' => $idPengguna, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran' => 'BOD', 'username' => 'approver_pry_' . Str::random(6),
            'email' => Str::random(8) . '@pry.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $idEventType = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $idEventType, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => 'proyek', 'nama' => 'Proyek', 'mode_resolusi' => 'pinned',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'pengguna', 'id_pengguna' => $idPengguna, 'dibuat_pada' => now(),
        ]);

        return $idPengguna;
    }

    private function buatProyekManual(array $overrides = []): string
    {
        $res = $this->postJson('/api/proyek', array_merge([
            'id_klien'    => $this->makeKlien(),
            'nama_proyek' => 'Proyek Manual ' . Str::random(4),
        ], $overrides));
        $res->assertStatus(201);
        return (string) $res->json('data.id_proyek');
    }

    public function test_proyek_manual_dipaksa_draft_saat_gerbang_aktif(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->pasangGerbangApproval();

        $id = $this->buatProyekManual(['status' => 'aktif']);

        $this->assertDatabaseHas('proyek', ['id_proyek' => $id, 'status' => 'draft']);
    }

    public function test_tanpa_event_type_status_manual_bebas_seperti_semula(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $id = $this->buatProyekManual(['status' => 'aktif']);

        $this->assertDatabaseHas('proyek', ['id_proyek' => $id, 'status' => 'aktif']);
    }

    public function test_ajukan_approval_proyek_manual_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->pasangGerbangApproval();
        $id = $this->buatProyekManual(['harga_proyek' => 5000000]);

        $res = $this->postJson("/api/proyek/{$id}/ajukan-approval");

        $res->assertStatus(200)->assertJsonPath('data.status', 'menunggu_approval');
        $this->assertDatabaseHas('approval_pengajuan', ['id_referensi' => $id, 'status' => 'menunggu']);
    }

    public function test_proyek_dari_penawaran_tidak_bisa_diajukan_approval(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->pasangGerbangApproval();
        $id = $this->buatProyekManual();
        DB::table('penawaran')->insert([
            'id_penawaran' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nomor_penawaran' => 'PNW-' . Str::random(8), 'judul' => 'Asal Proyek',
            'status' => 'disetujui', 'id_proyek' => $id, 'dibuat_pada' => now(),
        ]);

        $res = $this->postJson("/api/proyek/{$id}/ajukan-approval");

        $res->assertStatus(422);
        $this->assertStringContainsString('dari penawaran', $res->json('message'));
    }

    public function test_disetujui_membuat_proyek_aktif(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idApprover = $this->pasangGerbangApproval();
        $id = $this->buatProyekManual();
        $this->postJson("/api/proyek/{$id}/ajukan-approval")->assertStatus(200);

        app(\App\Modules\Approval\ApprovalService::class)
            ->putuskanUntukReferensi('proyek', $id, $idApprover, 'setuju', null, self::PERUSAHAAN_ID);

        $this->assertDatabaseHas('proyek', ['id_proyek' => $id, 'status' => 'aktif']);
    }

    public function test_ditolak_mengembalikan_proyek_ke_draft(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idApprover = $this->pasangGerbangApproval();
        $id = $this->buatProyekManual();
        $this->postJson("/api/proyek/{$id}/ajukan-approval")->assertStatus(200);

        app(\App\Modules\Approval\ApprovalService::class)
            ->putuskanUntukReferensi('proyek', $id, $idApprover, 'tolak', 'Harga belum wajar', self::PERUSAHAAN_ID);

        $this->assertDatabaseHas('proyek', ['id_proyek' => $id, 'status' => 'draft']);
    }

    public function test_update_status_aktif_diblokir_saat_gerbang_aktif(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->pasangGerbangApproval();
        $id = $this->buatProyekManual();

        $res = $this->patchJson("/api/proyek/{$id}/status", ['status' => 'aktif']);

        $res->assertStatus(422);
        $this->assertStringContainsString('ajukan approval', $res->json('message'));
        $this->assertDatabaseHas('proyek', ['id_proyek' => $id, 'status' => 'draft']);
    }

    public function test_update_status_saat_menunggu_approval_diblokir(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->pasangGerbangApproval();
        $id = $this->buatProyekManual();
        $this->postJson("/api/proyek/{$id}/ajukan-approval")->assertStatus(200);

        $res = $this->patchJson("/api/proyek/{$id}/status", ['status' => 'batal']);

        $res->assertStatus(422);
        $this->assertStringContainsString('menunggu approval', $res->json('message'));
    }

    public function test_setelah_disetujui_status_boleh_diubah_bebas(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idApprover = $this->pasangGerbangApproval();
        $id = $this->buatProyekManual();
        $this->postJson("/api/proyek/{$id}/ajukan-approval")->assertStatus(200);
        app(\App\Modules\Approval\ApprovalService::class)
            ->putuskanUntukReferensi('proyek', $id, $idApprover, 'setuju', null, self::PERUSAHAAN_ID);

        $this->patchJson("/api/proyek/{$id}/status", ['status' => 'selesai'])->assertStatus(200);
        $this->assertDatabaseHas('proyek', ['id_proyek' => $id, 'status' => 'selesai']);
    }

    public function test_hapus_proyek_menunggu_membatalkan_pengajuannya(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->pasangGerbangApproval();
        $id = $this->buatProyekManual();
        $this->postJson("/api/proyek/{$id}/ajukan-approval")->assertStatus(200);

        $this->deleteJson("/api/proyek/{$id}")->assertStatus(200);

        $this->assertDatabaseHas('approval_pengajuan', ['id_referensi' => $id, 'status' => 'dibatalkan']);
    }
}
