<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use App\Modules\Penawaran\PenawaranService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PenawaranApprovalWiringTest extends TestCase
{
    use RefreshDatabase;

    private function makeEventTypeDanApprover(string $idPengguna): void
    {
        $idJabatan = (string) Str::uuid();
        DB::table('jabatan')->insert([
            'id_jabatan' => $idJabatan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jabatan' => 'SLSMGR', 'nama_jabatan' => 'Sales Manager', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $idKaryawan = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $idKaryawan, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_jabatan' => $idJabatan,
            'nik' => 'NIK-' . Str::random(6), 'nama_karyawan' => 'Sales Manager Test', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('pengguna')->where('id_pengguna', $idPengguna)->update(['id_karyawan' => $idKaryawan]);

        $idEventType = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $idEventType, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => 'penawaran', 'nama' => 'Penawaran', 'mode_resolusi' => 'pinned',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);
    }

    private function makeKlien(): string
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien' => 'KLN-' . Str::random(8), 'nama_klien' => 'Klien Wiring Test',
            'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function buatPenawaranDraft(string $idPengguna): string
    {
        $id = (string) Str::uuid();
        DB::table('penawaran')->insert([
            'id_penawaran' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien' => $this->makeKlien(),
            'nomor_penawaran' => 'PNW-WIRING-' . Str::random(4), 'judul' => 'Penawaran Uji Wiring',
            'nilai_penawaran' => 5000000, 'status' => 'draft', 'aktif' => 1,
            'dibuat_pada' => now(), 'dibuat_oleh' => $idPengguna,
        ]);
        $this->tambahItemRute($id);
        return $id;
    }

    private function tambahItemRute(string $idPenawaran): void
    {
        $idRute = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute' => $idRute, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_rute' => 'RT-W' . Str::random(5), 'nama_rute' => 'Rute Wiring',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $idJenis = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $idJenis, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jenis' => 'JW' . Str::random(5), 'nama_jenis' => 'Jenis Wiring',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('penawaran_item')->insert([
            'id_penawaran_item' => (string) Str::uuid(),
            'id_perusahaan'     => self::PERUSAHAAN_ID,
            'id_penawaran'      => $idPenawaran,
            'id_rute'           => $idRute,
            'id_jenis_kendaraan' => $idJenis,
            'harga_satuan'      => 500000,
            'estimasi_ritase'   => 10,
            'subtotal'          => 5000000,
            'dibuat_pada'       => now(),
        ]);
    }

    public function test_ajukan_approval_pindah_ke_menunggu_approval_dan_membuat_approval_pengajuan(): void
    {
        $approver = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => 'MANAGER',
            'username' => 'sm_' . Str::random(6), 'email' => Str::random(6) . '@test.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        $this->makeEventTypeDanApprover($approver->id_pengguna);
        $sales = $this->actingAsRole('SUPERADMIN');
        $id = $this->buatPenawaranDraft($sales->id_pengguna);

        $res = $this->postJson("/api/penawaran/{$id}/ajukan-approval");
        $res->assertStatus(200)->assertJsonPath('data.status', 'menunggu_approval');

        $this->assertDatabaseHas('approval_pengajuan', ['id_referensi' => $id, 'status' => 'menunggu']);
    }

    public function test_detail_penawaran_menyertakan_status_proyek_batal(): void
    {
        $sales = $this->actingAsRole('SUPERADMIN');
        $idProyek = (string) Str::uuid();
        DB::table('proyek')->insert([
            'id_proyek' => $idProyek, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien' => $this->makeKlien(), 'kode_proyek' => 'PRJ-BATAL-1',
            'nama_proyek' => 'Proyek Batal', 'status' => 'batal', 'dibuat_pada' => now(),
        ]);
        $id = $this->buatPenawaranDraft($sales->id_pengguna);
        DB::table('penawaran')->where('id_penawaran', $id)->update(['id_proyek' => $idProyek, 'status' => 'disetujui']);

        $this->getJson("/api/penawaran/{$id}")
            ->assertStatus(200)
            ->assertJsonPath('data.proyek_status', 'batal')
            ->assertJsonPath('data.kode_proyek', 'PRJ-BATAL-1');
    }

    public function test_ajukan_approval_tanpa_item_rute_ditolak_422(): void
    {
        $sales = $this->actingAsRole('SUPERADMIN');
        $id = (string) Str::uuid();
        DB::table('penawaran')->insert([
            'id_penawaran' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien' => $this->makeKlien(),
            'nomor_penawaran' => 'PNW-NO-ITEM', 'judul' => 'Tanpa Item', 'status' => 'draft',
            'tipe_harga' => 'per_rit',
            'aktif' => 1, 'dibuat_pada' => now(), 'dibuat_oleh' => $sales->id_pengguna,
        ]);

        $this->postJson("/api/penawaran/{$id}/ajukan-approval")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Penawaran belum punya item rute — tambahkan minimal 1 rute sebelum diajukan approval');
    }

    public function test_ajukan_approval_dari_status_bukan_draft_ditolak_422(): void
    {
        $sales = $this->actingAsRole('SUPERADMIN');
        $id = (string) Str::uuid();
        DB::table('penawaran')->insert([
            'id_penawaran' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nomor_penawaran' => 'PNW-WIRING-X', 'judul' => 'X', 'status' => 'terkirim',
            'aktif' => 1, 'dibuat_pada' => now(), 'dibuat_oleh' => $sales->id_pengguna,
        ]);

        $this->postJson("/api/penawaran/{$id}/ajukan-approval")->assertStatus(422);
    }

    public function test_keputusan_disetujui_mengubah_status_jadi_terkirim(): void
    {
        $approver = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => 'MANAGER',
            'username' => 'sm_' . Str::random(6), 'email' => Str::random(6) . '@test.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        $this->makeEventTypeDanApprover($approver->id_pengguna);
        $sales = $this->actingAsRole('SUPERADMIN');
        $id = $this->buatPenawaranDraft($sales->id_pengguna);
        $this->postJson("/api/penawaran/{$id}/ajukan-approval")->assertStatus(200);

        app(\App\Modules\Approval\ApprovalService::class)->putuskanUntukReferensi(
            'penawaran', $id, $approver->id_pengguna, 'setuju', null, self::PERUSAHAAN_ID
        );

        $this->assertDatabaseHas('penawaran', ['id_penawaran' => $id, 'status' => 'terkirim']);
    }

    public function test_keputusan_ditolak_mengembalikan_ke_draft_dengan_alasan(): void
    {
        $approver = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => 'MANAGER',
            'username' => 'sm_' . Str::random(6), 'email' => Str::random(6) . '@test.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        $this->makeEventTypeDanApprover($approver->id_pengguna);
        $sales = $this->actingAsRole('SUPERADMIN');
        $id = $this->buatPenawaranDraft($sales->id_pengguna);
        $this->postJson("/api/penawaran/{$id}/ajukan-approval")->assertStatus(200);

        app(\App\Modules\Approval\ApprovalService::class)->putuskanUntukReferensi(
            'penawaran', $id, $approver->id_pengguna, 'tolak', 'Harga terlalu rendah', self::PERUSAHAAN_ID
        );

        $this->assertDatabaseHas('penawaran', [
            'id_penawaran' => $id, 'status' => 'draft', 'alasan_ditolak_internal' => 'Harga terlalu rendah',
        ]);
    }

    public function test_ajukan_approval_ulang_mereset_alasan_ditolak_internal_lama(): void
    {
        $approver = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => 'MANAGER',
            'username' => 'sm_' . Str::random(6), 'email' => Str::random(6) . '@test.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        $this->makeEventTypeDanApprover($approver->id_pengguna);
        $sales = $this->actingAsRole('SUPERADMIN');
        $id = $this->buatPenawaranDraft($sales->id_pengguna);
        DB::table('penawaran')->where('id_penawaran', $id)->update(['alasan_ditolak_internal' => 'Alasan basi dari penolakan sebelumnya']);

        $this->postJson("/api/penawaran/{$id}/ajukan-approval")->assertStatus(200);

        $this->assertDatabaseHas('penawaran', ['id_penawaran' => $id, 'alasan_ditolak_internal' => null]);
    }

    public function test_update_penawaran_tidak_bisa_lompati_gate_lewat_field_status(): void
    {
        $sales = $this->actingAsRole('SUPERADMIN');
        $id = $this->buatPenawaranDraft($sales->id_pengguna);

        $res = $this->putJson("/api/penawaran/{$id}", ['judul' => 'Judul Baru', 'status' => 'terkirim']);
        $res->assertStatus(200);

        $this->assertDatabaseHas('penawaran', ['id_penawaran' => $id, 'status' => 'draft', 'judul' => 'Judul Baru']);
    }

    public function test_ajukan_approval_tanpa_klien_ditolak_422(): void
    {
        $sales = $this->actingAsRole('SUPERADMIN');
        $id = (string) Str::uuid();
        DB::table('penawaran')->insert([
            'id_penawaran' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien' => null,
            'nomor_penawaran' => 'PNW-WIRING-NOKLIEN', 'judul' => 'Penawaran Tanpa Klien',
            'nilai_penawaran' => 5000000, 'status' => 'draft', 'aktif' => 1,
            'dibuat_pada' => now(), 'dibuat_oleh' => $sales->id_pengguna,
        ]);

        $res = $this->postJson("/api/penawaran/{$id}/ajukan-approval");

        $res->assertStatus(422);
        $this->assertDatabaseMissing('approval_pengajuan', ['id_referensi' => $id]);
    }

    public function test_ada_revisi_berjalan_menghitung_status_menunggu_approval(): void
    {
        $idProyek = (string) Str::uuid();
        $idKlien = (string) Str::uuid();
        DB::table('klien')->insert(['id_klien' => $idKlien, 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_klien' => 'K1', 'nama_klien' => 'Klien', 'dibuat_pada' => now()]);
        DB::table('proyek')->insert([
            'id_proyek' => $idProyek, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_klien' => $idKlien, 'kode_proyek' => 'PRJ-1',
            'nama_proyek' => 'Proyek Uji', 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);
        DB::table('penawaran')->insert([
            'id_penawaran' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nomor_penawaran' => 'PNW-REV-1', 'judul' => 'Revisi', 'status' => 'menunggu_approval',
            'id_proyek' => $idProyek, 'id_penawaran_induk' => (string) Str::uuid(), 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        $repo = app(\App\Modules\Penawaran\Contracts\PenawaranRepositoryInterface::class);
        $this->assertTrue($repo->adaRevisiBerjalan($idProyek));
    }
}
