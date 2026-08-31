<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ApprovalDiputuskan;
use App\Modules\KontrakVendor\KontrakVendorModel;
use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KontrakVendorApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function makeVendor(): VendorModel
    {
        return VendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => 'Vendor Approval Test',
        ]);
    }

    private function makeEventTypeDanApprover(string $idPenggunaApprover): void
    {
        $idJabatan = (string) Str::uuid();
        DB::table('jabatan')->insert([
            'id_jabatan' => $idJabatan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jabatan' => 'PROCMGR', 'nama_jabatan' => 'Procurement Manager', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $idKaryawan = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $idKaryawan, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_jabatan' => $idJabatan,
            'nik' => 'NIK-' . Str::random(6), 'nama_karyawan' => 'Procurement Manager Test', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('pengguna')->where('id_pengguna', $idPenggunaApprover)->update(['id_karyawan' => $idKaryawan]);

        $idEventType = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $idEventType, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => 'kontrak_vendor', 'nama' => 'Kontrak Vendor', 'mode_resolusi' => 'pinned',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);
    }

    private function makeApprover(): string
    {
        $id = (string) Str::uuid();
        DB::table('pengguna')->insert([
            'id_pengguna' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => 'MANAGER',
            'username' => 'pm_' . Str::random(6), 'email' => Str::random(6) . '@test.id',
            'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        return $id;
    }

    private function makeKontrakAktif(string $idVendor): KontrakVendorModel
    {
        return KontrakVendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_vendor'     => $idVendor,
            'mekanisme'     => 'unit_only',
            'nomor_kontrak' => 'KV-KOMP-' . Str::random(5),
            'status'        => 'aktif',
        ]);
    }

    public function test_tambah_unit_ke_kontrak_aktif_memicu_approval_ulang(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $kontrak = $this->makeKontrakAktif($vendor->id_vendor);

        $this->postJson('/api/armada-vendor', [
            'id_vendor'         => $vendor->id_vendor,
            'nopol'             => 'B 1 RA',
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'masa_berlaku_stnk' => '2027-06-01',
            'masa_berlaku_kir'  => '2027-06-02',
        ])->assertStatus(201);

        $this->assertSame('draft', $kontrak->fresh()->status);
    }

    public function test_hapus_unit_dari_kontrak_aktif_memicu_approval_ulang(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $kontrak = $this->makeKontrakAktif($vendor->id_vendor);
        $idArmada = (string) Str::uuid();
        DB::table('armada_vendor')->insert([
            'id_armada_vendor' => $idArmada, 'id_vendor' => $vendor->id_vendor,
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'nopol' => 'B 2 RA', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        $this->deleteJson("/api/armada-vendor/{$idArmada}")->assertStatus(200);

        $this->assertSame('draft', $kontrak->fresh()->status);
    }

    public function test_edit_unit_tanpa_ubah_komposisi_tidak_memicu_approval_ulang(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $kontrak = $this->makeKontrakAktif($vendor->id_vendor);
        $idArmada = (string) Str::uuid();
        DB::table('armada_vendor')->insert([
            'id_armada_vendor' => $idArmada, 'id_vendor' => $vendor->id_vendor,
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'nopol' => 'B 3 RA', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        $this->putJson("/api/armada-vendor/{$idArmada}", [
            'merk' => 'Hino Baru',
        ])->assertStatus(200);

        $this->assertSame('aktif', $kontrak->fresh()->status);
    }

    public function test_status_referensi_menampilkan_log_approval(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $approver = $this->makeApprover();
        $this->makeEventTypeDanApprover($approver);
        $vendor = $this->makeVendor();

        $create = $this->postJson('/api/kontrak-vendor', [
            'id_vendor' => $vendor->id_vendor,
            'mekanisme' => 'unit_only',
        ]);
        $idKontrak = $create->json('data.id_kontrak_vendor');
        $this->postJson("/api/kontrak-vendor/{$idKontrak}/ajukan-approval")->assertStatus(200);

        $res = $this->getJson('/api/approval-pengajuan/status-referensi?kode=kontrak_vendor&id_referensi=' . $idKontrak);
        $res->assertStatus(200)
            ->assertJsonPath('data.status', 'menunggu')
            ->assertJsonPath('data.progress.disetujui', 0)
            ->assertJsonPath('data.progress.total', 1);
        $this->assertCount(1, $res->json('data.approver'));
        $this->assertSame('menunggu', $res->json('data.approver.0.status'));
    }

    public function test_status_referensi_tanpa_pengajuan_mengembalikan_null(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $res = $this->getJson('/api/approval-pengajuan/status-referensi?kode=kontrak_vendor&id_referensi=' . (string) Str::uuid());
        $res->assertStatus(200);
        $this->assertNull($res->json('data'));
    }

    public function test_export_excel_dan_pdf_kontrak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $kontrak = KontrakVendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_vendor'     => $vendor->id_vendor,
            'mekanisme'     => 'unit_only',
            'nomor_kontrak' => 'KV-EXP-1',
            'status'        => 'aktif',
        ]);

        $this->get("/api/kontrak-vendor/{$kontrak->id_kontrak_vendor}/export/excel")->assertStatus(200);
        $this->get("/api/kontrak-vendor/{$kontrak->id_kontrak_vendor}/export/pdf")->assertStatus(200);
    }

    public function test_create_kontrak_lahir_berstatus_draft(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $res = $this->postJson('/api/kontrak-vendor', [
            'id_vendor' => $vendor->id_vendor,
            'mekanisme' => 'unit_only',
            'status'    => 'aktif',
        ]);

        $res->assertStatus(201)->assertJsonPath('data.status', 'draft');
    }

    public function test_ajukan_approval_pindah_ke_menunggu_dan_membuat_pengajuan(): void
    {
        $idApprover = $this->makeApprover();
        $this->makeEventTypeDanApprover($idApprover);
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $kontrak = KontrakVendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_vendor'     => $vendor->id_vendor,
            'mekanisme'     => 'unit_driver',
            'status'        => 'draft',
            'nilai_kontrak' => 50000000,
        ]);

        $this->postJson("/api/kontrak-vendor/{$kontrak->id_kontrak_vendor}/ajukan-approval")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'menunggu_approval');

        $this->assertDatabaseHas('approval_pengajuan', [
            'id_referensi' => $kontrak->id_kontrak_vendor,
            'status'       => 'menunggu',
        ]);
    }

    public function test_ajukan_approval_dari_status_bukan_draft_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $kontrak = KontrakVendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_vendor'     => $vendor->id_vendor,
            'mekanisme'     => 'unit_only',
            'status'        => 'aktif',
        ]);

        $this->postJson("/api/kontrak-vendor/{$kontrak->id_kontrak_vendor}/ajukan-approval")->assertStatus(422);
    }

    public function test_keputusan_disetujui_mengaktifkan_kontrak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $kontrak = KontrakVendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_vendor'     => $vendor->id_vendor,
            'mekanisme'     => 'unit_driver',
            'status'        => 'menunggu_approval',
        ]);

        event(new ApprovalDiputuskan(
            self::PERUSAHAAN_ID,
            (string) Str::uuid(),
            (string) Str::uuid(),
            'kontrak_vendor',
            $kontrak->id_kontrak_vendor,
            'disetujui',
            null,
        ));

        $this->assertSame('aktif', $kontrak->fresh()->status);
    }

    public function test_keputusan_ditolak_kembali_draft_dengan_alasan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $kontrak = KontrakVendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_vendor'     => $vendor->id_vendor,
            'mekanisme'     => 'unit_driver',
            'status'        => 'menunggu_approval',
        ]);

        event(new ApprovalDiputuskan(
            self::PERUSAHAAN_ID,
            (string) Str::uuid(),
            (string) Str::uuid(),
            'kontrak_vendor',
            $kontrak->id_kontrak_vendor,
            'ditolak',
            'Nilai kontrak terlalu tinggi',
        ));

        $segar = $kontrak->fresh();
        $this->assertSame('draft', $segar->status);
        $this->assertSame('Nilai kontrak terlalu tinggi', $segar->alasan_ditolak_internal);
    }

    public function test_ubah_nilai_kontrak_aktif_kembali_ke_draft(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $kontrak = KontrakVendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_vendor'     => $vendor->id_vendor,
            'mekanisme'     => 'unit_only',
            'status'        => 'aktif',
            'nilai_kontrak' => 10000000,
        ]);

        $this->putJson("/api/kontrak-vendor/{$kontrak->id_kontrak_vendor}", [
            'nilai_kontrak' => 20000000,
        ])->assertStatus(200)->assertJsonPath('data.status', 'draft');

        $this->putJson("/api/kontrak-vendor/{$kontrak->id_kontrak_vendor}", [
            'nomor_kontrak' => 'ADM-01',
        ])->assertStatus(200)->assertJsonPath('data.status', 'draft');
    }

    public function test_update_administratif_kontrak_aktif_tidak_menurunkan_status(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $kontrak = KontrakVendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_vendor'     => $vendor->id_vendor,
            'mekanisme'     => 'unit_only',
            'status'        => 'aktif',
            'nilai_kontrak' => 10000000,
        ]);

        $this->putJson("/api/kontrak-vendor/{$kontrak->id_kontrak_vendor}", [
            'nomor_kontrak' => 'ADM-02',
            'nilai_kontrak' => 10000000,
        ])->assertStatus(200)->assertJsonPath('data.status', 'aktif');
    }

    public function test_update_dengan_perubahan_saat_menunggu_menarik_pengajuan_dan_kembali_draft(): void
    {
        $idApprover = $this->makeApprover();
        $this->makeEventTypeDanApprover($idApprover);
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $create = $this->postJson('/api/kontrak-vendor', [
            'id_vendor'     => $vendor->id_vendor,
            'mekanisme'     => 'unit_only',
            'nomor_kontrak' => 'KV-M-1',
        ]);
        $idKontrak = $create->json('data.id_kontrak_vendor');
        $this->postJson("/api/kontrak-vendor/{$idKontrak}/ajukan-approval")->assertStatus(200);

        $this->putJson("/api/kontrak-vendor/{$idKontrak}", [
            'nomor_kontrak' => 'KV-M-2',
        ])->assertStatus(200)->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('approval_pengajuan', ['id_referensi' => $idKontrak, 'status' => 'dibatalkan']);
        $this->assertDatabaseMissing('approval_pengajuan', ['id_referensi' => $idKontrak, 'status' => 'menunggu']);
    }

    public function test_update_tanpa_perubahan_saat_menunggu_tidak_mengubah_status(): void
    {
        $idApprover = $this->makeApprover();
        $this->makeEventTypeDanApprover($idApprover);
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $create = $this->postJson('/api/kontrak-vendor', [
            'id_vendor'     => $vendor->id_vendor,
            'mekanisme'     => 'unit_only',
            'nomor_kontrak' => 'KV-M-1',
        ]);
        $idKontrak = $create->json('data.id_kontrak_vendor');
        $this->postJson("/api/kontrak-vendor/{$idKontrak}/ajukan-approval")->assertStatus(200);

        $this->putJson("/api/kontrak-vendor/{$idKontrak}", [
            'nomor_kontrak' => 'KV-M-1',
        ])->assertStatus(200)->assertJsonPath('data.status', 'menunggu_approval');

        $this->assertDatabaseHas('approval_pengajuan', ['id_referensi' => $idKontrak, 'status' => 'menunggu']);
    }

    public function test_tambah_unit_saat_menunggu_menarik_pengajuan_dan_kembali_draft(): void
    {
        $idApprover = $this->makeApprover();
        $this->makeEventTypeDanApprover($idApprover);
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $create = $this->postJson('/api/kontrak-vendor', [
            'id_vendor' => $vendor->id_vendor,
            'mekanisme' => 'unit_only',
        ]);
        $idKontrak = $create->json('data.id_kontrak_vendor');
        $this->postJson("/api/kontrak-vendor/{$idKontrak}/ajukan-approval")->assertStatus(200);

        $this->postJson('/api/armada-vendor', [
            'id_vendor'         => $vendor->id_vendor,
            'nopol'             => 'B 9 MN',
            'id_kontrak_vendor' => $idKontrak,
            'masa_berlaku_stnk' => '2027-06-01',
            'masa_berlaku_kir'  => '2027-06-02',
        ])->assertStatus(201);

        $this->assertSame('draft', KontrakVendorModel::find($idKontrak)->status);
        $this->assertDatabaseMissing('approval_pengajuan', ['id_referensi' => $idKontrak, 'status' => 'menunggu']);
    }

    public function test_unit_kontrak_draft_tidak_bisa_ditugaskan_dari_board(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $kontrak = KontrakVendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_vendor'     => $vendor->id_vendor,
            'mekanisme'     => 'unit_only',
            'status'        => 'draft',
        ]);
        $idArmadaVendor = (string) Str::uuid();
        DB::table('armada_vendor')->insert([
            'id_armada_vendor' => $idArmadaVendor, 'id_vendor' => $vendor->id_vendor,
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor, 'nopol' => 'B 1000 AP',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $idSupir = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $idSupir, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => 'Supir Approval Guard', 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);
        $idKlien = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien' => $idKlien, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien' => 'KLN-' . Str::random(6), 'nama_klien' => 'Klien AP', 'dibuat_pada' => now(),
        ]);
        $idProyek = (string) Str::uuid();
        DB::table('proyek')->insert([
            'id_proyek' => $idProyek, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_klien' => $idKlien,
            'kode_proyek' => 'PRJ-' . Str::random(6), 'nama_proyek' => 'Proyek AP', 'dibuat_pada' => now(),
        ]);
        $idRute = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute' => $idRute, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_rute' => 'RT-' . Str::random(6), 'nama_rute' => 'Rute AP', 'asal' => 'A', 'tujuan' => 'B', 'dibuat_pada' => now(),
        ]);
        DB::table('proyek_rute')->insert([
            'id_proyek_rute' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_proyek' => $idProyek, 'id_rute' => $idRute, 'dibuat_pada' => now(),
        ]);

        $this->postJson('/api/penugasan/harian', [
            'tanggal'          => now()->toDateString(),
            'id_armada_vendor' => $idArmadaVendor,
            'id_supir'         => $idSupir,
            'id_proyek'        => $idProyek,
            'id_rute'          => $idRute,
        ])->assertStatus(422);
    }
}
