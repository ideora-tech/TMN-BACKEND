<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FakturApprovalWiringTest extends TestCase
{
    use RefreshDatabase;

    private function makeEventTypeDanApprover(string $idPengguna): void
    {
        $idJabatan = (string) Str::uuid();
        DB::table('jabatan')->insert([
            'id_jabatan' => $idJabatan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jabatan' => 'KEUMGR', 'nama_jabatan' => 'Keuangan Manager', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $idKaryawan = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $idKaryawan, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_jabatan' => $idJabatan,
            'nik' => 'NIK-' . Str::random(6), 'nama_karyawan' => 'Keuangan Manager Test', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('pengguna')->where('id_pengguna', $idPengguna)->update(['id_karyawan' => $idKaryawan]);

        $idEventType = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $idEventType, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => 'faktur', 'nama' => 'Invoice Klien', 'mode_resolusi' => 'pinned',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);
    }

    private function buatFakturDraft(string $idPengguna): string
    {
        $id = (string) Str::uuid();
        DB::table('faktur')->insert([
            'id_faktur' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nomor_faktur' => 'FK-WIRING-' . Str::random(4), 'total' => 3000000,
            'status' => 'draft', 'dibuat_pada' => now(), 'dibuat_oleh' => $idPengguna,
        ]);
        return $id;
    }

    public function test_ajukan_approval_pindah_ke_menunggu_approval_dan_membuat_approval_pengajuan(): void
    {
        $approver = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => 'KEUANGAN',
            'username' => 'km_' . Str::random(6), 'email' => Str::random(6) . '@test.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        $this->makeEventTypeDanApprover($approver->id_pengguna);
        $keuangan = $this->actingAsRole('SUPERADMIN');
        $id = $this->buatFakturDraft($keuangan->id_pengguna);

        $res = $this->postJson("/api/faktur/{$id}/ajukan-approval");
        $res->assertStatus(200)->assertJsonPath('data.status', 'menunggu_approval');

        $this->assertDatabaseHas('approval_pengajuan', ['id_referensi' => $id, 'status' => 'menunggu']);
    }

    public function test_ajukan_approval_dari_status_bukan_draft_ditolak_422(): void
    {
        $keuangan = $this->actingAsRole('SUPERADMIN');
        $id = (string) Str::uuid();
        DB::table('faktur')->insert([
            'id_faktur' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nomor_faktur' => 'FK-WIRING-X', 'total' => 100000, 'status' => 'terkirim',
            'dibuat_pada' => now(), 'dibuat_oleh' => $keuangan->id_pengguna,
        ]);

        $this->postJson("/api/faktur/{$id}/ajukan-approval")->assertStatus(422);
    }

    public function test_keputusan_disetujui_mengubah_status_jadi_terkirim(): void
    {
        $approver = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => 'KEUANGAN',
            'username' => 'km_' . Str::random(6), 'email' => Str::random(6) . '@test.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        $this->makeEventTypeDanApprover($approver->id_pengguna);
        $keuangan = $this->actingAsRole('SUPERADMIN');
        $id = $this->buatFakturDraft($keuangan->id_pengguna);
        $this->postJson("/api/faktur/{$id}/ajukan-approval")->assertStatus(200);

        app(\App\Modules\Approval\ApprovalService::class)->putuskanUntukReferensi(
            'faktur', $id, $approver->id_pengguna, 'setuju', null, self::PERUSAHAAN_ID
        );

        $this->assertDatabaseHas('faktur', ['id_faktur' => $id, 'status' => 'terkirim']);
    }

    public function test_keputusan_ditolak_mengembalikan_ke_draft_dengan_alasan(): void
    {
        $approver = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => 'KEUANGAN',
            'username' => 'km_' . Str::random(6), 'email' => Str::random(6) . '@test.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        $this->makeEventTypeDanApprover($approver->id_pengguna);
        $keuangan = $this->actingAsRole('SUPERADMIN');
        $id = $this->buatFakturDraft($keuangan->id_pengguna);
        $this->postJson("/api/faktur/{$id}/ajukan-approval")->assertStatus(200);

        app(\App\Modules\Approval\ApprovalService::class)->putuskanUntukReferensi(
            'faktur', $id, $approver->id_pengguna, 'tolak', 'Total tidak sesuai PO', self::PERUSAHAAN_ID
        );

        $this->assertDatabaseHas('faktur', [
            'id_faktur' => $id, 'status' => 'draft', 'alasan_ditolak_internal' => 'Total tidak sesuai PO',
        ]);
    }

    public function test_update_faktur_tidak_bisa_lompati_gate_lewat_field_status(): void
    {
        $keuangan = $this->actingAsRole('SUPERADMIN');
        $id = $this->buatFakturDraft($keuangan->id_pengguna);

        $res = $this->putJson("/api/faktur/{$id}", ['nomor_faktur' => 'FK-UBAH', 'status' => 'terkirim']);
        $res->assertStatus(200);

        $this->assertDatabaseHas('faktur', ['id_faktur' => $id, 'status' => 'draft']);
    }

    public function test_store_faktur_tidak_bisa_lompati_gate_lewat_field_status(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/faktur', ['nomor_faktur' => 'FK-BYPASS', 'status' => 'terkirim']);
        $res->assertStatus(201)->assertJsonPath('data.status', 'draft');
    }

    public function test_status_batal_langsung_dari_draft_tetap_valid_tanpa_approval(): void
    {
        $keuangan = $this->actingAsRole('SUPERADMIN');
        $id = $this->buatFakturDraft($keuangan->id_pengguna);

        $this->patchJson("/api/faktur/{$id}/status", ['status' => 'batal'])->assertStatus(200);
    }

    public function test_transisi_batal_ke_lunas_langsung_ditolak_422(): void
    {
        $keuangan = $this->actingAsRole('SUPERADMIN');
        $id = $this->buatFakturDraft($keuangan->id_pengguna);
        $this->patchJson("/api/faktur/{$id}/status", ['status' => 'batal'])->assertStatus(200);

        $this->patchJson("/api/faktur/{$id}/status", ['status' => 'lunas'])->assertStatus(422);
    }

    public function test_transisi_draft_ke_terkirim_langsung_lewat_endpoint_status_ditolak_422(): void
    {
        $keuangan = $this->actingAsRole('SUPERADMIN');
        $id = $this->buatFakturDraft($keuangan->id_pengguna);

        $this->patchJson("/api/faktur/{$id}/status", ['status' => 'terkirim'])->assertStatus(422);
    }

    public function test_update_invoice_menunggu_approval_ditolak_422(): void
    {
        $approver = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => 'KEUANGAN',
            'username' => 'km_' . Str::random(6), 'email' => Str::random(6) . '@test.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        $this->makeEventTypeDanApprover($approver->id_pengguna);
        $keuangan = $this->actingAsRole('SUPERADMIN');
        $id = $this->buatFakturDraft($keuangan->id_pengguna);
        $this->postJson("/api/faktur/{$id}/ajukan-approval")->assertStatus(200);

        $res = $this->putJson("/api/faktur/{$id}", [
            'items' => [
                ['deskripsi' => 'Jasa angkut baru', 'qty' => 1, 'harga_satuan' => 500000000],
            ],
        ]);

        $res->assertStatus(422);
        $this->assertDatabaseHas('faktur', ['id_faktur' => $id, 'total' => 3000000]);
    }

    public function test_hapus_invoice_menunggu_approval_ditolak_422(): void
    {
        $approver = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => 'KEUANGAN',
            'username' => 'km_' . Str::random(6), 'email' => Str::random(6) . '@test.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        $this->makeEventTypeDanApprover($approver->id_pengguna);
        $keuangan = $this->actingAsRole('SUPERADMIN');
        $id = $this->buatFakturDraft($keuangan->id_pengguna);
        $this->postJson("/api/faktur/{$id}/ajukan-approval")->assertStatus(200);

        $this->deleteJson("/api/faktur/{$id}")->assertStatus(422);

        $this->assertDatabaseHas('faktur', ['id_faktur' => $id, 'dihapus_pada' => null]);
    }
}
