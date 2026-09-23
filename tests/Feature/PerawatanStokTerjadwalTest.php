<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PerawatanStokTerjadwalTest extends TestCase
{
    use RefreshDatabase;

    private function makeArmada(): ArmadaModel
    {
        return ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B ' . rand(1000, 9999) . ' ST',
            'merk'          => 'Hino',
            'status'        => 'tersedia',
        ]);
    }

    private function makeSparepart(int $stok): string
    {
        $id = (string) Str::uuid();
        DB::table('sparepart')->insert([
            'id_sparepart'  => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode'          => 'SP-' . Str::random(6),
            'nama'          => 'Oli Mesin',
            'satuan'        => 'pcs',
            'harga_standar' => 60000,
            'stok'          => $stok,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function stok(string $id): int
    {
        return (int) DB::table('sparepart')->where('id_sparepart', $id)->value('stok');
    }

    private function buatTerjadwal(ArmadaModel $armada, string $idSparepart, int $qty): string
    {
        $res = $this->postJson("/api/armada/{$armada->id_armada}/perawatan", [
            'tanggal'         => '2026-10-01',
            'jenis_perawatan' => 'Ganti Oli',
            'status'          => 'terjadwal',
            'sparepart'       => [
                ['sumber' => 'stok_sendiri', 'id_sparepart' => $idSparepart, 'qty' => $qty, 'harga' => 60000],
            ],
        ]);
        $res->assertStatus(201);
        return (string) $res->json('data.id_perawatan');
    }

    public function test_perawatan_terjadwal_mencatat_item_tanpa_memotong_stok(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $sp = $this->makeSparepart(10);

        $id = $this->buatTerjadwal($armada, $sp, 4);

        $this->assertSame(10, $this->stok($sp));
        $this->assertSame(1, DB::table('perawatan_sparepart')->where('id_perawatan', $id)->whereNull('dihapus_pada')->count());
        $this->assertSame(0, DB::table('sparepart_mutasi')->where('id_perawatan', $id)->count());
    }

    public function test_terjadwal_ke_dalam_proses_memotong_stok_sekali(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $sp = $this->makeSparepart(10);
        $id = $this->buatTerjadwal($armada, $sp, 4);

        $this->patchJson("/api/armada/{$armada->id_armada}/perawatan/{$id}", ['status' => 'dalam_proses'])->assertStatus(200);
        $this->assertSame(6, $this->stok($sp));
        $this->assertSame(1, DB::table('sparepart_mutasi')->where('id_perawatan', $id)->where('jenis', 'keluar')->count());

        $this->patchJson("/api/armada/{$armada->id_armada}/perawatan/{$id}", ['status' => 'selesai'])->assertStatus(200);
        $this->assertSame(6, $this->stok($sp));
        $this->assertSame(1, DB::table('sparepart_mutasi')->where('id_perawatan', $id)->where('jenis', 'keluar')->count());
    }

    public function test_terjadwal_ke_dalam_proses_ditolak_bila_stok_tidak_cukup(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $sp = $this->makeSparepart(2);
        $id = $this->buatTerjadwal($armada, $sp, 4);

        $res = $this->patchJson("/api/armada/{$armada->id_armada}/perawatan/{$id}", ['status' => 'dalam_proses']);

        $res->assertStatus(422);
        $this->assertSame(2, $this->stok($sp));
        $this->assertSame('terjadwal', DB::table('perawatan_armada')->where('id_perawatan', $id)->value('status'));
    }

    public function test_batal_saat_terjadwal_tidak_mengembalikan_stok_yang_belum_dipotong(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $sp = $this->makeSparepart(10);
        $id = $this->buatTerjadwal($armada, $sp, 4);

        $this->postJson("/api/armada/{$armada->id_armada}/perawatan/{$id}/batal", ['alasan' => 'batal'])->assertStatus(200);

        $this->assertSame(10, $this->stok($sp));
        $this->assertSame(0, DB::table('sparepart_mutasi')->where('id_perawatan', $id)->count());
    }

    public function test_ubah_item_saat_terjadwal_tidak_menyentuh_stok(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $sp = $this->makeSparepart(10);
        $id = $this->buatTerjadwal($armada, $sp, 4);

        $this->putJson("/api/armada/{$armada->id_armada}/perawatan/{$id}", [
            'sparepart' => [['sumber' => 'stok_sendiri', 'id_sparepart' => $sp, 'qty' => 7, 'harga' => 60000]],
        ])->assertStatus(200);

        $this->assertSame(10, $this->stok($sp));
        $this->assertSame(7, (int) DB::table('perawatan_sparepart')->where('id_perawatan', $id)->whereNull('dihapus_pada')->value('qty'));

        $this->patchJson("/api/armada/{$armada->id_armada}/perawatan/{$id}", ['status' => 'dalam_proses'])->assertStatus(200);
        $this->assertSame(3, $this->stok($sp));
    }

    public function test_dalam_proses_perilaku_lama_tetap_memotong_saat_dibuat_dan_mengembalikan_saat_batal(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $sp = $this->makeSparepart(10);

        $res = $this->postJson("/api/armada/{$armada->id_armada}/perawatan", [
            'tanggal'         => '2026-10-01',
            'jenis_perawatan' => 'Ganti Oli',
            'status'          => 'dalam_proses',
            'sparepart'       => [['sumber' => 'stok_sendiri', 'id_sparepart' => $sp, 'qty' => 4, 'harga' => 60000]],
        ]);
        $res->assertStatus(201);
        $id = $res->json('data.id_perawatan');
        $this->assertSame(6, $this->stok($sp));

        $this->postJson("/api/armada/{$armada->id_armada}/perawatan/{$id}/batal", ['alasan' => 'batal'])->assertStatus(200);
        $this->assertSame(10, $this->stok($sp));
    }
}
