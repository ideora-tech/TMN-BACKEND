<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvoiceVendorApprovalWiringTest extends TestCase
{
    use RefreshDatabase;

    private function makeVendorLokal(): string
    {
        $id = (string) Str::uuid();
        DB::table('vendor')->insert([
            'id_vendor' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor' => 'VDR-' . Str::random(8),
            'nama_vendor' => 'Vendor Wiring', 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeEventTypeDanApprover(string $idPengguna): void
    {
        $idJabatan = (string) Str::uuid();
        DB::table('jabatan')->insert([
            'id_jabatan' => $idJabatan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jabatan' => 'APMGR', 'nama_jabatan' => 'AP Manager', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $idKaryawan = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $idKaryawan, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_jabatan' => $idJabatan,
            'nik' => 'NIK-' . Str::random(6), 'nama_karyawan' => 'AP Manager Test', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('pengguna')->where('id_pengguna', $idPengguna)->update(['id_karyawan' => $idKaryawan]);

        $idEventType = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $idEventType, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => 'invoice_vendor', 'nama' => 'Invoice Vendor', 'mode_resolusi' => 'pinned',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);
    }

    private function buatInvoiceDraft(string $idPengguna, float $total = 5000000): string
    {
        $id = (string) Str::uuid();
        DB::table('invoice_vendor')->insert([
            'id_invoice_vendor' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_vendor' => $this->makeVendorLokal(), 'nomor_invoice' => 'IV-WIRING-' . Str::random(4),
            'tanggal_invoice' => now()->toDateString(), 'dpp' => $total, 'total' => $total,
            'status' => 'draft', 'status_pembayaran' => 'belum',
            'dibuat_pada' => now(), 'dibuat_oleh' => $idPengguna,
        ]);
        return $id;
    }

    public function test_ajukan_approval_pindah_ke_menunggu_approval_dan_membuat_approval_pengajuan(): void
    {
        $approver = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => 'KEUANGAN',
            'username' => 'ap_' . Str::random(6), 'email' => Str::random(6) . '@test.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        $this->makeEventTypeDanApprover($approver->id_pengguna);
        $keuangan = $this->actingAsRole('SUPERADMIN');
        $id = $this->buatInvoiceDraft($keuangan->id_pengguna);

        $res = $this->postJson("/api/invoice-vendor/{$id}/ajukan-approval");
        $res->assertStatus(200)->assertJsonPath('data.status', 'menunggu_approval');

        $this->assertDatabaseHas('approval_pengajuan', ['id_referensi' => $id, 'status' => 'menunggu']);
    }

    public function test_ajukan_approval_bukan_draft_ditolak_422(): void
    {
        $keuangan = $this->actingAsRole('SUPERADMIN');
        $id = $this->buatInvoiceDraft($keuangan->id_pengguna);
        DB::table('invoice_vendor')->where('id_invoice_vendor', $id)->update(['status' => 'diverifikasi']);

        $this->postJson("/api/invoice-vendor/{$id}/ajukan-approval")->assertStatus(422);
    }

    public function test_keputusan_disetujui_set_diverifikasi_dengan_approver(): void
    {
        $approver = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => 'KEUANGAN',
            'username' => 'ap_' . Str::random(6), 'email' => Str::random(6) . '@test.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        $this->makeEventTypeDanApprover($approver->id_pengguna);
        $keuangan = $this->actingAsRole('SUPERADMIN');
        $id = $this->buatInvoiceDraft($keuangan->id_pengguna);
        $this->postJson("/api/invoice-vendor/{$id}/ajukan-approval")->assertStatus(200);

        app(\App\Modules\Approval\ApprovalService::class)->putuskanUntukReferensi(
            'invoice_vendor', $id, $approver->id_pengguna, 'setuju', null, self::PERUSAHAAN_ID
        );

        $this->assertDatabaseHas('invoice_vendor', [
            'id_invoice_vendor' => $id, 'status' => 'diverifikasi', 'diverifikasi_oleh' => $approver->id_pengguna,
        ]);
    }

    public function test_keputusan_ditolak_set_ditolak_dengan_catatan(): void
    {
        $approver = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => 'KEUANGAN',
            'username' => 'ap_' . Str::random(6), 'email' => Str::random(6) . '@test.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        $this->makeEventTypeDanApprover($approver->id_pengguna);
        $keuangan = $this->actingAsRole('SUPERADMIN');
        $id = $this->buatInvoiceDraft($keuangan->id_pengguna);
        $this->postJson("/api/invoice-vendor/{$id}/ajukan-approval")->assertStatus(200);

        app(\App\Modules\Approval\ApprovalService::class)->putuskanUntukReferensi(
            'invoice_vendor', $id, $approver->id_pengguna, 'tolak', 'Nominal tidak sesuai kontrak', self::PERUSAHAAN_ID
        );

        $this->assertDatabaseHas('invoice_vendor', [
            'id_invoice_vendor' => $id, 'status' => 'ditolak', 'catatan_verifikasi' => 'Nominal tidak sesuai kontrak',
        ]);
    }

    public function test_keputusan_disetujui_total_nol_langsung_lunas(): void
    {
        $approver = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => 'KEUANGAN',
            'username' => 'ap_' . Str::random(6), 'email' => Str::random(6) . '@test.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        $this->makeEventTypeDanApprover($approver->id_pengguna);
        $keuangan = $this->actingAsRole('SUPERADMIN');
        $id = $this->buatInvoiceDraft($keuangan->id_pengguna, 0);

        $this->postJson("/api/invoice-vendor/{$id}/ajukan-approval")->assertStatus(200);
        app(\App\Modules\Approval\ApprovalService::class)->putuskanUntukReferensi(
            'invoice_vendor', $id, $approver->id_pengguna, 'setuju', null, self::PERUSAHAAN_ID
        );

        $this->assertDatabaseHas('invoice_vendor', [
            'id_invoice_vendor' => $id, 'status' => 'diverifikasi', 'status_pembayaran' => 'lunas',
        ]);
    }

    public function test_edit_invoice_menunggu_approval_ditolak_409(): void
    {
        $keuangan = $this->actingAsRole('SUPERADMIN');
        $id = $this->buatInvoiceDraft($keuangan->id_pengguna);
        DB::table('invoice_vendor')->where('id_invoice_vendor', $id)->update(['status' => 'menunggu_approval']);

        $this->putJson("/api/invoice-vendor/{$id}", ['dpp' => 999])->assertStatus(409);
        $this->deleteJson("/api/invoice-vendor/{$id}")->assertStatus(409);
    }

    public function test_ajukan_approval_ulang_setelah_ditolak_mereset_catatan(): void
    {
        $approver = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => 'KEUANGAN',
            'username' => 'ap_' . Str::random(6), 'email' => Str::random(6) . '@test.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        $this->makeEventTypeDanApprover($approver->id_pengguna);
        $keuangan = $this->actingAsRole('SUPERADMIN');
        $id = $this->buatInvoiceDraft($keuangan->id_pengguna);
        DB::table('invoice_vendor')->where('id_invoice_vendor', $id)
            ->update(['catatan_verifikasi' => 'Alasan penolakan basi']);

        $this->postJson("/api/invoice-vendor/{$id}/ajukan-approval")->assertStatus(200);

        $this->assertDatabaseHas('invoice_vendor', ['id_invoice_vendor' => $id, 'catatan_verifikasi' => null]);
    }

    public function test_siklus_tolak_engine_edit_lalu_ajukan_ulang(): void
    {
        $approver = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => 'KEUANGAN',
            'username' => 'ap_' . Str::random(6), 'email' => Str::random(6) . '@test.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        $this->makeEventTypeDanApprover($approver->id_pengguna);
        $keuangan = $this->actingAsRole('SUPERADMIN');
        $id = $this->buatInvoiceDraft($keuangan->id_pengguna);

        $this->postJson("/api/invoice-vendor/{$id}/ajukan-approval")->assertStatus(200);

        app(\App\Modules\Approval\ApprovalService::class)->putuskanUntukReferensi(
            'invoice_vendor', $id, $approver->id_pengguna, 'tolak', 'Perbaiki dulu', self::PERUSAHAAN_ID
        );

        $this->assertDatabaseHas('invoice_vendor', [
            'id_invoice_vendor' => $id, 'status' => 'ditolak', 'catatan_verifikasi' => 'Perbaiki dulu',
        ]);

        $this->putJson("/api/invoice-vendor/{$id}", ['dpp' => 2000000])->assertStatus(200);

        $this->assertDatabaseHas('invoice_vendor', [
            'id_invoice_vendor' => $id, 'status' => 'draft', 'catatan_verifikasi' => null,
        ]);

        $this->postJson("/api/invoice-vendor/{$id}/ajukan-approval")->assertStatus(200);

        $this->assertDatabaseHas('invoice_vendor', [
            'id_invoice_vendor' => $id, 'status' => 'menunggu_approval',
        ]);
        $this->assertSame(1, DB::table('approval_pengajuan')
            ->where('id_referensi', $id)->where('status', 'menunggu')->count());
    }

    public function test_listener_replay_setelah_keputusan_tidak_mengubah_apa_pun(): void
    {
        $approver = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => 'KEUANGAN',
            'username' => 'ap_' . Str::random(6), 'email' => Str::random(6) . '@test.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        $this->makeEventTypeDanApprover($approver->id_pengguna);
        $keuangan = $this->actingAsRole('SUPERADMIN');
        $id = $this->buatInvoiceDraft($keuangan->id_pengguna);

        $this->postJson("/api/invoice-vendor/{$id}/ajukan-approval")->assertStatus(200);

        app(\App\Modules\Approval\ApprovalService::class)->putuskanUntukReferensi(
            'invoice_vendor', $id, $approver->id_pengguna, 'setuju', null, self::PERUSAHAAN_ID
        );

        $sebelum = DB::table('invoice_vendor')->where('id_invoice_vendor', $id)->first();
        $this->assertSame('diverifikasi', $sebelum->status);
        $this->assertNotNull($sebelum->diverifikasi_oleh);

        event(new \App\Events\ApprovalDiputuskan(
            self::PERUSAHAAN_ID,
            (string) Str::uuid(),
            (string) Str::uuid(),
            'invoice_vendor',
            $id,
            'ditolak',
            'replay jahat',
        ));

        $sesudah = DB::table('invoice_vendor')->where('id_invoice_vendor', $id)->first();
        $this->assertSame('diverifikasi', $sesudah->status);
        $this->assertSame($sebelum->diverifikasi_oleh, $sesudah->diverifikasi_oleh);
        $this->assertNull($sesudah->catatan_verifikasi);
    }

    public function test_ajukan_approval_invoice_perusahaan_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert([
            'id_perusahaan' => $idPerusahaanLain,
            'nama'          => 'Perusahaan Lain Wiring',
            'dibuat_pada'   => now(),
        ]);
        $idVendorLain = (string) Str::uuid();
        DB::table('vendor')->insert([
            'id_vendor' => $idVendorLain, 'id_perusahaan' => $idPerusahaanLain,
            'kode_vendor' => 'VDR-' . Str::random(8), 'nama_vendor' => 'Vendor Lain', 'dibuat_pada' => now(),
        ]);
        $idInvoiceLain = (string) Str::uuid();
        DB::table('invoice_vendor')->insert([
            'id_invoice_vendor' => $idInvoiceLain, 'id_perusahaan' => $idPerusahaanLain,
            'id_vendor' => $idVendorLain, 'nomor_invoice' => 'IV-LAIN-' . Str::random(4),
            'tanggal_invoice' => now()->toDateString(), 'dpp' => 1000000, 'total' => 1000000,
            'status' => 'draft', 'status_pembayaran' => 'belum', 'dibuat_pada' => now(),
        ]);

        $this->postJson("/api/invoice-vendor/{$idInvoiceLain}/ajukan-approval")->assertStatus(404);

        $this->assertSame(0, DB::table('approval_pengajuan')->where('id_referensi', $idInvoiceLain)->count());
    }
}
