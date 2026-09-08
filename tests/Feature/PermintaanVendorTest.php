<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ApprovalDiputuskan;
use App\Modules\PermintaanVendor\PermintaanVendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PermintaanVendorTest extends TestCase
{
    use RefreshDatabase;

    private function makePermintaan(array $overrides = []): PermintaanVendorModel
    {
        return PermintaanVendorModel::create(array_merge([
            'id_perusahaan'    => self::PERUSAHAAN_ID,
            'nomor_permintaan' => 'PMV-TEST-' . Str::random(6),
            'jumlah_unit'      => 2,
            'mekanisme'        => 'unit_only',
            'status'           => 'draft',
        ], $overrides));
    }

    private function makeApprover(): string
    {
        $id = (string) Str::uuid();
        DB::table('pengguna')->insert([
            'id_pengguna' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => 'MANAGER',
            'username' => 'pv_' . Str::random(6), 'email' => Str::random(6) . '@test.id',
            'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        return $id;
    }

    private function makeJenisKendaraan(string $kode, string $nama): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jenis' => $kode, 'nama_jenis' => $nama, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
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
            'kode' => 'permintaan_vendor', 'nama' => 'Permintaan Vendor', 'mode_resolusi' => 'pinned',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);
    }

    public function test_create_permintaan_lahir_draft_dengan_nomor_otomatis(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/permintaan-vendor', [
            'mekanisme'   => 'unit_driver',
            'jumlah_unit' => 3,
            'catatan'     => 'Butuh armada tambahan proyek baru',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.jumlah_unit', 3)
            ->assertJsonPath('data.mekanisme', 'unit_driver');
        $this->assertMatchesRegularExpression('/^PMV-\d{6}-\d{4}$/', $res->json('data.nomor_permintaan'));
    }

    public function test_create_dengan_jumlah_unit_default_satu(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/permintaan-vendor', [
            'mekanisme' => 'unit_only',
        ]);

        $res->assertStatus(201)->assertJsonPath('data.jumlah_unit', 1);
    }

    public function test_create_dengan_proyek_tenant_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert([
            'id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain PV', 'dibuat_pada' => now(),
        ]);
        $idKlienLain = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien' => $idKlienLain, 'id_perusahaan' => $idPerusahaanLain,
            'kode_klien' => 'KLN-LAIN', 'nama_klien' => 'Klien Lain', 'dibuat_pada' => now(),
        ]);
        $idProyekLain = (string) Str::uuid();
        DB::table('proyek')->insert([
            'id_proyek' => $idProyekLain, 'id_perusahaan' => $idPerusahaanLain, 'id_klien' => $idKlienLain,
            'kode_proyek' => 'PRJ-LAIN', 'nama_proyek' => 'Proyek Lain', 'dibuat_pada' => now(),
        ]);

        $this->postJson('/api/permintaan-vendor', [
            'mekanisme' => 'unit_only',
            'id_proyek' => $idProyekLain,
        ])->assertStatus(404);
    }

    public function test_update_draft_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $permintaan = $this->makePermintaan();

        $this->putJson("/api/permintaan-vendor/{$permintaan->id_permintaan}", [
            'jumlah_unit'    => 5,
            'mekanisme'      => 'full',
            'periode_dari'   => '2026-10-01',
            'periode_sampai' => '2026-12-31',
        ])->assertStatus(200)
            ->assertJsonPath('data.jumlah_unit', 5)
            ->assertJsonPath('data.mekanisme', 'full')
            ->assertJsonPath('data.status', 'draft');
    }

    public function test_update_dan_hapus_ditolak_untuk_status_terkunci(): void
    {
        $this->actingAsRole('SUPERADMIN');

        foreach (['menunggu_approval', 'disetujui', 'dikontrakkan'] as $status) {
            $permintaan = $this->makePermintaan(['status' => $status]);

            $this->putJson("/api/permintaan-vendor/{$permintaan->id_permintaan}", [
                'jumlah_unit' => 9,
            ])->assertStatus(422);

            $this->deleteJson("/api/permintaan-vendor/{$permintaan->id_permintaan}")
                ->assertStatus(422);
        }
    }

    public function test_hapus_draft_soft_delete(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $permintaan = $this->makePermintaan();

        $this->deleteJson("/api/permintaan-vendor/{$permintaan->id_permintaan}")
            ->assertStatus(200);

        $this->assertNotNull(DB::table('permintaan_vendor')
            ->where('id_permintaan', $permintaan->id_permintaan)->value('dihapus_pada'));
    }

    public function test_show_dan_update_permintaan_tenant_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert([
            'id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain PV2', 'dibuat_pada' => now(),
        ]);
        $permintaanLain = $this->makePermintaan(['id_perusahaan' => $idPerusahaanLain]);

        $this->getJson("/api/permintaan-vendor/{$permintaanLain->id_permintaan}")->assertStatus(404);
        $this->putJson("/api/permintaan-vendor/{$permintaanLain->id_permintaan}", ['jumlah_unit' => 3])->assertStatus(404);
        $this->deleteJson("/api/permintaan-vendor/{$permintaanLain->id_permintaan}")->assertStatus(404);
    }

    public function test_ajukan_approval_pindah_ke_menunggu_dan_membuat_pengajuan(): void
    {
        $approver = $this->makeApprover();
        $this->makeEventTypeDanApprover($approver);
        $this->actingAsRole('SUPERADMIN');
        $permintaan = $this->makePermintaan();

        $this->postJson("/api/permintaan-vendor/{$permintaan->id_permintaan}/ajukan-approval")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'menunggu_approval');

        $this->assertDatabaseHas('approval_pengajuan', [
            'id_referensi' => $permintaan->id_permintaan,
            'status'       => 'menunggu',
        ]);
    }

    public function test_ajukan_approval_tanpa_event_type_langsung_disetujui(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $permintaan = $this->makePermintaan();

        $res = $this->postJson("/api/permintaan-vendor/{$permintaan->id_permintaan}/ajukan-approval");
        $res->assertStatus(200);
        $this->assertSame('disetujui', $res->json('data.status'));
        $this->assertSame('disetujui', $permintaan->fresh()->status);
        $this->assertDatabaseMissing('approval_pengajuan', [
            'id_referensi' => $permintaan->id_permintaan,
        ]);
    }

    public function test_ajukan_approval_dari_status_bukan_draft_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $permintaan = $this->makePermintaan(['status' => 'disetujui']);

        $this->postJson("/api/permintaan-vendor/{$permintaan->id_permintaan}/ajukan-approval")
            ->assertStatus(422);
    }

    public function test_keputusan_disetujui_mengubah_status_disetujui(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $permintaan = $this->makePermintaan(['status' => 'menunggu_approval']);

        event(new ApprovalDiputuskan(
            self::PERUSAHAAN_ID,
            (string) Str::uuid(),
            (string) Str::uuid(),
            'permintaan_vendor',
            $permintaan->id_permintaan,
            'disetujui',
            null,
        ));

        $this->assertSame('disetujui', $permintaan->fresh()->status);
    }

    public function test_keputusan_ditolak_menjadi_ditolak_dengan_alasan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $permintaan = $this->makePermintaan(['status' => 'menunggu_approval']);

        event(new ApprovalDiputuskan(
            self::PERUSAHAAN_ID,
            (string) Str::uuid(),
            (string) Str::uuid(),
            'permintaan_vendor',
            $permintaan->id_permintaan,
            'ditolak',
            'Jumlah unit terlalu banyak',
        ));

        $segar = $permintaan->fresh();
        $this->assertSame('ditolak', $segar->status);
        $this->assertSame('Jumlah unit terlalu banyak', $segar->alasan_ditolak);
    }

    public function test_listener_mengabaikan_kode_event_lain(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $permintaan = $this->makePermintaan(['status' => 'menunggu_approval']);

        event(new ApprovalDiputuskan(
            self::PERUSAHAAN_ID,
            (string) Str::uuid(),
            (string) Str::uuid(),
            'kontrak_vendor',
            $permintaan->id_permintaan,
            'disetujui',
            null,
        ));

        $this->assertSame('menunggu_approval', $permintaan->fresh()->status);
    }

    public function test_edit_saat_ditolak_kembali_draft_dan_alasan_terhapus(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $permintaan = $this->makePermintaan([
            'status'         => 'ditolak',
            'alasan_ditolak' => 'Jumlah unit terlalu banyak',
        ]);

        $this->putJson("/api/permintaan-vendor/{$permintaan->id_permintaan}", [
            'jumlah_unit' => 1,
        ])->assertStatus(200)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.alasan_ditolak', null);
    }

    public function test_ringkasan_referensi_muncul_di_menunggu_saya(): void
    {
        $approver = $this->makeApprover();
        $this->makeEventTypeDanApprover($approver);

        $idKlien = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien' => $idKlien, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien' => 'KLN-PV', 'nama_klien' => 'Klien PV', 'dibuat_pada' => now(),
        ]);
        $idProyek = (string) Str::uuid();
        DB::table('proyek')->insert([
            'id_proyek' => $idProyek, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_klien' => $idKlien,
            'kode_proyek' => 'PRJ-PV', 'nama_proyek' => 'Proyek Ringkasan PV', 'dibuat_pada' => now(),
        ]);
        $idJenis = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $idJenis, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jenis' => 'TRONTON', 'nama_jenis' => 'Tronton', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        $pengaju = $this->actingAsRole('SUPERADMIN');
        $permintaan = $this->makePermintaan([
            'nomor_permintaan'   => 'PMV-RINGKAS-1',
            'id_proyek'          => $idProyek,
            'id_jenis_kendaraan' => $idJenis,
            'jumlah_unit'        => 3,
            'mekanisme'          => 'unit_driver',
        ]);

        app(\App\Modules\Approval\ApprovalService::class)
            ->ajukan('permintaan_vendor', $permintaan->id_permintaan, $pengaju->id_pengguna, null, self::PERUSAHAAN_ID);

        \Laravel\Sanctum\Sanctum::actingAs(\App\Models\Pengguna::find($approver), ['*']);
        $res = $this->getJson('/api/approval-pengajuan/menunggu-saya');

        $res->assertStatus(200)
            ->assertJsonPath('data.0.kode_event_type', 'permintaan_vendor')
            ->assertJsonPath('data.0.nomor_referensi', 'PMV-RINGKAS-1')
            ->assertJsonPath('data.0.keterangan_referensi', '3 unit Tronton · Unit + Driver')
            ->assertJsonPath('data.0.pihak_referensi', 'Proyek Ringkasan PV');
    }

    public function test_ringkasan_referensi_tanpa_jenis_dan_proyek_pakai_fallback(): void
    {
        $approver = $this->makeApprover();
        $this->makeEventTypeDanApprover($approver);

        $pengaju = $this->actingAsRole('SUPERADMIN');
        $permintaan = $this->makePermintaan([
            'nomor_permintaan' => 'PMV-RINGKAS-2',
            'jumlah_unit'      => 2,
            'mekanisme'        => 'unit_only',
        ]);

        app(\App\Modules\Approval\ApprovalService::class)
            ->ajukan('permintaan_vendor', $permintaan->id_permintaan, $pengaju->id_pengguna, null, self::PERUSAHAAN_ID);

        \Laravel\Sanctum\Sanctum::actingAs(\App\Models\Pengguna::find($approver), ['*']);
        $res = $this->getJson('/api/approval-pengajuan/menunggu-saya');

        $res->assertStatus(200)
            ->assertJsonPath('data.0.nomor_referensi', 'PMV-RINGKAS-2')
            ->assertJsonPath('data.0.keterangan_referensi', '2 unit unit · Unit Only')
            ->assertJsonPath('data.0.pihak_referensi', null);
    }

    public function test_create_multi_baris_unit_tersimpan_dan_total_benar(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idCdd = $this->makeJenisKendaraan('CDD', 'CDD');
        $idTronton = $this->makeJenisKendaraan('TRONTON', 'Tronton');

        $res = $this->postJson('/api/permintaan-vendor', [
            'mekanisme' => 'unit_only',
            'unit'      => [
                ['id_jenis_kendaraan' => $idCdd, 'jumlah_unit' => 2],
                ['id_jenis_kendaraan' => $idTronton, 'jumlah_unit' => 1],
            ],
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.jumlah_unit', 3)
            ->assertJsonPath('data.id_jenis_kendaraan', $idCdd)
            ->assertJsonPath('data.nama_jenis_kendaraan', 'CDD')
            ->assertJsonCount(2, 'data.unit_diminta')
            ->assertJsonPath('data.unit_diminta.0.id_jenis_kendaraan', $idCdd)
            ->assertJsonPath('data.unit_diminta.0.nama_jenis_kendaraan', 'CDD')
            ->assertJsonPath('data.unit_diminta.0.jumlah_unit', 2)
            ->assertJsonPath('data.unit_diminta.1.id_jenis_kendaraan', $idTronton)
            ->assertJsonPath('data.unit_diminta.1.nama_jenis_kendaraan', 'Tronton')
            ->assertJsonPath('data.unit_diminta.1.jumlah_unit', 1);

        $this->assertSame(2, DB::table('permintaan_vendor_unit')
            ->where('id_permintaan', $res->json('data.id_permintaan'))
            ->whereNull('dihapus_pada')
            ->count());
    }

    public function test_create_unit_duplikat_jenis_sama_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idCdd = $this->makeJenisKendaraan('CDD', 'CDD');

        $res = $this->postJson('/api/permintaan-vendor', [
            'mekanisme' => 'unit_only',
            'unit'      => [
                ['id_jenis_kendaraan' => $idCdd, 'jumlah_unit' => 2],
                ['id_jenis_kendaraan' => $idCdd, 'jumlah_unit' => 1],
            ],
        ]);

        $res->assertStatus(422);
        $this->assertSame('Jenis kendaraan duplikat di daftar unit', $res->json('message'));
    }

    public function test_create_unit_duplikat_dua_baris_null_422(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/permintaan-vendor', [
            'mekanisme' => 'unit_only',
            'unit'      => [
                ['jumlah_unit' => 2],
                ['jumlah_unit' => 1],
            ],
        ]);

        $res->assertStatus(422);
        $this->assertSame('Jenis kendaraan duplikat di daftar unit', $res->json('message'));
    }

    public function test_create_unit_jenis_tenant_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert([
            'id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain PV Unit', 'dibuat_pada' => now(),
        ]);
        $idJenisLain = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $idJenisLain, 'id_perusahaan' => $idPerusahaanLain,
            'kode_jenis' => 'LAIN', 'nama_jenis' => 'Jenis Lain', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        $this->postJson('/api/permintaan-vendor', [
            'mekanisme' => 'unit_only',
            'unit'      => [
                ['id_jenis_kendaraan' => $idJenisLain, 'jumlah_unit' => 1],
            ],
        ])->assertStatus(404);
    }

    public function test_create_tanpa_unit_fallback_payload_lama_tetap_jalan(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/permintaan-vendor', [
            'mekanisme'   => 'unit_driver',
            'jumlah_unit' => 4,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.jumlah_unit', 4)
            ->assertJsonPath('data.id_jenis_kendaraan', null)
            ->assertJsonCount(1, 'data.unit_diminta')
            ->assertJsonPath('data.unit_diminta.0.id_jenis_kendaraan', null)
            ->assertJsonPath('data.unit_diminta.0.jumlah_unit', 4);
    }

    public function test_update_replace_unit_items_penuh(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idCdd = $this->makeJenisKendaraan('CDD', 'CDD');
        $idTronton = $this->makeJenisKendaraan('TRONTON', 'Tronton');

        $buat = $this->postJson('/api/permintaan-vendor', [
            'mekanisme' => 'unit_only',
            'unit'      => [
                ['id_jenis_kendaraan' => $idCdd, 'jumlah_unit' => 2],
                ['id_jenis_kendaraan' => $idTronton, 'jumlah_unit' => 1],
            ],
        ]);
        $id = $buat->json('data.id_permintaan');

        $res = $this->putJson("/api/permintaan-vendor/{$id}", [
            'unit' => [
                ['id_jenis_kendaraan' => $idTronton, 'jumlah_unit' => 5],
            ],
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.jumlah_unit', 5)
            ->assertJsonPath('data.id_jenis_kendaraan', $idTronton)
            ->assertJsonCount(1, 'data.unit_diminta')
            ->assertJsonPath('data.unit_diminta.0.id_jenis_kendaraan', $idTronton)
            ->assertJsonPath('data.unit_diminta.0.jumlah_unit', 5);

        $this->assertSame(1, DB::table('permintaan_vendor_unit')
            ->where('id_permintaan', $id)->whereNull('dihapus_pada')->count());
        $this->assertSame(2, DB::table('permintaan_vendor_unit')
            ->where('id_permintaan', $id)->whereNotNull('dihapus_pada')->count());
    }

    public function test_update_tanpa_sentuh_unit_items_tidak_berubah(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idCdd = $this->makeJenisKendaraan('CDD', 'CDD');
        $idTronton = $this->makeJenisKendaraan('TRONTON', 'Tronton');

        $buat = $this->postJson('/api/permintaan-vendor', [
            'mekanisme' => 'unit_only',
            'unit'      => [
                ['id_jenis_kendaraan' => $idCdd, 'jumlah_unit' => 2],
                ['id_jenis_kendaraan' => $idTronton, 'jumlah_unit' => 1],
            ],
        ]);
        $id = $buat->json('data.id_permintaan');

        $res = $this->putJson("/api/permintaan-vendor/{$id}", [
            'catatan' => 'Update catatan saja tanpa ganti unit',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.jumlah_unit', 3)
            ->assertJsonCount(2, 'data.unit_diminta');
    }

    public function test_ringkasan_referensi_multi_jenis_muncul_benar_di_menunggu_saya(): void
    {
        $approver = $this->makeApprover();
        $this->makeEventTypeDanApprover($approver);
        $idCdd = $this->makeJenisKendaraan('CDD', 'CDD');
        $idTronton = $this->makeJenisKendaraan('TRONTON', 'Tronton');

        $this->actingAsRole('SUPERADMIN');
        $buat = $this->postJson('/api/permintaan-vendor', [
            'mekanisme' => 'unit_only',
            'unit'      => [
                ['id_jenis_kendaraan' => $idCdd, 'jumlah_unit' => 2],
                ['id_jenis_kendaraan' => $idTronton, 'jumlah_unit' => 1],
            ],
        ]);
        $id = $buat->json('data.id_permintaan');

        $this->postJson("/api/permintaan-vendor/{$id}/ajukan-approval")->assertStatus(200);

        \Laravel\Sanctum\Sanctum::actingAs(\App\Models\Pengguna::find($approver), ['*']);
        $res = $this->getJson('/api/approval-pengajuan/menunggu-saya');

        $res->assertStatus(200)
            ->assertJsonPath('data.0.kode_event_type', 'permintaan_vendor')
            ->assertJsonPath('data.0.keterangan_referensi', '2 CDD + 1 Tronton · Unit Only');
    }
}
