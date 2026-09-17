<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\ArusKas\ArusKasService;
use App\Modules\Armada\ArmadaModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PerawatanPartBengkelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePerusahaan();
        app(ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 999999999);
    }

    private function makeArmada(): ArmadaModel
    {
        return ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B ' . random_int(1000, 9999) . ' BK',
            'merk'          => 'Hino',
        ]);
    }

    private function makeSparepart(string $nama = 'Filter Oli', int $stok = 10, float $harga = 50000): object
    {
        $id = (string) Str::uuid();
        DB::table('sparepart')->insert([
            'id_sparepart'  => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode'          => 'SP-' . Str::random(6),
            'nama'          => $nama,
            'satuan'        => 'pcs',
            'harga_standar' => $harga,
            'stok'          => $stok,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return DB::table('sparepart')->where('id_sparepart', $id)->first();
    }

    private function makeSupplier(string $idPerusahaan, string $nama = 'Bengkel Jaya Motor'): object
    {
        $id = (string) Str::uuid();
        DB::table('supplier')->insert([
            'id_supplier'   => $id,
            'id_perusahaan' => $idPerusahaan,
            'nama'          => $nama,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return DB::table('supplier')->where('id_supplier', $id)->first();
    }

    public function test_update_dengan_sparepart_kosong_menghapus_semua_item_dan_mengembalikan_stok(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $sp = $this->makeSparepart('Kampas Rem', 10);

        $create = $this->postJson("/api/armada/{$armada->id_armada}/perawatan", [
            'tanggal' => '2026-09-10', 'jenis_perawatan' => 'Servis', 'status' => 'dalam_proses',
            'sparepart' => [
                ['sumber' => 'bengkel', 'nama_sparepart' => 'Ongkos Pasang', 'qty' => 1, 'harga' => 50000],
                ['sumber' => 'stok_sendiri', 'id_sparepart' => $sp->id_sparepart, 'qty' => 2, 'harga' => 40000],
            ],
        ]);
        $idPerawatan = $create->json('data.id_perawatan');
        $this->assertSame(8, (int) DB::table('sparepart')->where('id_sparepart', $sp->id_sparepart)->value('stok'));

        $update = $this->putJson("/api/armada/{$armada->id_armada}/perawatan/{$idPerawatan}", [
            'tanggal'   => '2026-09-10',
            'biaya'     => 100000,
            'sparepart' => [],
        ]);

        $update->assertStatus(200)->assertJsonCount(0, 'data.sparepart');
        $this->assertSame(10, (int) DB::table('sparepart')->where('id_sparepart', $sp->id_sparepart)->value('stok'));
        $this->assertSame(0, DB::table('perawatan_sparepart')
            ->where('id_perawatan', $idPerawatan)->whereNull('dihapus_pada')->count());
    }

    public function test_part_bengkel_nama_bebas_tanpa_id_sparepart_tersimpan_tanpa_sentuh_stok(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();

        $res = $this->postJson("/api/armada/{$armada->id_armada}/perawatan", [
            'tanggal'         => '2026-09-10',
            'jenis_perawatan' => 'Servis Bengkel Luar',
            'sparepart'       => [
                ['sumber' => 'bengkel', 'nama_sparepart' => 'Baut Roda Set', 'qty' => 4, 'harga' => 15000],
            ],
        ]);

        $res->assertStatus(201)->assertJsonCount(1, 'data.sparepart');
        $item = $res->json('data.sparepart.0');
        $this->assertSame('bengkel', $item['sumber']);
        $this->assertNull($item['id_sparepart']);
        $this->assertSame('Baut Roda Set', $item['nama_sparepart']);

        $idPerawatan = $res->json('data.id_perawatan');
        $this->assertDatabaseHas('perawatan_sparepart', [
            'id_perawatan'   => $idPerawatan,
            'id_sparepart'   => null,
            'nama_sparepart' => 'Baut Roda Set',
            'sumber'         => 'bengkel',
        ]);
        $this->assertSame(0, DB::table('sparepart_mutasi')->where('id_perawatan', $idPerawatan)->count());
    }

    public function test_part_stok_sendiri_perilaku_lama_utuh_potong_stok_dan_tolak_bila_kurang(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $sp = $this->makeSparepart('Oli Mesin', 3);

        $resTolak = $this->postJson("/api/armada/{$armada->id_armada}/perawatan", [
            'tanggal'   => '2026-09-10',
            'jenis_perawatan' => 'Ganti Oli',
            'sparepart' => [
                ['sumber' => 'stok_sendiri', 'id_sparepart' => $sp->id_sparepart, 'qty' => 5, 'harga' => 60000],
            ],
        ]);
        $resTolak->assertStatus(422);
        $this->assertStringContainsString('tidak cukup', (string) $resTolak->json('message'));
        $this->assertSame(3, (int) DB::table('sparepart')->where('id_sparepart', $sp->id_sparepart)->value('stok'));

        $res = $this->postJson("/api/armada/{$armada->id_armada}/perawatan", [
            'tanggal'   => '2026-09-10',
            'jenis_perawatan' => 'Ganti Oli',
            'sparepart' => [
                ['sumber' => 'stok_sendiri', 'id_sparepart' => $sp->id_sparepart, 'qty' => 2, 'harga' => 60000],
            ],
        ]);
        $res->assertStatus(201);
        $this->assertSame(1, (int) DB::table('sparepart')->where('id_sparepart', $sp->id_sparepart)->value('stok'));
        $this->assertSame(1, DB::table('sparepart_mutasi')->where('id_perawatan', $res->json('data.id_perawatan'))->where('jenis', 'keluar')->count());
    }

    public function test_campuran_bengkel_dan_stok_sendiri_hanya_stok_sendiri_memotong_stok(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $sp = $this->makeSparepart('Filter Udara', 10);

        $res = $this->postJson("/api/armada/{$armada->id_armada}/perawatan", [
            'tanggal'   => '2026-09-10',
            'jenis_perawatan' => 'Servis Besar',
            'sparepart' => [
                ['sumber' => 'bengkel', 'nama_sparepart' => 'Ongkos Bubut', 'qty' => 1, 'harga' => 75000],
                ['sumber' => 'stok_sendiri', 'id_sparepart' => $sp->id_sparepart, 'qty' => 3, 'harga' => 50000],
            ],
        ]);

        $res->assertStatus(201)->assertJsonCount(2, 'data.sparepart');
        $this->assertSame(7, (int) DB::table('sparepart')->where('id_sparepart', $sp->id_sparepart)->value('stok'));

        $idPerawatan = $res->json('data.id_perawatan');
        $this->assertSame(1, DB::table('sparepart_mutasi')->where('id_perawatan', $idPerawatan)->count());
        $this->assertSame(1, DB::table('perawatan_sparepart')->where('id_perawatan', $idPerawatan)->where('sumber', 'bengkel')->count());
        $this->assertSame(1, DB::table('perawatan_sparepart')->where('id_perawatan', $idPerawatan)->where('sumber', 'stok_sendiri')->count());
    }

    public function test_update_ganti_part_bengkel_stok_tak_tersentuh_update_stok_sendiri_delta_benar(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $sp = $this->makeSparepart('Kampas Rem', 10);

        $create = $this->postJson("/api/armada/{$armada->id_armada}/perawatan", [
            'tanggal' => '2026-09-10', 'jenis_perawatan' => 'Servis', 'status' => 'dalam_proses',
            'sparepart' => [
                ['sumber' => 'bengkel', 'nama_sparepart' => 'Ongkos Pasang', 'qty' => 1, 'harga' => 50000],
                ['sumber' => 'stok_sendiri', 'id_sparepart' => $sp->id_sparepart, 'qty' => 2, 'harga' => 40000],
            ],
        ]);
        $idPerawatan = $create->json('data.id_perawatan');
        $this->assertSame(8, (int) DB::table('sparepart')->where('id_sparepart', $sp->id_sparepart)->value('stok'));

        $update = $this->putJson("/api/armada/{$armada->id_armada}/perawatan/{$idPerawatan}", [
            'sparepart' => [
                ['sumber' => 'bengkel', 'nama_sparepart' => 'Ongkos Pasang Ulang', 'qty' => 1, 'harga' => 90000],
                ['sumber' => 'stok_sendiri', 'id_sparepart' => $sp->id_sparepart, 'qty' => 5, 'harga' => 40000],
            ],
        ]);

        $update->assertStatus(200)->assertJsonCount(2, 'data.sparepart');
        $this->assertSame(5, (int) DB::table('sparepart')->where('id_sparepart', $sp->id_sparepart)->value('stok'));
        $this->assertDatabaseHas('sparepart_mutasi', [
            'id_sparepart' => $sp->id_sparepart, 'jenis' => 'keluar', 'qty' => 3, 'keterangan' => 'Perubahan item servis',
        ]);
        // 1 mutasi dari create (pemakaian awal) + 1 dari update (delta) — keduanya milik id_sparepart yang sama
        $this->assertSame(2, DB::table('sparepart_mutasi')->where('id_perawatan', $idPerawatan)->count());
        $this->assertDatabaseHas('perawatan_sparepart', [
            'id_perawatan' => $idPerawatan, 'sumber' => 'bengkel', 'nama_sparepart' => 'Ongkos Pasang Ulang', 'dihapus_pada' => null,
        ]);
    }

    public function test_batal_dan_hapus_campuran_hanya_mengembalikan_stok_sendiri(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $sp = $this->makeSparepart('Busi', 10);

        $create = $this->postJson("/api/armada/{$armada->id_armada}/perawatan", [
            'tanggal' => '2026-09-10', 'jenis_perawatan' => 'Servis', 'status' => 'terjadwal',
            'sparepart' => [
                ['sumber' => 'bengkel', 'nama_sparepart' => 'Jasa Servis', 'qty' => 1, 'harga' => 100000],
                ['sumber' => 'stok_sendiri', 'id_sparepart' => $sp->id_sparepart, 'qty' => 4, 'harga' => 20000],
            ],
        ]);
        $idPerawatan = $create->json('data.id_perawatan');
        $this->assertSame(6, (int) DB::table('sparepart')->where('id_sparepart', $sp->id_sparepart)->value('stok'));

        $this->postJson("/api/armada/{$armada->id_armada}/perawatan/{$idPerawatan}/batal", [
            'alasan' => 'Armada dipakai operasional',
        ])->assertStatus(200);

        $this->assertSame(10, (int) DB::table('sparepart')->where('id_sparepart', $sp->id_sparepart)->value('stok'));
        $this->assertSame(1, DB::table('sparepart_mutasi')->where('id_perawatan', $idPerawatan)->where('jenis', 'masuk')->count());

        $this->deleteJson("/api/armada/{$armada->id_armada}/perawatan/{$idPerawatan}", ['alasan' => 'Pembersihan data uji'])->assertStatus(200);
        $this->assertSame(10, (int) DB::table('sparepart')->where('id_sparepart', $sp->id_sparepart)->value('stok'));
    }

    public function test_id_supplier_tersimpan_dan_nama_supplier_tampil_di_resource(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $supplier = $this->makeSupplier(self::PERUSAHAAN_ID, 'Bengkel Maju Jaya');

        $res = $this->postJson("/api/armada/{$armada->id_armada}/perawatan", [
            'tanggal'         => '2026-09-10',
            'jenis_perawatan' => 'Servis Bengkel',
            'id_supplier'     => $supplier->id_supplier,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.id_supplier', $supplier->id_supplier)
            ->assertJsonPath('data.nama_supplier', 'Bengkel Maju Jaya');

        $idPerawatan = $res->json('data.id_perawatan');

        $show = $this->getJson("/api/armada/{$armada->id_armada}/perawatan/{$idPerawatan}");
        $show->assertStatus(200)->assertJsonPath('data.nama_supplier', 'Bengkel Maju Jaya');

        $list = $this->getJson("/api/armada/{$armada->id_armada}/perawatan");
        $list->assertStatus(200);
        $row = collect($list->json('data'))->firstWhere('id_perawatan', $idPerawatan);
        $this->assertSame('Bengkel Maju Jaya', $row['nama_supplier']);
    }

    public function test_supplier_milik_tenant_lain_ditolak_404_saat_create_dan_update(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert([
            'id_perusahaan' => $idPerusahaanLain,
            'nama'          => 'Perusahaan Lain',
            'dibuat_pada'   => now(),
        ]);
        $supplierLain = $this->makeSupplier($idPerusahaanLain, 'Bengkel Tenant Lain');

        $resCreate = $this->postJson("/api/armada/{$armada->id_armada}/perawatan", [
            'tanggal'         => '2026-09-10',
            'jenis_perawatan' => 'Servis',
            'id_supplier'     => $supplierLain->id_supplier,
        ]);
        $resCreate->assertStatus(404);
        $this->assertStringContainsString('Supplier tidak ditemukan', (string) $resCreate->json('message'));

        $create = $this->postJson("/api/armada/{$armada->id_armada}/perawatan", [
            'tanggal' => '2026-09-10', 'jenis_perawatan' => 'Servis', 'status' => 'terjadwal',
        ]);
        $idPerawatan = $create->json('data.id_perawatan');

        $resUpdate = $this->putJson("/api/armada/{$armada->id_armada}/perawatan/{$idPerawatan}", [
            'id_supplier' => $supplierLain->id_supplier,
        ]);
        $resUpdate->assertStatus(404);
        $this->assertStringContainsString('Supplier tidak ditemukan', (string) $resUpdate->json('message'));
    }

    public function test_payload_lama_tanpa_sumber_default_bengkel_tidak_potong_stok(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $sp = $this->makeSparepart('Filter Solar', 10);

        $res = $this->postJson("/api/armada/{$armada->id_armada}/perawatan", [
            'tanggal'         => '2026-09-10',
            'jenis_perawatan' => 'Servis Lama Tanpa Sumber',
            'sparepart'       => [
                ['id_sparepart' => $sp->id_sparepart, 'qty' => 2, 'harga' => 55000],
            ],
        ]);

        $res->assertStatus(201);
        $item = $res->json('data.sparepart.0');
        $this->assertSame('bengkel', $item['sumber']);
        $this->assertSame(10, (int) DB::table('sparepart')->where('id_sparepart', $sp->id_sparepart)->value('stok'));
        $this->assertSame(0, DB::table('sparepart_mutasi')->where('id_perawatan', $res->json('data.id_perawatan'))->count());
    }

    public function test_bengkel_id_sparepart_terisi_nama_kosong_otomatis_dari_master(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $sp = $this->makeSparepart('Filter Udara Original', 10);

        $res = $this->postJson("/api/armada/{$armada->id_armada}/perawatan", [
            'tanggal'         => '2026-09-10',
            'jenis_perawatan' => 'Servis',
            'sparepart'       => [
                ['sumber' => 'bengkel', 'id_sparepart' => $sp->id_sparepart, 'qty' => 1, 'harga' => 65000],
            ],
        ]);

        $res->assertStatus(201);
        $item = $res->json('data.sparepart.0');
        $this->assertSame('Filter Udara Original', $item['nama_sparepart']);
        $this->assertSame('bengkel', $item['sumber']);
        $this->assertSame(10, (int) DB::table('sparepart')->where('id_sparepart', $sp->id_sparepart)->value('stok'));
    }

    public function test_validasi_stok_sendiri_wajib_id_sparepart(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();

        $res = $this->postJson("/api/armada/{$armada->id_armada}/perawatan", [
            'tanggal'         => '2026-09-10',
            'jenis_perawatan' => 'Servis',
            'sparepart'       => [
                ['sumber' => 'stok_sendiri', 'qty' => 1, 'harga' => 1000],
            ],
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['sparepart.0.id_sparepart']);
    }

    public function test_validasi_bengkel_wajib_nama_jika_id_sparepart_kosong(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();

        $res = $this->postJson("/api/armada/{$armada->id_armada}/perawatan", [
            'tanggal'         => '2026-09-10',
            'jenis_perawatan' => 'Servis',
            'sparepart'       => [
                ['sumber' => 'bengkel', 'qty' => 1, 'harga' => 1000],
            ],
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['sparepart.0.nama_sparepart']);
    }

    public function test_total_biaya_arus_kas_mencakup_subtotal_bengkel_dan_stok_sendiri(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $sp = $this->makeSparepart('Oli Gardan', 10);

        $res = $this->postJson("/api/armada/{$armada->id_armada}/perawatan", [
            'tanggal'         => '2026-09-10',
            'jenis_perawatan' => 'Servis Besar',
            'biaya'           => 100000,
            'status'          => 'selesai',
            'sparepart'       => [
                ['sumber' => 'bengkel', 'nama_sparepart' => 'Jasa Tambahan', 'qty' => 1, 'harga' => 50000],
                ['sumber' => 'stok_sendiri', 'id_sparepart' => $sp->id_sparepart, 'qty' => 2, 'harga' => 30000],
            ],
        ]);
        $res->assertStatus(201);

        $idPerawatan = $res->json('data.id_perawatan');
        $pengajuan = DB::table('pengajuan_pengeluaran')->where('id_perawatan', $idPerawatan)->first();
        $this->assertNotNull($pengajuan);
        // 100000 (jasa) + 50000 (bengkel) + 2*30000 (stok_sendiri) = 210000
        $this->assertEquals(210000, (float) $pengajuan->nominal);
    }
}
