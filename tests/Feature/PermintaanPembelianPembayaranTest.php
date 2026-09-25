<?php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PermintaanPembelianPembayaranTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->ensurePerusahaan();
        app(\App\Modules\ArusKas\ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 999999999);
    }

    private function makeSupplier(): string
    {
        $id = (string) Str::uuid();
        DB::table('supplier')->insert(['id_supplier' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nama' => 'Toko ATK Jaya', 'aktif' => 1, 'dibuat_pada' => now()]);
        return $id;
    }

    private function makeBarang(string $nama = 'Kertas A4', string $satuan = 'rim'): string
    {
        $id = (string) Str::uuid();
        DB::table('barang')->insert([
            'id_barang' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode' => 'BRG-' . Str::random(4),
            'nama' => $nama, 'satuan' => $satuan, 'harga_standar' => 50000, 'stok' => 0, 'stok_minimum' => 0, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function payloadPr(array $override = []): array
    {
        return array_merge([
            'judul' => 'ATK Oktober', 'tipe' => 'umum', 'alasan' => 'Kebutuhan rutin', 'tanggal_permintaan' => now()->toDateString(),
            'items' => [
                ['jenis' => 'barang', 'id_barang' => $this->makeBarang(), 'nama_item' => 'Kertas A4', 'qty' => 10, 'satuan' => 'rim', 'harga_estimasi' => 55000],
                ['jenis' => 'jasa', 'nama_item' => 'Servis AC', 'qty' => 1, 'satuan' => 'unit', 'harga_estimasi' => 300000],
            ],
        ], $override);
    }

    private function buatPr(string $peran = 'DISPATCHER', array $override = []): array
    {
        $this->actingAsRole($peran);
        return $this->postJson('/api/permintaan-pembelian', $this->payloadPr($override))->assertStatus(201)->json('data');
    }

    private function prSampaiDibeli(): array
    {
        $pr = $this->buatPr();
        $this->actingAsRole('PENGADAAN');
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/proses")->assertStatus(200);
        $this->postJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/bukti", ['tahap' => 'pembelian', 'bukti' => [UploadedFile::fake()->image('nota.jpg')]])->assertStatus(200);
        $res = $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/dibeli", [
            'id_supplier' => $this->makeSupplier(), 'tanggal_pembelian' => now()->toDateString(),
            'items' => [
                ['id_item' => $pr['items'][0]['id_item'], 'harga_aktual' => 52000],
                ['id_item' => $pr['items'][1]['id_item'], 'harga_aktual' => 350000],
            ],
        ])->assertStatus(200);
        return $res->json('data');
    }

    private function buatEventTypeApprovalPr(string $idApprover): void
    {
        $idEventType = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $idEventType, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => 'permintaan_pembelian', 'nama' => 'Approval PR', 'mode_resolusi' => 'pinned', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType, 'tipe' => 'pengguna', 'id_pengguna' => $idApprover, 'dibuat_pada' => now(),
        ]);
    }

    public function test_dibeli_membuat_pengajuan_pengeluaran_kategori_pengadaan(): void
    {
        $pr = $this->prSampaiDibeli();
        $pengajuan = DB::table('pengajuan_pengeluaran')->where('id_permintaan_pembelian', $pr['id_permintaan'])->first();
        $this->assertNotNull($pengajuan);
        $this->assertSame('pengadaan', $pengajuan->kategori);
        $this->assertSame(870000.0, (float) $pengajuan->nominal);
        $this->assertSame('Toko ATK Jaya', $pengajuan->penerima);
        $info = $this->getJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/pengajuan")->assertStatus(200)->json('data');
        $this->assertSame($pengajuan->nomor_pengajuan, $info['nomor_pengajuan']);
    }

    public function test_transfer_ditolak_sebelum_diterima_dan_pr_selesai_setelah_transfer(): void
    {
        $pr = $this->prSampaiDibeli();
        $pengajuan = DB::table('pengajuan_pengeluaran')->where('id_permintaan_pembelian', $pr['id_permintaan'])->first();
        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $pengajuan->id_pengajuan)->update(['status' => 'siap_transfer']);
        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$pengajuan->id_pengajuan}/transfer", [
            'tanggal_transfer' => now()->toDateString(), 'bukti' => UploadedFile::fake()->image('tf.jpg'),
        ])->assertStatus(409);
        $this->actingAsRole('PENGADAAN');
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/terima", [
            'tanggal_diterima' => now()->toDateString(),
            'items' => [['id_item' => $pr['items'][0]['id_item'], 'qty_diterima' => 10], ['id_item' => $pr['items'][1]['id_item'], 'qty_diterima' => 1]],
        ])->assertStatus(200);
        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$pengajuan->id_pengajuan}/transfer", [
            'tanggal_transfer' => now()->toDateString(), 'bukti' => UploadedFile::fake()->image('tf.jpg'),
        ])->assertStatus(200);
        $this->assertSame('selesai', DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->value('status'));
        $this->assertSame(now()->toDateString(), DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->value('tanggal_pembayaran'));
    }

    public function test_rincian_sumber_pengajuan_mengembalikan_tipe_permintaan_pembelian(): void
    {
        $pr = $this->prSampaiDibeli();
        $pengajuan = DB::table('pengajuan_pengeluaran')->where('id_permintaan_pembelian', $pr['id_permintaan'])->first();
        $this->actingAsRole('KEUANGAN');
        $this->getJson("/api/arus-kas/pengajuan/{$pengajuan->id_pengajuan}/rincian-sumber")->assertStatus(200)
            ->assertJsonPath('data.tipe', 'permintaan_pembelian')
            ->assertJsonPath('data.data.nomor_permintaan', $pr['nomor_permintaan']);
    }

    public function test_approval_pr_aktif_menunggu_lalu_disetujui_via_engine(): void
    {
        $approver = $this->actingAsRole('MANAGER');
        $this->buatEventTypeApprovalPr($approver->id_pengguna);
        $pr = $this->buatPr('DISPATCHER');
        $this->assertSame('menunggu_approval', $pr['status']);
        app(\App\Modules\Approval\ApprovalService::class)->putuskanUntukReferensi('permintaan_pembelian', $pr['id_permintaan'], $approver->id_pengguna, 'setuju', null, self::PERUSAHAAN_ID);
        $this->assertSame('disetujui', DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->value('status'));
    }

    public function test_ringkasan_referensi_pr_muncul_di_menunggu_saya(): void
    {
        $approver = $this->actingAsRole('MANAGER');
        $this->buatEventTypeApprovalPr($approver->id_pengguna);
        $idDepartemen = (string) Str::uuid();
        DB::table('departemen')->insert([
            'id_departemen' => $idDepartemen, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_departemen' => 'OPS', 'nama_departemen' => 'Operasional', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $pr = $this->buatPr('DISPATCHER', ['judul' => 'ATK Ringkasan', 'id_departemen' => $idDepartemen]);
        \Laravel\Sanctum\Sanctum::actingAs($approver, ['*']);
        $this->getJson('/api/approval-pengajuan/menunggu-saya')->assertStatus(200)
            ->assertJsonPath('data.0.kode_event_type', 'permintaan_pembelian')
            ->assertJsonPath('data.0.nomor_referensi', $pr['nomor_permintaan'])
            ->assertJsonPath('data.0.keterangan_referensi', 'ATK Ringkasan')
            ->assertJsonPath('data.0.pihak_referensi', 'Operasional');
    }

    public function test_ringkasan_referensi_pr_tanpa_departemen_pakai_username_pengaju(): void
    {
        $approver = $this->actingAsRole('MANAGER');
        $this->buatEventTypeApprovalPr($approver->id_pengguna);
        $pr = $this->buatPr('DISPATCHER');
        $username = DB::table('pengguna')->where('id_pengguna', $pr['id_pengaju'])->value('username');
        \Laravel\Sanctum\Sanctum::actingAs($approver, ['*']);
        $this->getJson('/api/approval-pengajuan/menunggu-saya')->assertStatus(200)
            ->assertJsonPath('data.0.nomor_referensi', $pr['nomor_permintaan'])
            ->assertJsonPath('data.0.pihak_referensi', $username);
    }

    public function test_approval_pr_ditolak_lalu_edit_mengajukan_ulang(): void
    {
        $approver = $this->actingAsRole('MANAGER');
        $this->buatEventTypeApprovalPr($approver->id_pengguna);
        $pr = $this->buatPr('DISPATCHER');
        app(\App\Modules\Approval\ApprovalService::class)->putuskanUntukReferensi('permintaan_pembelian', $pr['id_permintaan'], $approver->id_pengguna, 'tolak', 'Terlalu mahal', self::PERUSAHAAN_ID);
        $this->assertSame('ditolak', DB::table('permintaan_pembelian')->where('id_permintaan', $pr['id_permintaan'])->value('status'));
        \Laravel\Sanctum\Sanctum::actingAs(\App\Models\Pengguna::findOrFail($pr['id_pengaju']), ['*']);
        $this->putJson("/api/permintaan-pembelian/{$pr['id_permintaan']}", $this->payloadPr(['judul' => 'Revisi']))
            ->assertStatus(200)->assertJsonPath('data.status', 'menunggu_approval')->assertJsonPath('data.alasan_ditolak', null);
    }

    public function test_batal_saat_menunggu_approval_membatalkan_approval_engine(): void
    {
        $approver = $this->actingAsRole('MANAGER');
        $this->buatEventTypeApprovalPr($approver->id_pengguna);
        $pr = $this->buatPr('DISPATCHER');
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/batal", ['alasan' => 'Batal'])->assertStatus(200);
        $this->assertDatabaseHas('approval_pengajuan', ['id_referensi' => $pr['id_permintaan'], 'status' => 'dibatalkan']);
    }
}
