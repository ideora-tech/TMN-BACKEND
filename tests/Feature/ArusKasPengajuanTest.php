<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use App\Modules\ArusKas\ArusKasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ArusKasPengajuanTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $override = []): array
    {
        return array_merge([
            'kategori'          => 'uang_jalan',
            'nominal'           => 500000,
            'tanggal_pengajuan' => now()->toDateString(),
            'penerima'          => 'Budi Supir',
            'keterangan'        => 'Uang jalan trip Jakarta-Bandung',
        ], $override);
    }

    private function setBatasTinggi(): void
    {
        app(ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 999999999);
    }

    private function buatPengajuan(array $override = []): string
    {
        $this->setBatasTinggi();
        $res = $this->postJson('/api/arus-kas/pengajuan', $this->payload($override));
        return $res->json('data.id_pengajuan');
    }

    private function idEventTypePengajuanPengeluaran(): string
    {
        $id = DB::table('approval_event_type')
            ->where('id_perusahaan', self::PERUSAHAAN_ID)->where('kode', 'pengajuan_pengeluaran')->value('id_event_type');
        if ($id !== null) {
            return $id;
        }
        $id = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => 'pengajuan_pengeluaran', 'nama' => 'Pengajuan Pengeluaran',
            'mode_resolusi' => 'pinned', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function buatPengajuanMenungguApproval(string $idApprover, array $override = []): string
    {
        $idEventType = $this->idEventTypePengajuanPengeluaran();
        DB::table('approval_config_approver')->insert([
            'id_config'     => (string) Str::uuid(),
            'id_event_type' => $idEventType,
            'tipe'          => 'pengguna',
            'id_pengguna'   => $idApprover,
            'dibuat_pada'   => now(),
        ]);

        $res = $this->postJson('/api/arus-kas/pengajuan', $this->payload($override));
        $res->assertStatus(201)->assertJsonPath('data.status', 'menunggu_approval');
        return $res->json('data.id_pengajuan');
    }

    public function test_create_pengajuan_berhasil_dengan_nomor_auto(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->setBatasTinggi();

        $res = $this->postJson('/api/arus-kas/pengajuan', $this->payload());

        $res->assertStatus(201)
            ->assertJsonPath('data.status', 'disetujui')
            ->assertJsonPath('data.kategori', 'uang_jalan')
            ->assertJsonPath('data.nominal', 500000);
        $this->assertMatchesRegularExpression('/^PP-\d{6}-0001$/', $res->json('data.nomor_pengajuan'));
    }

    public function test_nomor_urut_bertambah(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->setBatasTinggi();
        $this->postJson('/api/arus-kas/pengajuan', $this->payload())->assertStatus(201);
        $res2 = $this->postJson('/api/arus-kas/pengajuan', $this->payload());
        $this->assertMatchesRegularExpression('/-0002$/', $res2->json('data.nomor_pengajuan'));
    }

    public function test_validasi_field_wajib_dan_kategori_tidak_valid(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->postJson('/api/arus-kas/pengajuan', $this->payload(['penerima' => '']))->assertStatus(422);
        $this->postJson('/api/arus-kas/pengajuan', $this->payload(['kategori' => 'tidak_dikenal']))->assertStatus(422);
        $this->postJson('/api/arus-kas/pengajuan', $this->payload(['nominal' => 0]))->assertStatus(422);
    }

    public function test_upload_bukti_saat_create_tersimpan_di_disk(): void
    {
        Storage::fake('public');
        $this->actingAsRole('SUPERADMIN');
        $this->setBatasTinggi();

        $res = $this->post('/api/arus-kas/pengajuan', array_merge($this->payload(), [
            'bukti' => UploadedFile::fake()->create('kwitansi.pdf', 100, 'application/pdf'),
        ]));

        $res->assertStatus(201);
        $this->assertNotNull($res->json('data.url_bukti'));

        $tersimpan = (string) DB::table('pengajuan_pengeluaran')->orderByDesc('dibuat_pada')->value('url_bukti');
        $this->assertStringStartsWith('bukti-kas/', $tersimpan);
        Storage::disk('public')->assertExists($tersimpan);
    }

    public function test_upload_bukti_lebih_dari_5mb_ditolak(): void
    {
        Storage::fake('public');
        $this->actingAsRole('SUPERADMIN');

        $res = $this->post('/api/arus-kas/pengajuan', array_merge($this->payload(), [
            'bukti' => UploadedFile::fake()->create('kwitansi-besar.pdf', 6000, 'application/pdf'),
        ]));

        $res->assertStatus(422);
    }

    public function test_list_filter_status(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->buatPengajuan();
        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $id)->update(['status' => 'dicek']);
        $this->buatPengajuan();

        $this->assertCount(1, $this->getJson('/api/arus-kas/pengajuan?status=dicek')->json('data'));
        $this->assertCount(2, $this->getJson('/api/arus-kas/pengajuan')->json('data'));
    }

    public function test_show_pengajuan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->buatPengajuan();

        $this->getJson("/api/arus-kas/pengajuan/{$id}")
            ->assertStatus(200)->assertJsonPath('data.id_pengajuan', $id);
    }

    public function test_update_dan_delete_hanya_saat_menunggu_approval_dan_ditolak(): void
    {
        $superadmin = $this->actingAsRole('SUPERADMIN');
        $id = $this->buatPengajuanMenungguApproval($superadmin->id_pengguna);

        $this->putJson("/api/arus-kas/pengajuan/{$id}", $this->payload(['penerima' => 'Budi Baru']))
            ->assertStatus(200)->assertJsonPath('data.penerima', 'Budi Baru');

        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $id)->update(['status' => 'disetujui']);
        $this->putJson("/api/arus-kas/pengajuan/{$id}", $this->payload())->assertStatus(409);
        $this->deleteJson("/api/arus-kas/pengajuan/{$id}")->assertStatus(409);

        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $id)->update(['status' => 'ditolak']);
        $this->deleteJson("/api/arus-kas/pengajuan/{$id}")->assertStatus(200);
        $this->assertSoftDeleted('pengajuan_pengeluaran', ['id_pengajuan' => $id]);
    }

    public function test_alur_transisi_lengkap_sampai_ditransfer(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->putJson('/api/arus-kas/pengaturan-approval', ['batas' => 999999999])->assertStatus(200);
        $id = $this->buatPengajuan();

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/cek")
            ->assertStatus(200)->assertJsonPath('data.status', 'siap_transfer');

        Storage::fake('public');
        $res = $this->patch("/api/arus-kas/pengajuan/{$id}/transfer", [
            'tanggal_transfer' => now()->toDateString(),
            'bukti'            => UploadedFile::fake()->create('bukti-transfer.jpg', 100, 'image/jpeg'),
        ]);
        $res->assertStatus(200)->assertJsonPath('data.status', 'ditransfer');
        $this->assertNotNull($res->json('data.tanggal_transfer'));
        $this->assertNotNull($res->json('data.url_bukti'));

        $row = DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $id)->first();
        $this->assertNotNull($row->dicek_oleh);
        $this->assertNotNull($row->dicek_pada);
        $this->assertNotNull($row->disetujui_oleh);
        $this->assertNotNull($row->disetujui_pada);
        $this->assertNotNull($row->ditransfer_oleh);
        $this->assertNotNull($row->ditransfer_pada);
    }

    public function test_transfer_wajib_tanggal_transfer(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->buatPengajuan();
        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $id)->update(['status' => 'dicek']);

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/transfer", [])->assertStatus(422);
    }

    public function test_transfer_wajib_bukti(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->buatPengajuan();
        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $id)->update(['status' => 'siap_transfer']);

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/transfer", ['tanggal_transfer' => now()->toDateString()])
            ->assertStatus(422)
            ->assertJsonPath('errors.bukti.0', 'Bukti transfer wajib dilampirkan');
    }

    public function test_tolak_dari_disetujui_dan_dari_dicek_dengan_alasan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->buatPengajuan();

        $this->actingAsRole('MANAGER');
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/tolak", ['alasan' => 'Tidak sesuai anggaran'])
            ->assertStatus(200)->assertJsonPath('data.status', 'ditolak')
            ->assertJsonPath('data.alasan_ditolak', 'Tidak sesuai anggaran');

        $this->actingAsRole('SUPERADMIN');
        $id2 = $this->buatPengajuan();
        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $id2)->update(['status' => 'dicek']);

        $this->actingAsRole('MANAGER');
        $this->patchJson("/api/arus-kas/pengajuan/{$id2}/tolak", ['alasan' => 'Dokumen kurang'])
            ->assertStatus(200)->assertJsonPath('data.status', 'ditolak');

        $this->actingAsRole('SUPERADMIN');
        $id3 = $this->buatPengajuan();
        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $id3)->update(['status' => 'siap_transfer']);

        $this->actingAsRole('MANAGER');
        $this->patchJson("/api/arus-kas/pengajuan/{$id3}/tolak", ['alasan' => 'Batal transfer'])
            ->assertStatus(200)->assertJsonPath('data.status', 'ditolak');
    }

    public function test_tolak_wajib_alasan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->buatPengajuan();

        $this->actingAsRole('MANAGER');
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/tolak", [])->assertStatus(422);
    }

    public function test_transisi_tidak_sah_dikembalikan_409(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->buatPengajuan();

        $this->actingAsRole('KEUANGAN');
        $this->patch("/api/arus-kas/pengajuan/{$id}/transfer", ['tanggal_transfer' => now()->toDateString(), 'bukti' => UploadedFile::fake()->create('bukti.jpg', 5, 'image/jpeg')])
            ->assertStatus(409);

        $this->actingAsRole('MANAGER');
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/approval", ['keputusan' => 'setuju'])->assertStatus(409);

        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $id)->update(['status' => 'diajukan']);
        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/cek")->assertStatus(409);
    }

    public function test_guard_peran_cek_dan_transfer_hanya_keuangan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->buatPengajuan();

        $this->actingAsRole('MANAGER');
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/cek")->assertStatus(403);

        $this->actingAsRole('DISPATCHER');
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/cek")->assertStatus(403);
    }

    public function test_approval_oleh_bukan_approver_403_tolak_lama_tetap_dibatasi_role_manager(): void
    {
        $superadmin = $this->actingAsRole('SUPERADMIN');
        $id = $this->buatPengajuanMenungguApproval($superadmin->id_pengguna);

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/approval", ['keputusan' => 'setuju'])->assertStatus(403);
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/tolak", ['alasan' => 'x'])->assertStatus(403);
    }

    public function test_isolasi_tenant_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->buatPengajuan();

        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $id)->update(['id_perusahaan' => $idLain]);

        $this->getJson("/api/arus-kas/pengajuan/{$id}")->assertStatus(404);
        $this->putJson("/api/arus-kas/pengajuan/{$id}", $this->payload())->assertStatus(404);
        $this->deleteJson("/api/arus-kas/pengajuan/{$id}")->assertStatus(404);

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/cek")->assertStatus(404);
    }

    public function test_dispatcher_bisa_membuat_pengajuan_tapi_tidak_bisa_transisi(): void
    {
        $this->setBatasTinggi();
        $this->actingAsRole('DISPATCHER');
        $res = $this->postJson('/api/arus-kas/pengajuan', $this->payload());
        $res->assertStatus(201);
        $id = $res->json('data.id_pengajuan');

        $this->patchJson("/api/arus-kas/pengajuan/{$id}/cek")->assertStatus(403);
    }

    private function buatApprover(string $prefix): Pengguna
    {
        return Pengguna::create([
            'id_pengguna'   => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran'    => 'MANAGER',
            'username'      => $prefix . '_' . Str::random(6),
            'email'         => Str::random(6) . '@test.id',
            'kata_sandi'    => bcrypt('x'),
            'aktif'         => 1,
        ]);
    }

    private function buatEventTypeDenganApprover(string $kode, string $idApprover, bool $aktif = true): string
    {
        $idEventType = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $idEventType, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => $kode, 'nama' => $kode, 'mode_resolusi' => 'pinned',
            'aktif' => $aktif ? 1 : 0, 'dibuat_pada' => now(),
        ]);
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'pengguna', 'id_pengguna' => $idApprover, 'dibuat_pada' => now(),
        ]);
        return $idEventType;
    }

    public function test_create_kategori_dengan_event_type_aktif_menggunakan_kode_kategori_bukan_fallback(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $approver = $this->buatApprover('approver_sparepart');
        $idEventTypeSparepart = $this->buatEventTypeDenganApprover('sparepart', $approver->id_pengguna);

        $res = $this->postJson('/api/arus-kas/pengajuan', $this->payload(['kategori' => 'sparepart', 'nominal' => 500000]));
        $res->assertStatus(201)->assertJsonPath('data.status', 'menunggu_approval');
        $id = $res->json('data.id_pengajuan');

        $this->assertDatabaseHas('approval_pengajuan', [
            'id_referensi'  => $id,
            'id_event_type' => $idEventTypeSparepart,
            'status'        => 'menunggu',
        ]);
    }

    public function test_create_kategori_tanpa_event_type_aktif_fallback_ke_pengajuan_pengeluaran(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $approver = $this->buatApprover('approver_fallback');
        $idFallback = $this->idEventTypePengajuanPengeluaran();
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idFallback,
            'tipe' => 'pengguna', 'id_pengguna' => $approver->id_pengguna, 'dibuat_pada' => now(),
        ]);

        $res = $this->postJson('/api/arus-kas/pengajuan', $this->payload(['kategori' => 'legalitas', 'nominal' => 500000]));
        $res->assertStatus(201)->assertJsonPath('data.status', 'menunggu_approval');
        $id = $res->json('data.id_pengajuan');

        $this->assertDatabaseHas('approval_pengajuan', [
            'id_referensi'  => $id,
            'id_event_type' => $idFallback,
            'status'        => 'menunggu',
        ]);
    }

    public function test_create_fallback_nonaktif_dan_kategori_tidak_ada_dikembalikan_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idFallback = $this->idEventTypePengajuanPengeluaran();
        DB::table('approval_event_type')->where('id_event_type', $idFallback)->update(['aktif' => 0]);

        $this->postJson('/api/arus-kas/pengajuan', $this->payload(['kategori' => 'legalitas', 'nominal' => 500000]))
            ->assertStatus(422);

        $this->assertSame(0, DB::table('pengajuan_pengeluaran')->count());
    }

    public function test_update_saat_menunggu_approval_nominal_turun_dibawah_batas_membatalkan_approval_dan_auto_disetujui(): void
    {
        $superadmin = $this->actingAsRole('SUPERADMIN');
        app(ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 1000000);
        $id = $this->buatPengajuanMenungguApproval($superadmin->id_pengguna, ['nominal' => 5000000]);

        $this->putJson("/api/arus-kas/pengajuan/{$id}", $this->payload(['nominal' => 500000]))
            ->assertStatus(200)->assertJsonPath('data.status', 'disetujui');

        $this->assertDatabaseHas('approval_pengajuan', ['id_referensi' => $id, 'status' => 'dibatalkan']);
        $this->assertSame(0, DB::table('approval_pengajuan')->where('id_referensi', $id)->where('status', 'menunggu')->count());
    }

    public function test_update_saat_menunggu_approval_nominal_tetap_diatas_batas_membuat_approval_baru(): void
    {
        $superadmin = $this->actingAsRole('SUPERADMIN');
        app(ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 1000000);
        $id = $this->buatPengajuanMenungguApproval($superadmin->id_pengguna, ['nominal' => 5000000]);

        $this->putJson("/api/arus-kas/pengajuan/{$id}", $this->payload(['nominal' => 8000000]))
            ->assertStatus(200)->assertJsonPath('data.status', 'menunggu_approval');

        $this->assertSame(1, DB::table('approval_pengajuan')->where('id_referensi', $id)->where('status', 'dibatalkan')->count());
        $this->assertDatabaseHas('approval_pengajuan', ['id_referensi' => $id, 'status' => 'menunggu', 'nominal' => 8000000]);
    }

    public function test_update_saat_ditolak_dengan_nominal_diatas_batas_mengajukan_ulang_ke_engine(): void
    {
        $superadmin = $this->actingAsRole('SUPERADMIN');
        app(ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 1000000);
        $id = $this->buatPengajuanMenungguApproval($superadmin->id_pengguna, ['nominal' => 5000000]);

        $this->patchJson("/api/arus-kas/pengajuan/{$id}/approval", ['keputusan' => 'setuju'])
            ->assertStatus(200)->assertJsonPath('data.status', 'disetujui');

        $this->actingAsRole('MANAGER');
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/tolak", ['alasan' => 'Revisi dokumen'])
            ->assertStatus(200)->assertJsonPath('data.status', 'ditolak');

        $this->actingAsRole('SUPERADMIN');
        $this->putJson("/api/arus-kas/pengajuan/{$id}", $this->payload(['nominal' => 6000000]))
            ->assertStatus(200)->assertJsonPath('data.status', 'menunggu_approval');

        $this->assertSame(1, DB::table('approval_pengajuan')->where('id_referensi', $id)->where('status', 'menunggu')->count());
    }

    public function test_update_saat_ditolak_dengan_nominal_dibawah_batas_auto_disetujui(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->setBatasTinggi();
        $id = $this->buatPengajuan();

        $this->actingAsRole('MANAGER');
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/tolak", ['alasan' => 'Salah nominal'])
            ->assertStatus(200)->assertJsonPath('data.status', 'ditolak');

        $this->actingAsRole('SUPERADMIN');
        $this->putJson("/api/arus-kas/pengajuan/{$id}", $this->payload(['nominal' => 300000]))
            ->assertStatus(200)->assertJsonPath('data.status', 'disetujui');
    }

    public function test_delete_saat_menunggu_approval_membatalkan_approval_aktif(): void
    {
        $superadmin = $this->actingAsRole('SUPERADMIN');
        $id = $this->buatPengajuanMenungguApproval($superadmin->id_pengguna);

        $this->deleteJson("/api/arus-kas/pengajuan/{$id}")->assertStatus(200);

        $this->assertSoftDeleted('pengajuan_pengeluaran', ['id_pengajuan' => $id]);
        $this->assertDatabaseHas('approval_pengajuan', ['id_referensi' => $id, 'status' => 'dibatalkan']);
    }

    public function test_command_migrasi_approval_pending_sweep_legacy_dan_idempotent(): void
    {
        $admin = $this->actingAsRole('SUPERADMIN');
        app(ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 1000000);

        $approver = $this->buatApprover('approver_sweep');
        $idEventType = $this->idEventTypePengajuanPengeluaran();
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'pengguna', 'id_pengguna' => $approver->id_pengguna, 'dibuat_pada' => now(),
        ]);

        $idTinggi = (string) Str::uuid();
        DB::table('pengajuan_pengeluaran')->insert([
            'id_pengajuan' => $idTinggi, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nomor_pengajuan' => 'PP-SWEEP-1', 'kategori' => 'lainnya', 'nominal' => 5000000,
            'tanggal_pengajuan' => now()->toDateString(), 'penerima' => 'Test Sweep', 'status' => 'diajukan',
            'dibuat_oleh' => $admin->id_pengguna, 'dibuat_pada' => now(),
        ]);
        $idRendah = (string) Str::uuid();
        DB::table('pengajuan_pengeluaran')->insert([
            'id_pengajuan' => $idRendah, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nomor_pengajuan' => 'PP-SWEEP-2', 'kategori' => 'lainnya', 'nominal' => 100000,
            'tanggal_pengajuan' => now()->toDateString(), 'penerima' => 'Test Sweep 2', 'status' => 'dicek',
            'dibuat_oleh' => $admin->id_pengguna, 'dibuat_pada' => now(),
        ]);

        $this->artisan('arus-kas:migrasi-approval-pending')->assertSuccessful();

        $this->assertSame('menunggu_approval', DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idTinggi)->value('status'));
        $this->assertSame('disetujui', DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idRendah)->value('status'));
        $this->assertSame(1, DB::table('approval_pengajuan')->where('id_referensi', $idTinggi)->count());

        $this->artisan('arus-kas:migrasi-approval-pending')->assertSuccessful();

        $this->assertSame('menunggu_approval', DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idTinggi)->value('status'));
        $this->assertSame('disetujui', DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idRendah)->value('status'));
        $this->assertSame(1, DB::table('approval_pengajuan')->where('id_referensi', $idTinggi)->count());
    }

    public function test_cek_tanpa_gerbang_transfer_langsung_siap_transfer(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->buatPengajuan();

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/cek")
            ->assertStatus(200)->assertJsonPath('data.status', 'siap_transfer');

        $this->assertSame(0, DB::table('approval_pengajuan as ap')
            ->join('approval_event_type as et', 'et.id_event_type', '=', 'ap.id_event_type')
            ->where('et.kode', 'persetujuan_transfer')
            ->where('ap.id_referensi', $id)
            ->count());
    }

    public function test_cek_dengan_gerbang_transfer_aktif_status_tetap_dicek_dan_ajukan_ke_engine(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $dirut = $this->buatApprover('dirut');
        $idEventTypeTransfer = $this->buatEventTypeDenganApprover('persetujuan_transfer', $dirut->id_pengguna);
        $id = $this->buatPengajuan();

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/cek")
            ->assertStatus(200)->assertJsonPath('data.status', 'dicek');

        $this->assertDatabaseHas('approval_pengajuan', [
            'id_referensi'  => $id,
            'id_event_type' => $idEventTypeTransfer,
            'status'        => 'menunggu',
        ]);
    }

    public function test_dirut_setujui_persetujuan_transfer_jadi_siap_transfer(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $dirut = $this->buatApprover('dirut_setuju');
        $this->buatEventTypeDenganApprover('persetujuan_transfer', $dirut->id_pengguna);
        $id = $this->buatPengajuan();

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/cek")->assertStatus(200);

        $idApproval = DB::table('approval_pengajuan')->where('id_referensi', $id)->value('id_approval');

        Sanctum::actingAs($dirut, ['*']);
        $this->patchJson("/api/approval-pengajuan/{$idApproval}/keputusan", ['keputusan' => 'setuju'])
            ->assertStatus(200)->assertJsonPath('data.status', 'disetujui');

        $this->assertSame('siap_transfer', DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $id)->value('status'));
    }

    public function test_dirut_tolak_persetujuan_transfer_jadi_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $dirut = $this->buatApprover('dirut_tolak');
        $this->buatEventTypeDenganApprover('persetujuan_transfer', $dirut->id_pengguna);
        $id = $this->buatPengajuan();

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/cek")->assertStatus(200);

        $idApproval = DB::table('approval_pengajuan')->where('id_referensi', $id)->value('id_approval');

        Sanctum::actingAs($dirut, ['*']);
        $this->patchJson("/api/approval-pengajuan/{$idApproval}/keputusan", [
            'keputusan' => 'tolak',
            'catatan'   => 'Rekening tujuan belum jelas',
        ])->assertStatus(200)->assertJsonPath('data.status', 'ditolak');

        $row = DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $id)->first();
        $this->assertSame('ditolak', $row->status);
        $this->assertSame('Rekening tujuan belum jelas', $row->alasan_ditolak);
    }

    public function test_transfer_dari_dicek_409_dari_siap_transfer_200(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $dirut = $this->buatApprover('dirut_transfer');
        $this->buatEventTypeDenganApprover('persetujuan_transfer', $dirut->id_pengguna);
        $id = $this->buatPengajuan();

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/cek")->assertStatus(200)->assertJsonPath('data.status', 'dicek');

        $this->patch("/api/arus-kas/pengajuan/{$id}/transfer", ['tanggal_transfer' => now()->toDateString(), 'bukti' => UploadedFile::fake()->create('bukti.jpg', 5, 'image/jpeg')])
            ->assertStatus(409);

        $idApproval = DB::table('approval_pengajuan')->where('id_referensi', $id)->value('id_approval');
        Sanctum::actingAs($dirut, ['*']);
        $this->patchJson("/api/approval-pengajuan/{$idApproval}/keputusan", ['keputusan' => 'setuju'])->assertStatus(200);

        $this->actingAsRole('KEUANGAN');
        Storage::fake('public');
        $this->patch("/api/arus-kas/pengajuan/{$id}/transfer", ['tanggal_transfer' => now()->toDateString(), 'bukti' => UploadedFile::fake()->create('bukti.jpg', 5, 'image/jpeg')])
            ->assertStatus(200)->assertJsonPath('data.status', 'ditransfer');
    }

    public function test_proses_approval_kategori_kode_routing_tidak_gagal(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $approver = $this->buatApprover('approver_kategori_proses');
        $this->buatEventTypeDenganApprover('sparepart', $approver->id_pengguna);

        $res = $this->postJson('/api/arus-kas/pengajuan', $this->payload(['kategori' => 'sparepart', 'nominal' => 500000]));
        $res->assertStatus(201)->assertJsonPath('data.status', 'menunggu_approval');
        $id = $res->json('data.id_pengajuan');

        Sanctum::actingAs($approver, ['*']);
        $this->patchJson("/api/arus-kas/pengajuan/{$id}/approval", ['keputusan' => 'setuju'])
            ->assertStatus(200)->assertJsonPath('data.status', 'disetujui');
    }
}
