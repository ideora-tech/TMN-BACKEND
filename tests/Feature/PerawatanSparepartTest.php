<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PerawatanSparepartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePerusahaan();
        app(\App\Modules\ArusKas\ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 999999999);
    }

    private function makeArmada(): ArmadaModel
    {
        return ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B ' . random_int(1000, 9999) . ' PS',
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

    private function makeJenis(string $nama = 'Servis Rutin'): object
    {
        $id = (string) Str::uuid();
        DB::table('jenis_perawatan')->insert([
            'id_jenis_perawatan' => $id,
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'nama'               => $nama,
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ]);
        return DB::table('jenis_perawatan')->where('id_jenis_perawatan', $id)->first();
    }

    public function test_create_servis_dengan_sparepart_mengurangi_stok_dan_mencatat_mutasi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $jenis  = $this->makeJenis('Servis 10.000 km');
        $spA    = $this->makeSparepart('Filter Oli', 10);
        $spB    = $this->makeSparepart('Busi', 20, 25000);

        $res = $this->postJson("/api/armada/{$armada->id_armada}/perawatan", [
            'tanggal'            => '2026-07-17',
            'id_jenis_perawatan' => $jenis->id_jenis_perawatan,
            'biaya'              => 500000,
            'sparepart'          => [
                ['id_sparepart' => $spA->id_sparepart, 'qty' => 2, 'harga' => 55000],
                ['id_sparepart' => $spB->id_sparepart, 'qty' => 4, 'harga' => 25000],
            ],
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.jenis_perawatan', 'Servis 10.000 km')
            ->assertJsonPath('data.id_jenis_perawatan', $jenis->id_jenis_perawatan)
            ->assertJsonCount(2, 'data.sparepart');

        // jangan bergantung urutan array (dibuat_pada bisa tie di detik yang sama)
        $items = collect($res->json('data.sparepart'))->keyBy('nama_sparepart');
        $this->assertSame(2, $items->get('Filter Oli')['qty']);
        $this->assertSame(4, $items->get('Busi')['qty']);

        $this->assertSame(8,  (int) DB::table('sparepart')->where('id_sparepart', $spA->id_sparepart)->value('stok'));
        $this->assertSame(16, (int) DB::table('sparepart')->where('id_sparepart', $spB->id_sparepart)->value('stok'));

        $idPerawatan = $res->json('data.id_perawatan');
        $this->assertSame(2, DB::table('sparepart_mutasi')->where('id_perawatan', $idPerawatan)->where('jenis', 'keluar')->count());
    }

    public function test_create_stok_tidak_cukup_ditolak_422(): void
    {
        // Stok masuk resmi lewat realisasi pembelian; pemakaian tidak boleh membuat stok minus.
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $sp     = $this->makeSparepart('Filter Oli', 1);

        $res = $this->postJson("/api/armada/{$armada->id_armada}/perawatan", [
            'tanggal'         => '2026-07-17',
            'jenis_perawatan' => 'Ganti Filter',
            'sparepart'       => [
                ['id_sparepart' => $sp->id_sparepart, 'qty' => 5, 'harga' => 55000],
            ],
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('tidak cukup', (string) $res->json('message'));
        $this->assertSame(1, (int) DB::table('sparepart')->where('id_sparepart', $sp->id_sparepart)->value('stok'));
        $this->assertSame(0, DB::table('perawatan_armada')->where('id_armada', $armada->id_armada)->count());
        $this->assertSame(0, DB::table('sparepart_mutasi')->where('jenis', 'keluar')->count());
    }

    public function test_update_menaikkan_qty_melebihi_stok_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $sp = $this->makeSparepart('Filter Oli', 5);

        $create = $this->postJson("/api/armada/{$armada->id_armada}/perawatan", [
            'tanggal' => '2026-07-17', 'jenis_perawatan' => 'Servis', 'status' => 'dalam_proses',
            'sparepart' => [['id_sparepart' => $sp->id_sparepart, 'qty' => 3, 'harga' => 55000]],
        ]);
        $idPerawatan = $create->json('data.id_perawatan');
        // stok kini 2; menaikkan qty 3 → 6 butuh tambahan 3 > tersedia 2

        $res = $this->putJson("/api/armada/{$armada->id_armada}/perawatan/{$idPerawatan}", [
            'sparepart' => [['id_sparepart' => $sp->id_sparepart, 'qty' => 6, 'harga' => 55000]],
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('tidak cukup', (string) $res->json('message'));
        $this->assertSame(2, (int) DB::table('sparepart')->where('id_sparepart', $sp->id_sparepart)->value('stok'));

        // menurunkan qty (mengembalikan stok) tetap boleh
        $resTurun = $this->putJson("/api/armada/{$armada->id_armada}/perawatan/{$idPerawatan}", [
            'sparepart' => [['id_sparepart' => $sp->id_sparepart, 'qty' => 1, 'harga' => 55000]],
        ]);
        $resTurun->assertStatus(200);
        $this->assertSame(4, (int) DB::table('sparepart')->where('id_sparepart', $sp->id_sparepart)->value('stok'));
    }

    public function test_update_item_menghitung_delta_stok_dua_arah(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $spA = $this->makeSparepart('Filter Oli', 10);
        $spB = $this->makeSparepart('Busi', 20, 25000);

        $create = $this->postJson("/api/armada/{$armada->id_armada}/perawatan", [
            'tanggal' => '2026-07-17', 'jenis_perawatan' => 'Servis', 'status' => 'dalam_proses',
            'sparepart' => [
                ['id_sparepart' => $spA->id_sparepart, 'qty' => 2, 'harga' => 55000],
                ['id_sparepart' => $spB->id_sparepart, 'qty' => 4, 'harga' => 25000],
            ],
        ]);
        $idPerawatan = $create->json('data.id_perawatan');
        // stok kini: A=8, B=16

        $res = $this->putJson("/api/armada/{$armada->id_armada}/perawatan/{$idPerawatan}", [
            'sparepart' => [
                ['id_sparepart' => $spA->id_sparepart, 'qty' => 5, 'harga' => 55000], // +3 → keluar 3
                // B dihapus dari daftar → delta -4 → masuk 4
            ],
        ]);

        $res->assertStatus(200)->assertJsonCount(1, 'data.sparepart');
        $this->assertSame(5,  (int) DB::table('sparepart')->where('id_sparepart', $spA->id_sparepart)->value('stok'));
        $this->assertSame(20, (int) DB::table('sparepart')->where('id_sparepart', $spB->id_sparepart)->value('stok'));

        $this->assertDatabaseHas('sparepart_mutasi', ['id_sparepart' => $spA->id_sparepart, 'jenis' => 'keluar', 'qty' => 3, 'keterangan' => 'Perubahan item servis']);
        $this->assertDatabaseHas('sparepart_mutasi', ['id_sparepart' => $spB->id_sparepart, 'jenis' => 'masuk', 'qty' => 4, 'keterangan' => 'Perubahan item servis']);
        // baris lama di-soft-delete, baris aktif tinggal 1
        $this->assertSame(1, DB::table('perawatan_sparepart')->where('id_perawatan', $idPerawatan)->whereNull('dihapus_pada')->count());
    }

    public function test_delete_servis_mengembalikan_stok(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $sp = $this->makeSparepart('Filter Oli', 10);

        $create = $this->postJson("/api/armada/{$armada->id_armada}/perawatan", [
            'tanggal' => '2026-07-17', 'jenis_perawatan' => 'Servis', 'status' => 'dalam_proses',
            'sparepart' => [['id_sparepart' => $sp->id_sparepart, 'qty' => 3, 'harga' => 50000]],
        ]);
        $idPerawatan = $create->json('data.id_perawatan');
        $this->assertSame(7, (int) DB::table('sparepart')->where('id_sparepart', $sp->id_sparepart)->value('stok'));

        $this->deleteJson("/api/armada/{$armada->id_armada}/perawatan/{$idPerawatan}", ['alasan' => 'Pembersihan data uji'])->assertStatus(200);

        $this->assertSame(10, (int) DB::table('sparepart')->where('id_sparepart', $sp->id_sparepart)->value('stok'));
        $this->assertDatabaseHas('sparepart_mutasi', ['id_perawatan' => $idPerawatan, 'jenis' => 'masuk', 'qty' => 3, 'keterangan' => 'Pembatalan servis']);
        $this->assertSoftDeleted('perawatan_armada', ['id_perawatan' => $idPerawatan]);
    }

    public function test_batal_servis_mengembalikan_stok_tanpa_menghapus_record(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $sp = $this->makeSparepart('Filter Udara', 10);

        $create = $this->postJson("/api/armada/{$armada->id_armada}/perawatan", [
            'tanggal' => '2026-07-17', 'jenis_perawatan' => 'Servis', 'status' => 'terjadwal',
            'sparepart' => [['id_sparepart' => $sp->id_sparepart, 'qty' => 4, 'harga' => 60000]],
        ]);
        $idPerawatan = $create->json('data.id_perawatan');
        $this->assertSame(6, (int) DB::table('sparepart')->where('id_sparepart', $sp->id_sparepart)->value('stok'));

        $this->postJson("/api/armada/{$armada->id_armada}/perawatan/{$idPerawatan}/batal", [
            'alasan' => 'Armada dipakai operasional',
        ])->assertStatus(200);

        $this->assertSame(10, (int) DB::table('sparepart')->where('id_sparepart', $sp->id_sparepart)->value('stok'));
        $this->assertDatabaseHas('sparepart_mutasi', ['id_perawatan' => $idPerawatan, 'jenis' => 'masuk', 'qty' => 4, 'keterangan' => 'Pembatalan servis']);
        $this->assertDatabaseHas('perawatan_armada', ['id_perawatan' => $idPerawatan, 'status' => 'dibatalkan', 'dihapus_pada' => null]);
        // item servis tetap aktif agar riwayat pembatalan masih bisa dilihat
        $this->assertSame(1, DB::table('perawatan_sparepart')->where('id_perawatan', $idPerawatan)->whereNull('dihapus_pada')->count());

        // hapus setelah batal tidak mengembalikan stok dua kali
        $this->deleteJson("/api/armada/{$armada->id_armada}/perawatan/{$idPerawatan}", ['alasan' => 'Pembersihan data uji'])->assertStatus(200);
        $this->assertSame(10, (int) DB::table('sparepart')->where('id_sparepart', $sp->id_sparepart)->value('stok'));
    }

    public function test_update_id_jenis_perawatan_menyinkronkan_snapshot_teks(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $jenisBaru = $this->makeJenis('Overhaul Mesin');

        $create = $this->postJson("/api/armada/{$armada->id_armada}/perawatan", [
            'tanggal' => '2026-07-17', 'jenis_perawatan' => 'Teks Manual Lama', 'status' => 'dalam_proses',
        ]);
        $idPerawatan = $create->json('data.id_perawatan');

        $res = $this->putJson("/api/armada/{$armada->id_armada}/perawatan/{$idPerawatan}", [
            'id_jenis_perawatan' => $jenisBaru->id_jenis_perawatan,
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.id_jenis_perawatan', $jenisBaru->id_jenis_perawatan)
            ->assertJsonPath('data.jenis_perawatan', 'Overhaul Mesin');
    }

    public function test_create_tanpa_sparepart_dan_teks_manual_tetap_jalan_regresi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();

        $res = $this->postJson("/api/armada/{$armada->id_armada}/perawatan", [
            'tanggal'         => '2026-07-17',
            'jenis_perawatan' => 'Cuci Kendaraan',
            'biaya'           => 100000,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.jenis_perawatan', 'Cuci Kendaraan')
            ->assertJsonPath('data.id_jenis_perawatan', null);
        $this->assertSame([], $res->json('data.sparepart'));
    }

    public function test_tanpa_jenis_sama_sekali_ditolak_validasi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();

        $res = $this->postJson("/api/armada/{$armada->id_armada}/perawatan", [
            'tanggal' => '2026-07-17',
        ]);

        $res->assertStatus(422);
    }

    public function test_hapus_master_sparepart_yang_dipakai_servis_aktif_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $sp = $this->makeSparepart('Filter Oli', 10);

        $create = $this->postJson("/api/armada/{$armada->id_armada}/perawatan", [
            'tanggal' => '2026-07-17', 'jenis_perawatan' => 'Servis', 'status' => 'dalam_proses',
            'sparepart' => [['id_sparepart' => $sp->id_sparepart, 'qty' => 2, 'harga' => 50000]],
        ]);
        $idPerawatan = $create->json('data.id_perawatan');

        $resTolak = $this->deleteJson("/api/sparepart/{$sp->id_sparepart}");
        $resTolak->assertStatus(422);
        $this->assertStringContainsString('masih dipakai', (string) $resTolak->json('message'));
        $this->assertNull(DB::table('sparepart')->where('id_sparepart', $sp->id_sparepart)->value('dihapus_pada'));

        // setelah servisnya dihapus (lines ikut soft-delete), master boleh dihapus
        $this->deleteJson("/api/armada/{$armada->id_armada}/perawatan/{$idPerawatan}", ['alasan' => 'Pembersihan data uji'])->assertStatus(200);
        $this->deleteJson("/api/sparepart/{$sp->id_sparepart}")->assertStatus(200);
    }

    public function test_hapus_master_jenis_perawatan_yang_dipakai_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $jenis = $this->makeJenis('Servis Berkala');

        $create = $this->postJson("/api/armada/{$armada->id_armada}/perawatan", [
            'tanggal' => '2026-07-17', 'id_jenis_perawatan' => $jenis->id_jenis_perawatan, 'status' => 'dalam_proses',
        ]);
        $idPerawatan = $create->json('data.id_perawatan');

        $resTolak = $this->deleteJson("/api/jenis-perawatan/{$jenis->id_jenis_perawatan}");
        $resTolak->assertStatus(422);
        $this->assertStringContainsString('masih dipakai', (string) $resTolak->json('message'));

        $this->deleteJson("/api/armada/{$armada->id_armada}/perawatan/{$idPerawatan}", ['alasan' => 'Pembersihan data uji'])->assertStatus(200);
        $this->deleteJson("/api/jenis-perawatan/{$jenis->id_jenis_perawatan}")->assertStatus(200);
    }

    public function test_payload_dengan_master_soft_deleted_ditolak_validasi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $sp = $this->makeSparepart('Part Mati', 10);
        $jenis = $this->makeJenis('Jenis Mati');

        DB::table('sparepart')->where('id_sparepart', $sp->id_sparepart)->update(['dihapus_pada' => now()]);
        DB::table('jenis_perawatan')->where('id_jenis_perawatan', $jenis->id_jenis_perawatan)->update(['dihapus_pada' => now()]);

        $resPart = $this->postJson("/api/armada/{$armada->id_armada}/perawatan", [
            'tanggal' => '2026-07-17', 'jenis_perawatan' => 'Servis',
            'sparepart' => [['id_sparepart' => $sp->id_sparepart, 'qty' => 1, 'harga' => 1000]],
        ]);
        $resPart->assertStatus(422);

        $resJenis = $this->postJson("/api/armada/{$armada->id_armada}/perawatan", [
            'tanggal' => '2026-07-17', 'id_jenis_perawatan' => $jenis->id_jenis_perawatan,
        ]);
        $resJenis->assertStatus(422);
    }
}
