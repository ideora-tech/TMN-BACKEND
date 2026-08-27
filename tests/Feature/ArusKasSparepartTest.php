<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\ArusKas\ArusKasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ArusKasSparepartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePerusahaan();

        $idApprover = (string) Str::uuid();
        DB::table('pengguna')->insert([
            'id_pengguna' => $idApprover, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran' => 'MANAGER', 'username' => 'approver_default_' . Str::random(8),
            'email' => Str::random(8) . '@test.id', 'kata_sandi' => bcrypt('x'),
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $idEventType = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $idEventType, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => 'pengajuan_pengeluaran', 'nama' => 'Pengajuan Pengeluaran', 'mode_resolusi' => 'pinned',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'pengguna', 'id_pengguna' => $idApprover, 'dibuat_pada' => now(),
        ]);
    }

    private function makeSupplier(string $nama = 'Toko Sparepart Jaya'): string
    {
        $id = (string) Str::uuid();
        DB::table('supplier')->insert([
            'id_supplier'   => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => $nama,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makeSparepart(string $nama = 'Oli Mesin'): string
    {
        $id = (string) Str::uuid();
        DB::table('sparepart')->insert([
            'id_sparepart'  => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode'          => 'SP-' . Str::random(6),
            'nama'          => $nama,
            'satuan'        => 'pcs',
            'harga_standar' => 50000,
            'stok'          => 0,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makePerawatan(): string
    {
        $idArmada = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada'     => $idArmada,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B ' . rand(1000, 9999) . ' AK',
            'dibuat_pada'   => now(),
        ]);
        $idPerawatan = (string) Str::uuid();
        DB::table('perawatan_armada')->insert([
            'id_perawatan'    => $idPerawatan,
            'id_armada'       => $idArmada,
            'tanggal'         => now()->toDateString(),
            'jenis_perawatan' => 'Servis Rutin',
            'biaya'           => 0,
            'dibuat_pada'     => now(),
        ]);
        return $idPerawatan;
    }

    private function payloadPembelian(array $override = []): array
    {
        return array_merge([
            'id_supplier'       => $this->makeSupplier(),
            'tanggal_pengajuan' => now()->toDateString(),
            'items'             => [
                ['id_sparepart' => $this->makeSparepart('Oli Mesin'), 'qty' => 2, 'harga_estimasi' => 60000],
                ['id_sparepart' => $this->makeSparepart('Filter Udara'), 'qty' => 1, 'harga_estimasi' => 80000],
            ],
        ], $override);
    }

    private function pengajuanUntukPembelian(string $idPembelian): ?object
    {
        return DB::table('pengajuan_pengeluaran')->where('id_pembelian', $idPembelian)->first();
    }

    public function test_store_non_perawatan_membuat_pengajuan_otomatis(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/pembelian-sparepart', $this->payloadPembelian());
        $res->assertStatus(201);
        $idPembelian = $res->json('data.id_pembelian');

        $pengajuan = $this->pengajuanUntukPembelian($idPembelian);
        $this->assertNotNull($pengajuan);
        $this->assertSame('sparepart', $pengajuan->kategori);
        $this->assertEquals(200000, (float) $pengajuan->nominal);
        $this->assertSame('menunggu_approval', $pengajuan->status);
        $this->assertSame($idPembelian, $pengajuan->id_pembelian);
        $this->assertNotNull($pengajuan->nomor_pengajuan);
        $this->assertStringContainsString('Toko Sparepart Jaya', (string) $pengajuan->keterangan);

        $resPengajuan = $this->getJson("/api/arus-kas/pengajuan/{$pengajuan->id_pengajuan}");
        $resPengajuan->assertStatus(200)->assertJsonPath('data.id_pembelian', $idPembelian);
    }

    public function test_store_dengan_id_perawatan_tidak_membuat_pengajuan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPerawatan = $this->makePerawatan();

        $res = $this->postJson('/api/pembelian-sparepart', $this->payloadPembelian(['id_perawatan' => $idPerawatan]));
        $res->assertStatus(201)->assertJsonPath('data.status', 'disetujui_finance');
        $idPembelian = $res->json('data.id_pembelian');

        $row = DB::table('pembelian_sparepart')->where('id_pembelian', $idPembelian)->first();
        $this->assertSame('disetujui_finance', $row->status);
        $this->assertNotNull($row->disetujui_manager_oleh);
        $this->assertNotNull($row->disetujui_manager_pada);
        $this->assertNotNull($row->disetujui_finance_oleh);
        $this->assertNotNull($row->disetujui_finance_pada);

        $this->assertNull($this->pengajuanUntukPembelian($idPembelian));
    }

    public function test_dedup_pengajuan_pembelian_per_id_pembelian(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPembelian = $this->postJson('/api/pembelian-sparepart', $this->payloadPembelian())->json('data.id_pembelian');
        $this->assertSame(1, DB::table('pengajuan_pengeluaran')->where('id_pembelian', $idPembelian)->count());

        app(ArusKasService::class)->buatPengajuanPembelianOtomatis((object) ['id_pembelian' => $idPembelian], 200000);

        $this->assertSame(1, DB::table('pengajuan_pengeluaran')->where('id_pembelian', $idPembelian)->count());
    }

    public function test_update_total_estimasi_sinkron_ke_nominal_pengajuan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPembelian = $this->postJson('/api/pembelian-sparepart', $this->payloadPembelian())->json('data.id_pembelian');
        $idPengajuan = $this->pengajuanUntukPembelian($idPembelian)->id_pengajuan;

        $idSparepartBaru = $this->makeSparepart('Kampas Rem');
        $this->putJson("/api/pembelian-sparepart/{$idPembelian}", $this->payloadPembelian([
            'items' => [['id_sparepart' => $idSparepartBaru, 'qty' => 3, 'harga_estimasi' => 40000]],
        ]))->assertStatus(200)->assertJsonPath('data.total_estimasi', 120000);

        $pengajuan = DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idPengajuan)->first();
        $this->assertEquals(120000, (float) $pengajuan->nominal);
    }

    public function test_update_lepas_id_perawatan_membuat_pengajuan_otomatis(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPerawatan = $this->makePerawatan();
        $create = $this->postJson('/api/pembelian-sparepart', $this->payloadPembelian(['id_perawatan' => $idPerawatan]));
        $create->assertJsonPath('data.status', 'disetujui_finance');
        $idPembelian = $create->json('data.id_pembelian');
        $this->assertNull($this->pengajuanUntukPembelian($idPembelian));

        $this->putJson("/api/pembelian-sparepart/{$idPembelian}", $this->payloadPembelian(['id_perawatan' => null]))
            ->assertStatus(200)
            ->assertJsonPath('data.id_perawatan', null)
            ->assertJsonPath('data.status', 'diajukan');

        $row = DB::table('pembelian_sparepart')->where('id_pembelian', $idPembelian)->first();
        $this->assertSame('diajukan', $row->status);
        $this->assertNull($row->disetujui_manager_oleh);
        $this->assertNull($row->disetujui_manager_pada);
        $this->assertNull($row->disetujui_finance_oleh);
        $this->assertNull($row->disetujui_finance_pada);

        $pengajuan = $this->pengajuanUntukPembelian($idPembelian);
        $this->assertNotNull($pengajuan);
        $this->assertSame('sparepart', $pengajuan->kategori);
        $this->assertSame('menunggu_approval', $pengajuan->status);
        $this->assertEquals(200000, (float) $pengajuan->nominal);
        $this->assertSame($idPembelian, $pengajuan->id_pembelian);
    }

    public function test_update_tambah_id_perawatan_menghapus_pengajuan_otomatis_lama(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPerawatan = $this->makePerawatan();
        $idPembelian = $this->postJson('/api/pembelian-sparepart', $this->payloadPembelian())->json('data.id_pembelian');
        $idPengajuan = $this->pengajuanUntukPembelian($idPembelian)->id_pengajuan;

        $this->putJson("/api/pembelian-sparepart/{$idPembelian}", $this->payloadPembelian(['id_perawatan' => $idPerawatan]))
            ->assertStatus(200)
            ->assertJsonPath('data.id_perawatan', $idPerawatan)
            ->assertJsonPath('data.status', 'disetujui_finance');

        $this->assertSoftDeleted('pengajuan_pengeluaran', ['id_pengajuan' => $idPengajuan]);

        $row = DB::table('pembelian_sparepart')->where('id_pembelian', $idPembelian)->first();
        $this->assertSame('disetujui_finance', $row->status);
        $this->assertNotNull($row->disetujui_manager_oleh);
        $this->assertNotNull($row->disetujui_manager_pada);
        $this->assertNotNull($row->disetujui_finance_oleh);
        $this->assertNotNull($row->disetujui_finance_pada);
    }

    private function ajukanSampaiDicek(): array
    {
        $this->actingAsRole('SUPERADMIN');
        $this->putJson('/api/arus-kas/pengaturan-approval', ['batas' => 999999999])->assertStatus(200);
        $create = $this->postJson('/api/pembelian-sparepart', $this->payloadPembelian());
        $idPembelian = $create->json('data.id_pembelian');
        $items = $create->json('data.items');
        $idPengajuan = $this->pengajuanUntukPembelian($idPembelian)->id_pengajuan;

        $this->assertSame('disetujui', $this->pengajuanUntukPembelian($idPembelian)->status);

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/cek")
            ->assertStatus(200)->assertJsonPath('data.status', 'siap_transfer');

        return [$idPembelian, $items, $idPengajuan];
    }

    public function test_setujui_di_arus_kas_sinkron_status_pembelian_dan_boleh_realisasi(): void
    {
        Storage::fake('public');
        [$idPembelian, $items] = $this->ajukanSampaiDicek();

        $row = DB::table('pembelian_sparepart')->where('id_pembelian', $idPembelian)->first();
        $this->assertSame('disetujui_finance', $row->status);
        $this->assertNotNull($row->disetujui_manager_oleh);
        $this->assertNotNull($row->disetujui_manager_pada);
        $this->assertNotNull($row->disetujui_finance_oleh);
        $this->assertNotNull($row->disetujui_finance_pada);

        $this->actingAsRole('SUPERADMIN');
        $this->postJson("/api/pembelian-sparepart/{$idPembelian}/bukti", [
            'bukti' => [UploadedFile::fake()->image('nota.jpg')],
        ])->assertStatus(200);

        $res = $this->patchJson("/api/pembelian-sparepart/{$idPembelian}/realisasi", [
            'tanggal_pembelian' => now()->toDateString(),
            'items'             => [
                ['id_item' => $items[0]['id_item'], 'harga_aktual' => 65000],
                ['id_item' => $items[1]['id_item'], 'harga_aktual' => 75000],
            ],
        ]);
        $res->assertStatus(200)
            ->assertJsonPath('data.status', 'dibeli')
            ->assertJsonPath('data.total_aktual', 205000);
    }

    public function test_nominal_pengajuan_sinkron_dari_estimasi_ke_aktual_setelah_realisasi(): void
    {
        Storage::fake('public');
        [$idPembelian, $items, $idPengajuan] = $this->ajukanSampaiDicek();

        $sebelum = DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idPengajuan)->first();
        $this->assertEquals(200000, (float) $sebelum->nominal);

        $this->actingAsRole('SUPERADMIN');
        $this->postJson("/api/pembelian-sparepart/{$idPembelian}/bukti", [
            'bukti' => [UploadedFile::fake()->image('nota.jpg')],
        ])->assertStatus(200);
        $this->patchJson("/api/pembelian-sparepart/{$idPembelian}/realisasi", [
            'tanggal_pembelian' => now()->toDateString(),
            'items'             => [
                ['id_item' => $items[0]['id_item'], 'harga_aktual' => 65000],
                ['id_item' => $items[1]['id_item'], 'harga_aktual' => 75000],
            ],
        ])->assertStatus(200);

        $sesudah = DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idPengajuan)->first();
        $this->assertEquals(205000, (float) $sesudah->nominal);
        $this->assertSame('siap_transfer', $sesudah->status);
    }

    public function test_tolak_di_arus_kas_sinkron_dua_arah_ke_pembelian(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->putJson('/api/arus-kas/pengaturan-approval', ['batas' => 999999999])->assertStatus(200);
        $idPembelian = $this->postJson('/api/pembelian-sparepart', $this->payloadPembelian())->json('data.id_pembelian');
        $idPengajuan = $this->pengajuanUntukPembelian($idPembelian)->id_pengajuan;
        $this->assertSame('disetujui', $this->pengajuanUntukPembelian($idPembelian)->status);

        $this->actingAsRole('MANAGER');
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/tolak", ['alasan' => 'Harga tidak wajar'])
            ->assertStatus(200)->assertJsonPath('data.status', 'ditolak');

        $row = DB::table('pembelian_sparepart')->where('id_pembelian', $idPembelian)->first();
        $this->assertSame('ditolak', $row->status);
        $this->assertSame('Harga tidak wajar', $row->alasan_ditolak);
    }

    public function test_transfer_uang_muka_saat_disetujui_finance_berhasil_status_tetap_disetujui_finance(): void
    {
        [$idPembelian, , $idPengajuan] = $this->ajukanSampaiDicek();

        $this->actingAsRole('KEUANGAN');
        $tanggalTransfer = now()->toDateString();
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/transfer", [
            'tanggal_transfer' => $tanggalTransfer,
        ])->assertStatus(200)->assertJsonPath('data.status', 'ditransfer');

        $rowPengajuan = DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idPengajuan)->first();
        $this->assertSame('ditransfer', $rowPengajuan->status);
        $rowPembelian = DB::table('pembelian_sparepart')->where('id_pembelian', $idPembelian)->first();
        $this->assertSame('disetujui_finance', $rowPembelian->status);
        $this->assertEquals($tanggalTransfer, $rowPembelian->tanggal_pembayaran);
    }

    public function test_transfer_uang_muka_masuk_rekap_dengan_nominal_transfer(): void
    {
        [, , $idPengajuan] = $this->ajukanSampaiDicek();

        $this->actingAsRole('KEUANGAN');
        $tanggalTransfer = now()->toDateString();
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/transfer", [
            'tanggal_transfer' => $tanggalTransfer,
        ])->assertStatus(200);

        $dari   = now()->startOfMonth()->toDateString();
        $sampai = now()->endOfMonth()->toDateString();
        $rekap = $this->getJson("/api/arus-kas?dari={$dari}&sampai={$sampai}");
        $rows = collect($rekap->json('data.transaksi'))
            ->where('sumber', 'pengajuan_pengeluaran')
            ->where('kategori', 'sparepart')
            ->values();
        $this->assertCount(1, $rows);
        $this->assertEquals(200000, $rows[0]['nominal']);
        $this->assertSame('keluar', $rows[0]['arah']);
    }

    public function test_transfer_ditolak_409_jika_pembelian_belum_disetujui_finance(): void
    {
        [$idPembelian, , $idPengajuan] = $this->ajukanSampaiDicek();
        DB::table('pembelian_sparepart')->where('id_pembelian', $idPembelian)->update(['status' => 'diajukan']);

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/transfer", [
            'tanggal_transfer' => now()->toDateString(),
        ])->assertStatus(409);

        $rowPengajuan = DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idPengajuan)->first();
        $this->assertSame('siap_transfer', $rowPengajuan->status);
    }

    public function test_transfer_setelah_dibeli_menjadi_lunas_dengan_tanggal_pembayaran(): void
    {
        Storage::fake('public');
        [$idPembelian, $items, $idPengajuan] = $this->ajukanSampaiDicek();

        $this->actingAsRole('SUPERADMIN');
        $this->postJson("/api/pembelian-sparepart/{$idPembelian}/bukti", [
            'bukti' => [UploadedFile::fake()->image('nota.jpg')],
        ])->assertStatus(200);
        $this->patchJson("/api/pembelian-sparepart/{$idPembelian}/realisasi", [
            'tanggal_pembelian' => now()->toDateString(),
            'items'             => [
                ['id_item' => $items[0]['id_item'], 'harga_aktual' => 65000],
                ['id_item' => $items[1]['id_item'], 'harga_aktual' => 75000],
            ],
        ])->assertStatus(200);

        $this->actingAsRole('KEUANGAN');
        $tanggalTransfer = now()->addDay()->toDateString();
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/transfer", [
            'tanggal_transfer' => $tanggalTransfer,
        ])->assertStatus(200)->assertJsonPath('data.status', 'ditransfer');

        $row = DB::table('pembelian_sparepart')->where('id_pembelian', $idPembelian)->first();
        $this->assertSame('lunas', $row->status);
        $this->assertEquals($tanggalTransfer, $row->tanggal_pembayaran);
    }

    public function test_hapus_pembelian_saat_diajukan_ikut_soft_delete_pengajuan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPembelian = $this->postJson('/api/pembelian-sparepart', $this->payloadPembelian())->json('data.id_pembelian');
        $idPengajuan = $this->pengajuanUntukPembelian($idPembelian)->id_pengajuan;

        $this->deleteJson("/api/pembelian-sparepart/{$idPembelian}")->assertStatus(200);

        $this->assertSoftDeleted('pengajuan_pengeluaran', ['id_pengajuan' => $idPengajuan]);
    }

    public function test_rekap_sparepart_tidak_lagi_sumber_langsung_hanya_muncul_via_pengajuan_ditransfer(): void
    {
        Storage::fake('public');
        [$idPembelian, $items, $idPengajuan] = $this->ajukanSampaiDicek();

        $this->actingAsRole('SUPERADMIN');
        $this->postJson("/api/pembelian-sparepart/{$idPembelian}/bukti", [
            'bukti' => [UploadedFile::fake()->image('nota.jpg')],
        ]);
        $this->patchJson("/api/pembelian-sparepart/{$idPembelian}/realisasi", [
            'tanggal_pembelian' => now()->toDateString(),
            'items'             => [
                ['id_item' => $items[0]['id_item'], 'harga_aktual' => 65000],
                ['id_item' => $items[1]['id_item'], 'harga_aktual' => 75000],
            ],
        ]);

        $dari   = now()->startOfMonth()->toDateString();
        $sampai = now()->endOfMonth()->toDateString();

        $rekapSebelum = $this->getJson("/api/arus-kas?dari={$dari}&sampai={$sampai}");
        $this->assertCount(0, collect($rekapSebelum->json('data.transaksi'))->where('sumber', 'pembelian_sparepart'));
        $this->assertCount(0, collect($rekapSebelum->json('data.transaksi'))->where('kategori', 'sparepart'));

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/transfer", [
            'tanggal_transfer' => now()->toDateString(),
        ])->assertStatus(200);

        $rekapSesudah = $this->getJson("/api/arus-kas?dari={$dari}&sampai={$sampai}");
        $rows = collect($rekapSesudah->json('data.transaksi'))
            ->where('sumber', 'pengajuan_pengeluaran')
            ->where('kategori', 'sparepart')
            ->values();
        $this->assertCount(1, $rows);
        $this->assertEquals(205000, $rows[0]['nominal']);
        $this->assertSame('keluar', $rows[0]['arah']);

        $this->assertCount(0, collect($rekapSesudah->json('data.transaksi'))->where('sumber', 'pembelian_sparepart'));
    }
}
