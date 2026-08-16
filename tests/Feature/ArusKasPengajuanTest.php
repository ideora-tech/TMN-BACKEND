<?php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    private function buatPengajuan(array $override = []): string
    {
        $res = $this->postJson('/api/v1/arus-kas/pengajuan', $this->payload($override));
        return $res->json('data.id_pengajuan');
    }

    public function test_create_pengajuan_berhasil_dengan_nomor_auto(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/v1/arus-kas/pengajuan', $this->payload());

        $res->assertStatus(201)
            ->assertJsonPath('data.status', 'diajukan')
            ->assertJsonPath('data.kategori', 'uang_jalan')
            ->assertJsonPath('data.nominal', 500000);
        $this->assertMatchesRegularExpression('/^PP-\d{6}-0001$/', $res->json('data.nomor_pengajuan'));
    }

    public function test_nomor_urut_bertambah(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->postJson('/api/v1/arus-kas/pengajuan', $this->payload())->assertStatus(201);
        $res2 = $this->postJson('/api/v1/arus-kas/pengajuan', $this->payload());
        $this->assertMatchesRegularExpression('/-0002$/', $res2->json('data.nomor_pengajuan'));
    }

    public function test_validasi_field_wajib_dan_kategori_tidak_valid(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->postJson('/api/v1/arus-kas/pengajuan', $this->payload(['penerima' => '']))->assertStatus(422);
        $this->postJson('/api/v1/arus-kas/pengajuan', $this->payload(['kategori' => 'tidak_dikenal']))->assertStatus(422);
        $this->postJson('/api/v1/arus-kas/pengajuan', $this->payload(['nominal' => 0]))->assertStatus(422);
    }

    public function test_upload_bukti_saat_create_tersimpan_di_disk(): void
    {
        Storage::fake('public');
        $this->actingAsRole('SUPERADMIN');

        $res = $this->post('/api/v1/arus-kas/pengajuan', array_merge($this->payload(), [
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

        $res = $this->post('/api/v1/arus-kas/pengajuan', array_merge($this->payload(), [
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

        $this->assertCount(1, $this->getJson('/api/v1/arus-kas/pengajuan?status=dicek')->json('data'));
        $this->assertCount(2, $this->getJson('/api/v1/arus-kas/pengajuan')->json('data'));
    }

    public function test_show_pengajuan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->buatPengajuan();

        $this->getJson("/api/v1/arus-kas/pengajuan/{$id}")
            ->assertStatus(200)->assertJsonPath('data.id_pengajuan', $id);
    }

    public function test_update_dan_delete_hanya_saat_diajukan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->buatPengajuan();

        $this->putJson("/api/v1/arus-kas/pengajuan/{$id}", $this->payload(['penerima' => 'Budi Baru']))
            ->assertStatus(200)->assertJsonPath('data.penerima', 'Budi Baru');

        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $id)->update(['status' => 'dicek']);
        $this->putJson("/api/v1/arus-kas/pengajuan/{$id}", $this->payload())->assertStatus(409);
        $this->deleteJson("/api/v1/arus-kas/pengajuan/{$id}")->assertStatus(409);

        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $id)->update(['status' => 'diajukan']);
        $this->deleteJson("/api/v1/arus-kas/pengajuan/{$id}")->assertStatus(200);
        $this->assertSoftDeleted('pengajuan_pengeluaran', ['id_pengajuan' => $id]);
    }

    public function test_alur_transisi_lengkap_sampai_ditransfer(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->putJson('/api/v1/arus-kas/pengaturan-approval', ['batas' => 999999999])->assertStatus(200);
        $id = $this->buatPengajuan();

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/v1/arus-kas/pengajuan/{$id}/cek")
            ->assertStatus(200)->assertJsonPath('data.status', 'disetujui');

        Storage::fake('public');
        $res = $this->patch("/api/v1/arus-kas/pengajuan/{$id}/transfer", [
            'tanggal_transfer' => now()->toDateString(),
            'bukti'            => UploadedFile::fake()->create('bukti-transfer.jpg', 100, 'image/jpeg'),
        ]);
        $res->assertStatus(200)->assertJsonPath('data.status', 'ditransfer');
        $this->assertNotNull($res->json('data.tanggal_transfer'));
        $this->assertNotNull($res->json('data.url_bukti'));

        $row = DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $id)->first();
        $this->assertNotNull($row->dicek_oleh);
        $this->assertNotNull($row->dicek_pada);
        $this->assertSame($row->dicek_oleh, $row->disetujui_oleh);
        $this->assertNotNull($row->disetujui_pada);
        $this->assertNotNull($row->ditransfer_oleh);
        $this->assertNotNull($row->ditransfer_pada);
    }

    public function test_transfer_wajib_tanggal_transfer(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->buatPengajuan();
        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $id)->update(['status' => 'disetujui']);

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/v1/arus-kas/pengajuan/{$id}/transfer", [])->assertStatus(422);
    }

    public function test_tolak_dari_diajukan_dan_dari_dicek_dengan_alasan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->buatPengajuan();

        $this->actingAsRole('MANAGER');
        $this->patchJson("/api/v1/arus-kas/pengajuan/{$id}/tolak", ['alasan' => 'Tidak sesuai anggaran'])
            ->assertStatus(200)->assertJsonPath('data.status', 'ditolak')
            ->assertJsonPath('data.alasan_ditolak', 'Tidak sesuai anggaran');

        $this->actingAsRole('SUPERADMIN');
        $id2 = $this->buatPengajuan();
        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $id2)->update(['status' => 'dicek']);

        $this->actingAsRole('MANAGER');
        $this->patchJson("/api/v1/arus-kas/pengajuan/{$id2}/tolak", ['alasan' => 'Dokumen kurang'])
            ->assertStatus(200)->assertJsonPath('data.status', 'ditolak');
    }

    public function test_tolak_wajib_alasan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->buatPengajuan();

        $this->actingAsRole('MANAGER');
        $this->patchJson("/api/v1/arus-kas/pengajuan/{$id}/tolak", [])->assertStatus(422);
    }

    public function test_transisi_tidak_sah_dikembalikan_409(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->buatPengajuan();

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/v1/arus-kas/pengajuan/{$id}/transfer", ['tanggal_transfer' => now()->toDateString()])
            ->assertStatus(409);

        $this->actingAsRole('MANAGER');
        $this->patchJson("/api/v1/arus-kas/pengajuan/{$id}/approval", ['keputusan' => 'setuju'])->assertStatus(409);

        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $id)->update(['status' => 'ditolak']);
        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/v1/arus-kas/pengajuan/{$id}/cek")->assertStatus(409);
    }

    public function test_guard_peran_cek_dan_transfer_hanya_keuangan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->buatPengajuan();

        $this->actingAsRole('MANAGER');
        $this->patchJson("/api/v1/arus-kas/pengajuan/{$id}/cek")->assertStatus(403);

        $this->actingAsRole('DISPATCHER');
        $this->patchJson("/api/v1/arus-kas/pengajuan/{$id}/cek")->assertStatus(403);
    }

    public function test_approval_oleh_bukan_approver_403_tolak_lama_tetap_dibatasi_role_manager(): void
    {
        $superadmin = $this->actingAsRole('SUPERADMIN');
        DB::table('approver_keuangan')->insert([
            'id_approver'   => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'tipe'          => 'pengguna',
            'id_pengguna'   => $superadmin->id_pengguna,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        $id = $this->buatPengajuan();

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/v1/arus-kas/pengajuan/{$id}/cek")
            ->assertStatus(200)->assertJsonPath('data.status', 'menunggu_approval');
        $this->patchJson("/api/v1/arus-kas/pengajuan/{$id}/approval", ['keputusan' => 'setuju'])->assertStatus(403);
        $this->patchJson("/api/v1/arus-kas/pengajuan/{$id}/tolak", ['alasan' => 'x'])->assertStatus(403);
    }

    public function test_isolasi_tenant_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->buatPengajuan();

        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $id)->update(['id_perusahaan' => $idLain]);

        $this->getJson("/api/v1/arus-kas/pengajuan/{$id}")->assertStatus(404);
        $this->putJson("/api/v1/arus-kas/pengajuan/{$id}", $this->payload())->assertStatus(404);
        $this->deleteJson("/api/v1/arus-kas/pengajuan/{$id}")->assertStatus(404);

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/v1/arus-kas/pengajuan/{$id}/cek")->assertStatus(404);
    }

    public function test_dispatcher_bisa_membuat_pengajuan_tapi_tidak_bisa_transisi(): void
    {
        $this->actingAsRole('DISPATCHER');
        $res = $this->postJson('/api/v1/arus-kas/pengajuan', $this->payload());
        $res->assertStatus(201);
        $id = $res->json('data.id_pengajuan');

        $this->patchJson("/api/v1/arus-kas/pengajuan/{$id}/cek")->assertStatus(403);
    }
}
