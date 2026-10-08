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

class ArusKasNotifikasiKeuanganTest extends TestCase
{
    use RefreshDatabase;
    use MenerbitkanPo;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->ensurePerusahaan();
        app(ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 999999999);
    }

    private function buatPengguna(string $peran): Pengguna
    {
        return Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => $peran,
            'username' => strtolower($peran) . '_' . Str::random(6), 'email' => Str::random(8) . '@test.id',
            'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
    }

    private function notif(Pengguna $pengguna, string $tipe): array
    {
        return DB::table('notifikasi')->where('id_pengguna', $pengguna->id_pengguna)->where('tipe', $tipe)
            ->orderBy('dibuat_pada')->get()->all();
    }

    private function buatPengajuan(float $nominal = 250000, string $penerima = 'Toko Sumber'): array
    {
        return $this->postJson('/api/arus-kas/pengajuan', [
            'kategori' => 'lainnya', 'nominal' => $nominal, 'tanggal_pengajuan' => now()->toDateString(), 'penerima' => $penerima,
        ])->assertStatus(201)->assertJsonPath('data.status', 'disetujui')->json('data');
    }

    public function test_pengajuan_disetujui_lalu_diverifikasi_memberi_tahu_keuangan_selain_pelaku(): void
    {
        $rekan = $this->buatPengguna('KEUANGAN');
        $superadmin = $this->buatPengguna('SUPERADMIN');
        $manager = $this->buatPengguna('MANAGER');
        $pelaku = $this->actingAsRole('KEUANGAN');

        $pengajuan = $this->buatPengajuan();

        $verifikasi = $this->notif($rekan, 'keuangan_verifikasi');
        $this->assertCount(1, $verifikasi);
        $this->assertSame("Pengajuan {$pengajuan['nomor_pengajuan']} perlu diverifikasi", $verifikasi[0]->judul);
        $this->assertSame('Lainnya Rp 250.000 untuk Toko Sumber.', $verifikasi[0]->isi);
        $this->assertSame('/proses-pembayaran?tab=verifikasi', $verifikasi[0]->link);
        $this->assertSame($pengajuan['id_pengajuan'], $verifikasi[0]->referensi_id);
        $this->assertSame(0, (int) $verifikasi[0]->dibaca);
        $this->assertCount(1, $this->notif($superadmin, 'keuangan_verifikasi'));
        $this->assertCount(0, $this->notif($pelaku, 'keuangan_verifikasi'));
        $this->assertCount(0, $this->notif($manager, 'keuangan_verifikasi'));
        $this->assertCount(0, $this->notif($rekan, 'keuangan_transfer'));

        $this->patchJson("/api/arus-kas/pengajuan/{$pengajuan['id_pengajuan']}/cek")->assertStatus(200)->assertJsonPath('data.status', 'siap_transfer');

        $transfer = $this->notif($rekan, 'keuangan_transfer');
        $this->assertCount(1, $transfer);
        $this->assertSame("Pengajuan {$pengajuan['nomor_pengajuan']} siap ditransfer", $transfer[0]->judul);
        $this->assertSame('Lainnya Rp 250.000 untuk Toko Sumber.', $transfer[0]->isi);
        $this->assertSame('/proses-pembayaran?tab=siap', $transfer[0]->link);
        $this->assertCount(0, $this->notif($pelaku, 'keuangan_transfer'));
        $this->assertCount(0, $this->notif($manager, 'keuangan_transfer'));
    }

    public function test_pengajuan_beruntun_digabung_ke_satu_notifikasi_yang_belum_dibaca(): void
    {
        $rekan = $this->buatPengguna('KEUANGAN');
        $this->actingAsRole('KEUANGAN');

        $this->buatPengajuan(100000, 'Toko A');
        $this->buatPengajuan(200000, 'Toko B');
        $ketiga = $this->buatPengajuan(300000, 'Toko C');

        $baris = $this->notif($rekan, 'keuangan_verifikasi');
        $this->assertCount(1, $baris);
        $this->assertSame('3 pengajuan menunggu verifikasi', $baris[0]->judul);
        $this->assertSame("Terbaru: {$ketiga['nomor_pengajuan']} — Lainnya Rp 300.000 untuk Toko C.", $baris[0]->isi);
        $this->assertSame($ketiga['id_pengajuan'], $baris[0]->referensi_id);
        $this->assertSame(0, (int) $baris[0]->dibaca);

        $this->travel(11)->minutes();
        $keempat = $this->buatPengajuan(400000, 'Toko D');
        $baris = $this->notif($rekan, 'keuangan_verifikasi');
        $this->assertCount(2, $baris);
        $this->assertSame("Pengajuan {$keempat['nomor_pengajuan']} perlu diverifikasi", $baris[1]->judul);
        $this->assertSame('Lainnya Rp 400.000 untuk Toko D. Total 4 pengajuan menunggu verifikasi.', $baris[1]->isi);

        DB::table('notifikasi')->where('id_pengguna', $rekan->id_pengguna)->update(['dibaca' => 1]);
        $kelima = $this->buatPengajuan(500000, 'Toko E');
        $baris = $this->notif($rekan, 'keuangan_verifikasi');
        $this->assertCount(3, $baris);
        $this->assertSame(1, collect($baris)->where('dibaca', 0)->count());
        $this->assertSame("Pengajuan {$kelima['nomor_pengajuan']} perlu diverifikasi", collect($baris)->firstWhere('dibaca', 0)->judul);
    }

    public function test_gerbang_persetujuan_transfer_memberi_tahu_keuangan_setelah_disetujui(): void
    {
        $dirut = $this->buatPengguna('MANAGER');
        $idEventType = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $idEventType, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => 'persetujuan_transfer', 'nama' => 'Persetujuan Transfer', 'mode_resolusi' => 'pinned', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType, 'tipe' => 'pengguna', 'id_pengguna' => $dirut->id_pengguna, 'dibuat_pada' => now(),
        ]);

        $verifikator = $this->actingAsRole('KEUANGAN');
        $pengajuan = $this->buatPengajuan();
        $this->patchJson("/api/arus-kas/pengajuan/{$pengajuan['id_pengajuan']}/cek")->assertStatus(200)->assertJsonPath('data.status', 'dicek');
        $this->assertCount(0, $this->notif($verifikator, 'keuangan_transfer'));

        $idApproval = DB::table('approval_pengajuan')->where('id_referensi', $pengajuan['id_pengajuan'])->value('id_approval');
        Sanctum::actingAs($dirut, ['*']);
        $this->patchJson("/api/approval-pengajuan/{$idApproval}/keputusan", ['keputusan' => 'setuju'])->assertStatus(200);

        $this->assertSame('siap_transfer', DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $pengajuan['id_pengajuan'])->value('status'));
        $transfer = $this->notif($verifikator, 'keuangan_transfer');
        $this->assertCount(1, $transfer);
        $this->assertSame("Pengajuan {$pengajuan['nomor_pengajuan']} siap ditransfer", $transfer[0]->judul);
    }

    private function prUmumDibeli(): array
    {
        $idBarang = (string) Str::uuid();
        DB::table('barang')->insert([
            'id_barang' => $idBarang, 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode' => 'BRG-' . Str::random(4),
            'nama' => 'Kertas A4', 'satuan' => 'rim', 'harga_standar' => 50000, 'stok' => 0, 'stok_minimum' => 0, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $idSupplier = (string) Str::uuid();
        DB::table('supplier')->insert(['id_supplier' => $idSupplier, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nama' => 'Toko ATK Jaya', 'aktif' => 1, 'dibuat_pada' => now()]);

        $this->actingAsRole('DISPATCHER');
        $pr = $this->postJson('/api/permintaan-pembelian', [
            'judul' => 'ATK Oktober', 'tipe' => 'umum', 'alasan' => 'Kebutuhan rutin', 'tanggal_permintaan' => now()->toDateString(),
            'items' => [
                ['jenis' => 'barang', 'id_barang' => $idBarang, 'nama_item' => 'Kertas A4', 'qty' => 10, 'satuan' => 'rim', 'harga_estimasi' => 55000],
                ['jenis' => 'jasa', 'nama_item' => 'Servis AC', 'qty' => 1, 'satuan' => 'unit', 'harga_estimasi' => 300000],
            ],
        ])->assertStatus(201)->json('data');

        $this->actingAsRole('PENGADAAN');
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/proses")->assertStatus(200);
        $payload = [
            'id_supplier' => $idSupplier, 'tanggal_pembelian' => now()->toDateString(),
            'items' => [
                ['id_item' => $pr['items'][0]['id_item'], 'harga_aktual' => 52000],
                ['id_item' => $pr['items'][1]['id_item'], 'harga_aktual' => 350000],
            ],
            'diskon' => 87000, 'ppn_persen' => 11, 'ongkir' => 20000,
        ];
        $this->terbitkanPo($pr['id_permintaan'], $payload)->assertStatus(200);
        $this->postJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/bukti", ['tahap' => 'pembelian', 'bukti' => [UploadedFile::fake()->image('nota.jpg')]])->assertStatus(200);
        return $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/dibeli", $payload)->assertStatus(200)->json('data');
    }

    private function terima(array $pr, int $qtyBarang, int $qtyJasa): void
    {
        $this->actingAsRole('PENGADAAN');
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/terima", [
            'tanggal_diterima' => now()->toDateString(),
            'items' => [
                ['id_item' => $pr['items'][0]['id_item'], 'qty_diterima' => $qtyBarang],
                ['id_item' => $pr['items'][1]['id_item'], 'qty_diterima' => $qtyJasa],
            ],
        ])->assertStatus(200);
    }

    public function test_pr_dibeli_memberi_tahu_keuangan_dan_siap_transfer_ditahan_sampai_barang_diterima(): void
    {
        $keuangan = $this->buatPengguna('KEUANGAN');
        $pr = $this->prUmumDibeli();
        $pengajuan = DB::table('pengajuan_pengeluaran')->where('id_permintaan_pembelian', $pr['id_permintaan'])->first();

        $verifikasi = $this->notif($keuangan, 'keuangan_verifikasi');
        $this->assertCount(1, $verifikasi);
        $this->assertSame("Pengajuan {$pengajuan->nomor_pengajuan} perlu diverifikasi", $verifikasi[0]->judul);
        $this->assertSame('Pengadaan Rp 889.130 untuk Toko ATK Jaya.', $verifikasi[0]->isi);

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$pengajuan->id_pengajuan}/cek")->assertStatus(200)->assertJsonPath('data.status', 'siap_transfer');
        $this->assertCount(0, $this->notif($keuangan, 'keuangan_transfer'));

        $this->terima($pr, 4, 1);
        $this->assertCount(0, $this->notif($keuangan, 'keuangan_transfer'));

        $this->terima($pr, 6, 0);
        $transfer = $this->notif($keuangan, 'keuangan_transfer');
        $this->assertCount(1, $transfer);
        $this->assertSame("Pengajuan {$pengajuan->nomor_pengajuan} siap ditransfer", $transfer[0]->judul);
        $this->assertSame("Barang {$pr['nomor_permintaan']} sudah diterima. Pengadaan Rp 889.130 untuk Toko ATK Jaya.", $transfer[0]->isi);
        $this->assertSame('/proses-pembayaran?tab=siap', $transfer[0]->link);
    }

    public function test_tutup_sisa_memberi_tahu_keuangan_dengan_nilai_yang_disesuaikan(): void
    {
        $keuangan = $this->buatPengguna('KEUANGAN');
        $pr = $this->prUmumDibeli();
        $pengajuan = DB::table('pengajuan_pengeluaran')->where('id_permintaan_pembelian', $pr['id_permintaan'])->first();
        $this->terima($pr, 4, 1);

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$pengajuan->id_pengajuan}/cek")->assertStatus(200);
        $this->assertCount(0, $this->notif($keuangan, 'keuangan_transfer'));

        $this->actingAsRole('PENGADAAN');
        $this->patchJson("/api/permintaan-pembelian/{$pr['id_permintaan']}/tutup-sisa", ['alasan' => 'Stok supplier habis'])->assertStatus(200);

        $transfer = $this->notif($keuangan, 'keuangan_transfer');
        $this->assertCount(1, $transfer);
        $this->assertSame("Sisa pesanan {$pr['nomor_permintaan']} ditutup, nilai disesuaikan. Pengadaan Rp 577.442 untuk Toko ATK Jaya.", $transfer[0]->isi);
    }

    public function test_verifikasi_setelah_barang_diterima_langsung_memberi_tahu_siap_transfer(): void
    {
        $keuangan = $this->buatPengguna('KEUANGAN');
        $pr = $this->prUmumDibeli();
        $pengajuan = DB::table('pengajuan_pengeluaran')->where('id_permintaan_pembelian', $pr['id_permintaan'])->first();
        $this->terima($pr, 10, 1);
        $this->assertCount(0, $this->notif($keuangan, 'keuangan_transfer'));

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$pengajuan->id_pengajuan}/cek")->assertStatus(200);

        $transfer = $this->notif($keuangan, 'keuangan_transfer');
        $this->assertCount(1, $transfer);
        $this->assertSame('Pengadaan Rp 889.130 untuk Toko ATK Jaya.', $transfer[0]->isi);
    }
}
