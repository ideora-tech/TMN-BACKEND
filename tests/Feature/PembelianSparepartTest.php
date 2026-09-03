<?php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PembelianSparepartTest extends TestCase
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

    private function makeSupplier(?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('supplier')->insert([
            'id_supplier' => $id, 'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'nama' => 'Toko Test', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeSparepart(string $nama = 'Oli Mesin', int $stok = 0, ?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('sparepart')->insert([
            'id_sparepart' => $id, 'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'kode' => 'SP-' . Str::random(6), 'nama' => $nama, 'satuan' => 'pcs',
            'harga_standar' => 50000, 'stok' => $stok, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function payloadPengajuan(array $override = []): array
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

    public function test_create_pengajuan_berhasil_dengan_nomor_dan_total(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/pembelian-sparepart', $this->payloadPengajuan());

        $res->assertStatus(201)
            ->assertJsonPath('data.status', 'diajukan')
            ->assertJsonPath('data.total_estimasi', 200000);
        $this->assertMatchesRegularExpression('/^PS-\d{6}-0001$/', $res->json('data.nomor_pengajuan'));
        $this->assertCount(2, $res->json('data.items'));
        $this->assertSame('Oli Mesin', $res->json('data.items.0.nama_sparepart'));
    }

    public function test_nomor_urut_bertambah(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->postJson('/api/pembelian-sparepart', $this->payloadPengajuan())->assertStatus(201);
        $res2 = $this->postJson('/api/pembelian-sparepart', $this->payloadPengajuan());
        $this->assertMatchesRegularExpression('/-0002$/', $res2->json('data.nomor_pengajuan'));
    }

    public function test_validasi_item_kosong_dan_qty_nol(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->postJson('/api/pembelian-sparepart', $this->payloadPengajuan(['items' => []]))->assertStatus(422);
        $this->postJson('/api/pembelian-sparepart', $this->payloadPengajuan([
            'items' => [['id_sparepart' => $this->makeSparepart(), 'qty' => 0, 'harga_estimasi' => 1000]],
        ]))->assertStatus(422);
    }

    public function test_sparepart_perusahaan_lain_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);

        $this->postJson('/api/pembelian-sparepart', $this->payloadPengajuan([
            'items' => [['id_sparepart' => $this->makeSparepart('Punya Orang', 0, $idLain), 'qty' => 1, 'harga_estimasi' => 1000]],
        ]))->assertStatus(422);
    }

    public function test_kaitan_perawatan_opsional_tidak_membuat_pengajuan_arus_kas(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idArmada = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada' => $idArmada, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol' => 'B ' . rand(1000, 9999) . ' TMN', 'dibuat_pada' => now(),
        ]);
        $idPerawatan = (string) Str::uuid();
        DB::table('perawatan_armada')->insert([
            'id_perawatan' => $idPerawatan, 'id_armada' => $idArmada,
            'tanggal' => now()->toDateString(), 'jenis_perawatan' => 'Servis Rutin',
            'biaya' => 0, 'dibuat_pada' => now(),
        ]);

        $res = $this->postJson('/api/pembelian-sparepart', $this->payloadPengajuan(['id_perawatan' => $idPerawatan]));
        $res->assertStatus(201)
            ->assertJsonPath('data.id_perawatan', $idPerawatan)
            ->assertJsonPath('data.status', 'disetujui_finance');
        $this->assertNotNull($res->json('data.nopol_armada'));
        $this->assertNotNull($res->json('data.disetujui_manager_pada'));
        $this->assertNotNull($res->json('data.disetujui_finance_pada'));

        $this->assertNull($this->pengajuanUntukPembelian($res->json('data.id_pembelian')));
    }

    public function test_list_filter_status_dan_search_nomor(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->postJson('/api/pembelian-sparepart', $this->payloadPengajuan())->json('data.id_pembelian');
        DB::table('pembelian_sparepart')->where('id_pembelian', $id)->update(['status' => 'dibeli']);
        $this->postJson('/api/pembelian-sparepart', $this->payloadPengajuan());

        $this->assertCount(1, $this->getJson('/api/pembelian-sparepart?status=dibeli')->json('data'));
        $this->assertCount(1, $this->getJson('/api/pembelian-sparepart?search=0002')->json('data'));
    }

    public function test_update_dan_delete_hanya_saat_diajukan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $create = $this->postJson('/api/pembelian-sparepart', $this->payloadPengajuan());
        $id = $create->json('data.id_pembelian');
        $idSparepartBaru = $this->makeSparepart('Kampas Rem');

        $this->putJson("/api/pembelian-sparepart/{$id}", $this->payloadPengajuan([
            'items' => [['id_sparepart' => $idSparepartBaru, 'qty' => 3, 'harga_estimasi' => 40000]],
        ]))->assertStatus(200)->assertJsonPath('data.total_estimasi', 120000);

        DB::table('pembelian_sparepart')->where('id_pembelian', $id)->update(['status' => 'disetujui_finance']);
        $this->putJson("/api/pembelian-sparepart/{$id}", $this->payloadPengajuan())->assertStatus(422);
        $this->deleteJson("/api/pembelian-sparepart/{$id}")->assertStatus(422);

        DB::table('pembelian_sparepart')->where('id_pembelian', $id)->update(['status' => 'diajukan']);
        $this->deleteJson("/api/pembelian-sparepart/{$id}")->assertStatus(200);
        $this->assertSoftDeleted('pembelian_sparepart', ['id_pembelian' => $id]);
    }

    private function buatPembelianBerPerawatan(array $overrideItems = []): array
    {
        $idArmada = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada' => $idArmada, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol' => 'B ' . rand(1000, 9999) . ' TMN', 'dibuat_pada' => now(),
        ]);
        $idPerawatan = (string) Str::uuid();
        DB::table('perawatan_armada')->insert([
            'id_perawatan' => $idPerawatan, 'id_armada' => $idArmada,
            'tanggal' => now()->toDateString(), 'jenis_perawatan' => 'Servis Rutin',
            'biaya' => 0, 'dibuat_pada' => now(),
        ]);

        $payload = $this->payloadPengajuan(array_merge(['id_perawatan' => $idPerawatan], $overrideItems));
        $create = $this->postJson('/api/pembelian-sparepart', $payload);
        $create->assertStatus(201)->assertJsonPath('data.status', 'disetujui_finance');

        return ['id_pembelian' => $create->json('data.id_pembelian'), 'id_perawatan' => $idPerawatan];
    }

    public function test_delete_ber_perawatan_saat_disetujui_finance_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $data = $this->buatPembelianBerPerawatan();

        $this->deleteJson("/api/pembelian-sparepart/{$data['id_pembelian']}")->assertStatus(200);
        $this->assertSoftDeleted('pembelian_sparepart', ['id_pembelian' => $data['id_pembelian']]);
    }

    public function test_delete_non_perawatan_saat_disetujui_finance_tetap_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->postJson('/api/pembelian-sparepart', $this->payloadPengajuan())->json('data.id_pembelian');
        DB::table('pembelian_sparepart')->where('id_pembelian', $id)->update(['status' => 'disetujui_finance']);

        $this->deleteJson("/api/pembelian-sparepart/{$id}")->assertStatus(422);
        $this->assertDatabaseHas('pembelian_sparepart', ['id_pembelian' => $id, 'dihapus_pada' => null]);
    }

    public function test_edit_ber_perawatan_saat_disetujui_finance_berhasil_tanpa_diblokir(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $data = $this->buatPembelianBerPerawatan();
        $idSparepartBaru = $this->makeSparepart('Busi');

        $res = $this->putJson("/api/pembelian-sparepart/{$data['id_pembelian']}", $this->payloadPengajuan([
            'id_perawatan' => $data['id_perawatan'],
            'items'        => [['id_sparepart' => $idSparepartBaru, 'qty' => 2, 'harga_estimasi' => 30000]],
        ]));

        $res->assertStatus(200)
            ->assertJsonPath('data.status', 'disetujui_finance')
            ->assertJsonPath('data.id_perawatan', $data['id_perawatan'])
            ->assertJsonPath('data.total_estimasi', 60000);
    }

    public function test_isolasi_tenant(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->postJson('/api/pembelian-sparepart', $this->payloadPengajuan())->json('data.id_pembelian');

        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        DB::table('pembelian_sparepart')->where('id_pembelian', $id)->update(['id_perusahaan' => $idLain]);

        $this->getJson("/api/pembelian-sparepart/{$id}")->assertStatus(404);
    }

    public function test_route_approval_lama_sudah_dihapus(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->postJson('/api/pembelian-sparepart', $this->payloadPengajuan())->json('data.id_pembelian');

        $this->actingAsRole('MANAGER');
        $this->patchJson("/api/pembelian-sparepart/{$id}/approve-manager")->assertStatus(404);
        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/pembelian-sparepart/{$id}/approve-finance")->assertStatus(404);
        $this->patchJson("/api/pembelian-sparepart/{$id}/tolak", ['alasan' => 'x'])->assertStatus(404);
        $this->patchJson("/api/pembelian-sparepart/{$id}/lunas", ['tanggal_pembayaran' => now()->toDateString()])->assertStatus(404);
    }

    public function test_alur_baru_approval_via_arus_kas_lalu_realisasi_dan_transfer_menggantikan_lunas(): void
    {
        Storage::fake('public');
        $this->actingAsRole('SUPERADMIN');
        $this->putJson('/api/arus-kas/pengaturan-approval', ['batas' => 999999999])->assertStatus(200);
        $create = $this->postJson('/api/pembelian-sparepart', $this->payloadPengajuan());
        $idPembelian = $create->json('data.id_pembelian');
        $items = $create->json('data.items');
        $idPengajuan = $this->pengajuanUntukPembelian($idPembelian)->id_pengajuan;

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/cek")
            ->assertStatus(200)->assertJsonPath('data.status', 'siap_transfer');

        $rowSetelahSetuju = DB::table('pembelian_sparepart')->where('id_pembelian', $idPembelian)->first();
        $this->assertSame('disetujui_finance', $rowSetelahSetuju->status);

        $this->actingAsRole('SUPERADMIN');
        $this->postJson("/api/pembelian-sparepart/{$idPembelian}/bukti", [
            'bukti' => [UploadedFile::fake()->image('nota.jpg')],
        ])->assertStatus(200);
        $this->patchJson("/api/pembelian-sparepart/{$idPembelian}/realisasi", [
            'tanggal_pembelian' => now()->toDateString(),
            'items' => [
                ['id_item' => $items[0]['id_item'], 'harga_aktual' => 65000],
                ['id_item' => $items[1]['id_item'], 'harga_aktual' => 75000],
            ],
        ])->assertStatus(200)->assertJsonPath('data.status', 'dibeli');

        $this->actingAsRole('KEUANGAN');
        $this->patch("/api/arus-kas/pengajuan/{$idPengajuan}/transfer", [
            'tanggal_transfer' => now()->toDateString(),
            'bukti'            => UploadedFile::fake()->create('bukti.jpg', 5, 'image/jpeg'),
        ])->assertStatus(200)->assertJsonPath('data.status', 'ditransfer');

        $rowFinal = DB::table('pembelian_sparepart')->where('id_pembelian', $idPembelian)->first();
        $this->assertSame('lunas', $rowFinal->status);
        $this->assertEquals(now()->toDateString(), $rowFinal->tanggal_pembayaran);
    }

    public function test_alur_ber_perawatan_langsung_disetujui_finance_lalu_realisasi_menambah_stok(): void
    {
        Storage::fake('public');
        $this->actingAsRole('SUPERADMIN');

        $idArmada = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada' => $idArmada, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol' => 'B ' . rand(1000, 9999) . ' TMN', 'dibuat_pada' => now(),
        ]);
        $idPerawatan = (string) Str::uuid();
        DB::table('perawatan_armada')->insert([
            'id_perawatan' => $idPerawatan, 'id_armada' => $idArmada,
            'tanggal' => now()->toDateString(), 'jenis_perawatan' => 'Servis Rutin',
            'biaya' => 0, 'dibuat_pada' => now(),
        ]);
        $idSparepart = $this->makeSparepart('Oli Gardan', 5);

        $create = $this->postJson('/api/pembelian-sparepart', $this->payloadPengajuan([
            'id_perawatan' => $idPerawatan,
            'items'        => [['id_sparepart' => $idSparepart, 'qty' => 4, 'harga_estimasi' => 70000]],
        ]));
        $create->assertStatus(201)
            ->assertJsonPath('data.id_perawatan', $idPerawatan)
            ->assertJsonPath('data.status', 'disetujui_finance');
        $idPembelian = $create->json('data.id_pembelian');
        $idItem = $create->json('data.items.0.id_item');
        $this->assertNull($this->pengajuanUntukPembelian($idPembelian));

        $this->postJson("/api/pembelian-sparepart/{$idPembelian}/bukti", [
            'bukti' => [UploadedFile::fake()->image('nota.jpg')],
        ])->assertStatus(200);

        $res = $this->patchJson("/api/pembelian-sparepart/{$idPembelian}/realisasi", [
            'tanggal_pembelian' => now()->toDateString(),
            'items'             => [
                ['id_item' => $idItem, 'harga_aktual' => 72000],
            ],
        ]);
        $res->assertStatus(200)
            ->assertJsonPath('data.status', 'dibeli')
            ->assertJsonPath('data.total_aktual', 288000);

        $stokSesudah = DB::table('sparepart')->where('id_sparepart', $idSparepart)->value('stok');
        $this->assertSame(9, (int) $stokSesudah);
    }

    private function ajukanSampaiTransferUangMuka(): array
    {
        $this->actingAsRole('SUPERADMIN');
        $this->putJson('/api/arus-kas/pengaturan-approval', ['batas' => 999999999])->assertStatus(200);
        $create = $this->postJson('/api/pembelian-sparepart', $this->payloadPengajuan());
        $idPembelian = $create->json('data.id_pembelian');
        $items = $create->json('data.items');
        $idPengajuan = $this->pengajuanUntukPembelian($idPembelian)->id_pengajuan;

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/cek")
            ->assertStatus(200)->assertJsonPath('data.status', 'siap_transfer');

        $tanggalTransfer = now()->toDateString();
        $this->patch("/api/arus-kas/pengajuan/{$idPengajuan}/transfer", [
            'tanggal_transfer' => $tanggalTransfer,
            'bukti'            => UploadedFile::fake()->create('bukti.jpg', 5, 'image/jpeg'),
        ])->assertStatus(200);

        return [$idPembelian, $items, $idPengajuan, $tanggalTransfer];
    }

    public function test_realisasi_setelah_uang_muka_transfer_langsung_lunas_dan_nominal_pengajuan_tidak_berubah(): void
    {
        Storage::fake('public');
        [$idPembelian, $items, $idPengajuan, $tanggalTransfer] = $this->ajukanSampaiTransferUangMuka();

        $rowSetelahTransfer = DB::table('pembelian_sparepart')->where('id_pembelian', $idPembelian)->first();
        $this->assertSame('disetujui_finance', $rowSetelahTransfer->status);
        $this->assertEquals($tanggalTransfer, $rowSetelahTransfer->tanggal_pembayaran);

        $this->actingAsRole('SUPERADMIN');
        $this->postJson("/api/pembelian-sparepart/{$idPembelian}/bukti", [
            'bukti' => [UploadedFile::fake()->image('nota.jpg')],
        ])->assertStatus(200);
        $res = $this->patchJson("/api/pembelian-sparepart/{$idPembelian}/realisasi", [
            'tanggal_pembelian' => now()->toDateString(),
            'items' => [
                ['id_item' => $items[0]['id_item'], 'harga_aktual' => 65000],
                ['id_item' => $items[1]['id_item'], 'harga_aktual' => 90000],
            ],
        ]);
        $res->assertStatus(200)
            ->assertJsonPath('data.status', 'lunas')
            ->assertJsonPath('data.total_aktual', 220000)
            ->assertJsonPath('data.tanggal_pembayaran', $tanggalTransfer);

        $pengajuan = DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idPengajuan)->first();
        $this->assertEquals(200000, (float) $pengajuan->nominal);
        $this->assertSame('ditransfer', $pengajuan->status);
    }

    public function test_detail_pembelian_menampilkan_data_pembayaran_setelah_uang_muka_dan_realisasi(): void
    {
        Storage::fake('public');
        [$idPembelian, $items] = $this->ajukanSampaiTransferUangMuka();

        $this->actingAsRole('SUPERADMIN');
        $this->postJson("/api/pembelian-sparepart/{$idPembelian}/bukti", [
            'bukti' => [UploadedFile::fake()->image('nota.jpg')],
        ])->assertStatus(200);
        $this->patchJson("/api/pembelian-sparepart/{$idPembelian}/realisasi", [
            'tanggal_pembelian' => now()->toDateString(),
            'items' => [
                ['id_item' => $items[0]['id_item'], 'harga_aktual' => 70000],
                ['id_item' => $items[1]['id_item'], 'harga_aktual' => 95000],
            ],
        ])->assertStatus(200);

        $detail = $this->getJson("/api/pembelian-sparepart/{$idPembelian}");
        $detail->assertStatus(200)
            ->assertJsonPath('data.pembayaran.nominal_ditransfer', 200000)
            ->assertJsonPath('data.pembayaran.total_aktual', 235000)
            ->assertJsonPath('data.pembayaran.selisih', 35000);
    }

    public function test_detail_pembelian_pembayaran_null_sebelum_ditransfer(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPembelian = $this->postJson('/api/pembelian-sparepart', $this->payloadPengajuan())->json('data.id_pembelian');

        $detail = $this->getJson("/api/pembelian-sparepart/{$idPembelian}");
        $detail->assertStatus(200)->assertJsonPath('data.pembayaran', null);
    }
}
