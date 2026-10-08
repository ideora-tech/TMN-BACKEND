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
use Tests\Feature\Concerns\MenerbitkanPo;
use Tests\TestCase;

class PermintaanPembelianAksesLihatTest extends TestCase
{
    use RefreshDatabase;
    use MenerbitkanPo;

    private const PESAN_TOLAK = 'Anda hanya bisa melihat permintaan pembelian yang Anda ajukan atau perlu Anda setujui';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->ensurePerusahaan();
        app(ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 999999999);
    }

    private function buatPr(Pengguna $pengaju, string $judul): array
    {
        Sanctum::actingAs($pengaju, ['*']);
        return $this->postJson('/api/permintaan-pembelian', [
            'judul' => $judul, 'tipe' => 'umum', 'alasan' => 'Kebutuhan rutin', 'tanggal_permintaan' => now()->toDateString(),
            'items' => [
                ['jenis' => 'jasa', 'nama_item' => 'Servis AC', 'qty' => 1, 'satuan' => 'unit', 'harga_estimasi' => 300000],
            ],
        ])->assertStatus(201)->json('data');
    }

    private function idDaftar(string $query = ''): array
    {
        return collect($this->getJson('/api/permintaan-pembelian?limit=50' . $query)->assertStatus(200)->json('data'))
            ->pluck('id_permintaan')->sort()->values()->all();
    }

    public function test_pemohon_hanya_melihat_permintaan_miliknya_di_daftar_dan_ringkasan(): void
    {
        $ani = $this->actingAsRole('DISPATCHER');
        $budi = $this->actingAsRole('DISPATCHER');
        $prAni = $this->buatPr($ani, 'ATK Ani');
        $prBudi1 = $this->buatPr($budi, 'ATK Budi 1');
        $prBudi2 = $this->buatPr($budi, 'ATK Budi 2');

        Sanctum::actingAs($ani, ['*']);
        $res = $this->getJson('/api/permintaan-pembelian?limit=50')->assertStatus(200);
        $res->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id_permintaan', $prAni['id_permintaan'])
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.lihat_semua', false)
            ->assertJsonPath('meta.ringkasan.disetujui', 1);
        $this->assertSame([], $this->idDaftar('&search=Budi'));

        Sanctum::actingAs($budi, ['*']);
        $this->assertSame(collect([$prBudi1['id_permintaan'], $prBudi2['id_permintaan']])->sort()->values()->all(), $this->idDaftar());
        $this->getJson('/api/permintaan-pembelian')->assertJsonPath('meta.ringkasan.disetujui', 2);
    }

    public function test_pemohon_tidak_bisa_membuka_detail_pengajuan_dan_po_milik_orang_lain(): void
    {
        $ani = $this->actingAsRole('DISPATCHER');
        $budi = $this->actingAsRole('DISPATCHER');
        $pr = $this->buatPr($budi, 'ATK Budi');

        Sanctum::actingAs($ani, ['*']);
        $this->getJson("/api/permintaan-pembelian/{$pr['id_permintaan']}")->assertStatus(403)->assertJsonPath('message', self::PESAN_TOLAK);
        $this->getJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/pengajuan")->assertStatus(403);
        $this->get("/api/permintaan-pembelian/{$pr['id_permintaan']}/po/pdf")->assertStatus(403);

        Sanctum::actingAs($budi, ['*']);
        $this->getJson("/api/permintaan-pembelian/{$pr['id_permintaan']}")->assertStatus(200)->assertJsonPath('data.judul', 'ATK Budi');
        $this->getJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/pengajuan")->assertStatus(200);
    }

    public function test_pengadaan_dan_keuangan_melihat_semua_permintaan(): void
    {
        $ani = $this->actingAsRole('DISPATCHER');
        $budi = $this->actingAsRole('DISPATCHER');
        $prAni = $this->buatPr($ani, 'ATK Ani');
        $prBudi = $this->buatPr($budi, 'ATK Budi');
        $semua = collect([$prAni['id_permintaan'], $prBudi['id_permintaan']])->sort()->values()->all();

        foreach (['PENGADAAN', 'KEUANGAN', 'SUPERADMIN'] as $peran) {
            $this->actingAsRole($peran);
            $this->assertSame($semua, $this->idDaftar(), "Peran {$peran} harus melihat semua PR");
            $this->getJson('/api/permintaan-pembelian')->assertJsonPath('meta.lihat_semua', true)->assertJsonPath('meta.ringkasan.disetujui', 2);
            $this->getJson("/api/permintaan-pembelian/{$prBudi['id_permintaan']}")->assertStatus(200);
        }

        $this->actingAsRole('PENGADAAN');
        $this->assertSame([$prAni['id_permintaan']], collect($this->getJson('/api/permintaan-pembelian?milik_saya=0&search=Ani')->json('data'))->pluck('id_permintaan')->all());
    }

    public function test_approver_melihat_permintaan_yang_perlu_disetujuinya(): void
    {
        $approver = $this->actingAsRole('DISPATCHER');
        $orangLain = $this->actingAsRole('DISPATCHER');
        $pengaju = $this->actingAsRole('DISPATCHER');
        $idEventType = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $idEventType, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => 'permintaan_pembelian', 'nama' => 'Approval PR', 'mode_resolusi' => 'pinned', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType, 'tipe' => 'pengguna', 'id_pengguna' => $approver->id_pengguna, 'dibuat_pada' => now(),
        ]);

        $pr = $this->buatPr($pengaju, 'Perlu approval');
        $this->assertSame('menunggu_approval', $pr['status']);

        Sanctum::actingAs($approver, ['*']);
        $this->assertSame([$pr['id_permintaan']], $this->idDaftar());
        $this->getJson("/api/permintaan-pembelian/{$pr['id_permintaan']}")->assertStatus(200);

        Sanctum::actingAs($orangLain, ['*']);
        $this->assertSame([], $this->idDaftar());
        $this->getJson("/api/permintaan-pembelian/{$pr['id_permintaan']}")->assertStatus(403);
    }

    public function test_hitungan_pembayaran_ditolak_mengikuti_yang_boleh_dilihat(): void
    {
        $ani = $this->actingAsRole('DISPATCHER');
        $budi = $this->actingAsRole('DISPATCHER');
        $pr = $this->buatPr($budi, 'ATK Budi');
        $idSupplier = (string) Str::uuid();
        DB::table('supplier')->insert(['id_supplier' => $idSupplier, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nama' => 'Toko ATK Jaya', 'aktif' => 1, 'dibuat_pada' => now()]);

        $this->actingAsRole('PENGADAAN');
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/proses")->assertStatus(200);
        $payload = [
            'id_supplier' => $idSupplier, 'tanggal_pembelian' => now()->toDateString(),
            'items' => [['id_item' => $pr['items'][0]['id_item'], 'harga_aktual' => 350000]],
        ];
        $this->terbitkanPo($pr['id_permintaan'], $payload)->assertStatus(200);
        $this->postJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/bukti", ['tahap' => 'pembelian', 'bukti' => [UploadedFile::fake()->image('nota.jpg')]])->assertStatus(200);
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/dibeli", $payload)->assertStatus(200);
        $idPengajuan = DB::table('pengajuan_pengeluaran')->where('id_permintaan_pembelian', $pr['id_permintaan'])->value('id_pengajuan');

        $superadmin = $this->actingAsRole('SUPERADMIN');
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/tolak", ['alasan' => 'Nota salah'])->assertStatus(200);

        Sanctum::actingAs($ani, ['*']);
        $this->getJson('/api/permintaan-pembelian')->assertStatus(200)->assertJsonPath('meta.pembayaran_ditolak', 0);
        Sanctum::actingAs($budi, ['*']);
        $this->getJson('/api/permintaan-pembelian')->assertStatus(200)->assertJsonPath('meta.pembayaran_ditolak', 1);

        $this->assertDatabaseHas('notifikasi', ['id_pengguna' => $budi->id_pengguna, 'tipe' => 'pengadaan_ditolak', 'referensi_id' => $pr['id_permintaan']]);
        $this->assertDatabaseMissing('notifikasi', ['id_pengguna' => $ani->id_pengguna, 'tipe' => 'pengadaan_ditolak']);
        $this->assertSame(1, DB::table('notifikasi')->where('id_pengguna', $superadmin->id_pengguna)->where('tipe', 'pengadaan_ditolak')->count());
    }
}
