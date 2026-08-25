<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\ArusKas\ArusKasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ArusKasPerawatanTest extends TestCase
{
    use RefreshDatabase;

    private function buatArmada(string $nopol = 'B 1234 AK'): string
    {
        $id = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada'     => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => $nopol,
            'merk'          => 'Hino',
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function buatSparepart(int $stok = 10): string
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

    private function pengajuanPerawatan(string $idPerawatan): ?object
    {
        return DB::table('pengajuan_pengeluaran')->where('id_perawatan', $idPerawatan)->first();
    }

    public function test_perawatan_selesai_berbiaya_membuat_pengajuan_otomatis(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->buatArmada('B 9001 AK');
        $sp = $this->buatSparepart();

        $res = $this->postJson("/api/armada/{$armada}/perawatan", [
            'tanggal'         => '2026-08-10',
            'jenis_perawatan' => 'Ganti Oli',
            'biaya'           => 250000,
            'status'          => 'selesai',
            'sparepart'       => [
                ['id_sparepart' => $sp, 'qty' => 2, 'harga' => 60000],
            ],
        ]);
        $res->assertStatus(201);
        $idPerawatan = $res->json('data.id_perawatan');

        $pengajuan = $this->pengajuanPerawatan($idPerawatan);
        $this->assertNotNull($pengajuan);
        $this->assertSame('perawatan', $pengajuan->kategori);
        $this->assertEquals(370000, (float) $pengajuan->nominal);
        $this->assertSame('diajukan', $pengajuan->status);
        $this->assertSame('B 9001 AK', $pengajuan->penerima);
        $this->assertSame('Ganti Oli - B 9001 AK', $pengajuan->keterangan);
        $this->assertNotNull($pengajuan->nomor_pengajuan);

        $resPengajuan = $this->getJson("/api/arus-kas/pengajuan/{$pengajuan->id_pengajuan}");
        $resPengajuan->assertStatus(200)->assertJsonPath('data.id_perawatan', $idPerawatan);
    }

    public function test_perawatan_selesai_biaya_nol_tidak_membuat_pengajuan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->buatArmada();

        $res = $this->postJson("/api/armada/{$armada}/perawatan", [
            'tanggal'         => '2026-08-10',
            'jenis_perawatan' => 'Cek Rutin Gratis',
            'biaya'           => 0,
            'status'          => 'selesai',
        ]);
        $res->assertStatus(201);
        $idPerawatan = $res->json('data.id_perawatan');

        $this->assertNull($this->pengajuanPerawatan($idPerawatan));
    }

    public function test_perawatan_status_terjadwal_tidak_membuat_pengajuan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->buatArmada();

        $res = $this->postJson("/api/armada/{$armada}/perawatan", [
            'tanggal'         => '2026-08-10',
            'jenis_perawatan' => 'Servis Besar',
            'biaya'           => 500000,
            'status'          => 'terjadwal',
        ]);
        $res->assertStatus(201);
        $idPerawatan = $res->json('data.id_perawatan');

        $this->assertNull($this->pengajuanPerawatan($idPerawatan));
    }

    public function test_perawatan_dibuat_dalam_proses_berbiaya_membuat_pengajuan_otomatis(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->buatArmada('B 9002 AK');

        $res = $this->postJson("/api/armada/{$armada}/perawatan", [
            'tanggal'         => '2026-08-10',
            'jenis_perawatan' => 'Servis Besar',
            'biaya'           => 500000,
            'status'          => 'dalam_proses',
        ]);
        $res->assertStatus(201);
        $idPerawatan = $res->json('data.id_perawatan');

        $pengajuan = $this->pengajuanPerawatan($idPerawatan);
        $this->assertNotNull($pengajuan);
        $this->assertEquals(500000, (float) $pengajuan->nominal);
        $this->assertSame('diajukan', $pengajuan->status);
    }

    public function test_perawatan_terjadwal_ke_dalam_proses_membuat_pengajuan_otomatis(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->buatArmada();

        $create = $this->postJson("/api/armada/{$armada}/perawatan", [
            'tanggal'         => '2026-08-10',
            'jenis_perawatan' => 'Servis Besar',
            'biaya'           => 400000,
            'status'          => 'terjadwal',
        ]);
        $create->assertStatus(201);
        $idPerawatan = $create->json('data.id_perawatan');
        $this->assertNull($this->pengajuanPerawatan($idPerawatan));

        $update = $this->putJson("/api/armada/{$armada}/perawatan/{$idPerawatan}", [
            'status' => 'dalam_proses',
        ]);
        $update->assertStatus(200);

        $pengajuan = $this->pengajuanPerawatan($idPerawatan);
        $this->assertNotNull($pengajuan);
        $this->assertEquals(400000, (float) $pengajuan->nominal);
    }

    public function test_dalam_proses_ke_selesai_dedup_tetap_satu_pengajuan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->buatArmada();

        $create = $this->postJson("/api/armada/{$armada}/perawatan", [
            'tanggal'         => '2026-08-10',
            'jenis_perawatan' => 'Servis Besar',
            'biaya'           => 300000,
            'status'          => 'dalam_proses',
        ]);
        $create->assertStatus(201);
        $idPerawatan = $create->json('data.id_perawatan');
        $this->assertSame(1, DB::table('pengajuan_pengeluaran')->where('id_perawatan', $idPerawatan)->count());

        $update = $this->putJson("/api/armada/{$armada}/perawatan/{$idPerawatan}", [
            'status' => 'selesai',
        ]);
        $update->assertStatus(200);

        $this->assertSame(1, DB::table('pengajuan_pengeluaran')->where('id_perawatan', $idPerawatan)->count());
    }

    public function test_perawatan_biaya_nol_dalam_proses_lalu_diisi_membuat_pengajuan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->buatArmada();

        $create = $this->postJson("/api/armada/{$armada}/perawatan", [
            'tanggal'         => '2026-08-10',
            'jenis_perawatan' => 'Servis Besar',
            'biaya'           => 0,
            'status'          => 'dalam_proses',
        ]);
        $create->assertStatus(201);
        $idPerawatan = $create->json('data.id_perawatan');
        $this->assertNull($this->pengajuanPerawatan($idPerawatan));

        $update = $this->putJson("/api/armada/{$armada}/perawatan/{$idPerawatan}", [
            'biaya' => 350000,
        ]);
        $update->assertStatus(200);

        $pengajuan = $this->pengajuanPerawatan($idPerawatan);
        $this->assertNotNull($pengajuan);
        $this->assertEquals(350000, (float) $pengajuan->nominal);
    }

    public function test_delete_perawatan_dalam_proses_menghapus_pengajuan_yang_belum_ditransfer(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->buatArmada();

        $create = $this->postJson("/api/armada/{$armada}/perawatan", [
            'tanggal'         => '2026-08-10',
            'jenis_perawatan' => 'Servis Besar',
            'biaya'           => 300000,
            'status'          => 'dalam_proses',
        ]);
        $create->assertStatus(201);
        $idPerawatan = $create->json('data.id_perawatan');
        $this->assertNotNull($this->pengajuanPerawatan($idPerawatan));

        $delete = $this->deleteJson("/api/armada/{$armada}/perawatan/{$idPerawatan}", [
            'alasan' => 'Pembersihan data uji',
        ]);
        $delete->assertStatus(200);

        $this->assertNull(DB::table('pengajuan_pengeluaran')->where('id_perawatan', $idPerawatan)->whereNull('dihapus_pada')->first());
        $pengajuan = $this->pengajuanPerawatan($idPerawatan);
        $this->assertNotNull($pengajuan->dihapus_pada);
    }

    public function test_delete_perawatan_dalam_proses_pengajuan_sudah_ditransfer_tetap_dipertahankan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->buatArmada();

        $create = $this->postJson("/api/armada/{$armada}/perawatan", [
            'tanggal'         => '2026-08-10',
            'jenis_perawatan' => 'Servis Besar',
            'biaya'           => 300000,
            'status'          => 'dalam_proses',
        ]);
        $create->assertStatus(201);
        $idPerawatan = $create->json('data.id_perawatan');

        DB::table('pengajuan_pengeluaran')->where('id_perawatan', $idPerawatan)->update([
            'status'           => 'ditransfer',
            'tanggal_transfer' => '2026-08-12',
        ]);

        $delete = $this->deleteJson("/api/armada/{$armada}/perawatan/{$idPerawatan}", [
            'alasan' => 'Pembersihan data uji',
        ]);
        $delete->assertStatus(200);

        $pengajuan = $this->pengajuanPerawatan($idPerawatan);
        $this->assertNotNull($pengajuan);
        $this->assertSame('ditransfer', $pengajuan->status);
    }

    public function test_update_status_menjadi_selesai_membuat_pengajuan_otomatis(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->buatArmada();

        $create = $this->postJson("/api/armada/{$armada}/perawatan", [
            'tanggal'         => '2026-08-10',
            'jenis_perawatan' => 'Servis Besar',
            'biaya'           => 300000,
            'status'          => 'terjadwal',
        ]);
        $create->assertStatus(201);
        $idPerawatan = $create->json('data.id_perawatan');
        $this->assertNull($this->pengajuanPerawatan($idPerawatan));

        $update = $this->putJson("/api/armada/{$armada}/perawatan/{$idPerawatan}", [
            'status' => 'selesai',
        ]);
        $update->assertStatus(200);

        $pengajuan = $this->pengajuanPerawatan($idPerawatan);
        $this->assertNotNull($pengajuan);
        $this->assertEquals(300000, (float) $pengajuan->nominal);
    }

    public function test_dedup_pengajuan_perawatan_per_id_perawatan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->buatArmada();

        $res = $this->postJson("/api/armada/{$armada}/perawatan", [
            'tanggal'         => '2026-08-10',
            'jenis_perawatan' => 'Ganti Ban',
            'biaya'           => 200000,
            'status'          => 'selesai',
        ]);
        $idPerawatan = $res->json('data.id_perawatan');
        $this->assertSame(1, DB::table('pengajuan_pengeluaran')->where('id_perawatan', $idPerawatan)->count());

        app(ArusKasService::class)->buatPengajuanPerawatanOtomatis((object) ['id_perawatan' => $idPerawatan], 200000);

        $this->assertSame(1, DB::table('pengajuan_pengeluaran')->where('id_perawatan', $idPerawatan)->count());
    }

    public function test_sinkron_nominal_pengajuan_perawatan_saat_masih_diajukan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->buatArmada();

        $res = $this->postJson("/api/armada/{$armada}/perawatan", [
            'tanggal'         => '2026-08-10',
            'jenis_perawatan' => 'Ganti Ban',
            'biaya'           => 200000,
            'status'          => 'selesai',
        ]);
        $idPerawatan = $res->json('data.id_perawatan');

        app(ArusKasService::class)->sinkronNominalPengajuanPerawatan($idPerawatan, 275000);

        $pengajuan = $this->pengajuanPerawatan($idPerawatan);
        $this->assertEquals(275000, (float) $pengajuan->nominal);
        $this->assertSame('diajukan', $pengajuan->status);
    }

    public function test_sinkron_nominal_tidak_berubah_setelah_pengajuan_dicek(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->buatArmada();

        $res = $this->postJson("/api/armada/{$armada}/perawatan", [
            'tanggal'         => '2026-08-10',
            'jenis_perawatan' => 'Ganti Ban',
            'biaya'           => 200000,
            'status'          => 'selesai',
        ]);
        $idPerawatan = $res->json('data.id_perawatan');

        DB::table('pengajuan_pengeluaran')->where('id_perawatan', $idPerawatan)->update(['status' => 'dicek']);

        app(ArusKasService::class)->sinkronNominalPengajuanPerawatan($idPerawatan, 999000);

        $pengajuan = $this->pengajuanPerawatan($idPerawatan);
        $this->assertEquals(200000, (float) $pengajuan->nominal);
        $this->assertSame('dicek', $pengajuan->status);
    }

    public function test_rekap_perawatan_tidak_lagi_muncul_sebagai_sumber_langsung(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->buatArmada();

        DB::table('perawatan_armada')->insert([
            'id_perawatan'    => (string) Str::uuid(),
            'id_armada'       => $armada,
            'tanggal'         => '2026-08-10',
            'jenis_perawatan' => 'Ganti Oli',
            'biaya'           => 250000,
            'status'          => 'selesai',
            'dibuat_pada'     => now(),
        ]);

        $res = $this->getJson('/api/arus-kas?dari=2026-08-01&sampai=2026-08-31');
        $res->assertStatus(200);

        $rows = collect($res->json('data.transaksi'))->where('sumber', 'perawatan_armada');
        $this->assertCount(0, $rows);
    }

    public function test_rekap_pengajuan_perawatan_ditransfer_muncul_dengan_kategori_perawatan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->buatArmada('B 7777 AK');

        $res = $this->postJson("/api/armada/{$armada}/perawatan", [
            'tanggal'         => '2026-08-10',
            'jenis_perawatan' => 'Ganti Oli',
            'biaya'           => 250000,
            'status'          => 'selesai',
        ]);
        $idPerawatan = $res->json('data.id_perawatan');

        DB::table('pengajuan_pengeluaran')->where('id_perawatan', $idPerawatan)->update([
            'status'           => 'ditransfer',
            'tanggal_transfer' => '2026-08-15',
        ]);

        $rekap = $this->getJson('/api/arus-kas?dari=2026-08-01&sampai=2026-08-31');
        $rekap->assertStatus(200);

        $rows = collect($rekap->json('data.transaksi'))->where('sumber', 'pengajuan_pengeluaran')->values();
        $this->assertCount(1, $rows);
        $this->assertSame('perawatan', $rows[0]['kategori']);
        $this->assertEquals(250000, $rows[0]['nominal']);
        $this->assertSame('keluar', $rows[0]['arah']);
    }

    public function test_pembelian_sparepart_ber_id_perawatan_tetap_dikecualikan_dari_rekap(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->buatArmada();

        $idPerawatan = (string) Str::uuid();
        DB::table('perawatan_armada')->insert([
            'id_perawatan'    => $idPerawatan,
            'id_armada'       => $armada,
            'tanggal'         => '2026-08-10',
            'jenis_perawatan' => 'Servis Besar',
            'biaya'           => 100000,
            'status'          => 'selesai',
            'dibuat_pada'     => now(),
        ]);

        $idSupplier = (string) Str::uuid();
        DB::table('supplier')->insert([
            'id_supplier'   => $idSupplier,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => 'Toko Sparepart Test',
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);

        DB::table('pembelian_sparepart')->insert([
            'id_pembelian'       => (string) Str::uuid(),
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'nomor_pengajuan'    => 'PS-TEST-' . Str::random(6),
            'id_supplier'        => $idSupplier,
            'id_perawatan'       => $idPerawatan,
            'status'             => 'lunas',
            'total_estimasi'     => 500000,
            'total_aktual'       => 500000,
            'tanggal_pengajuan'  => '2026-08-01',
            'tanggal_pembelian'  => '2026-08-02',
            'tanggal_pembayaran' => '2026-08-08',
            'dibuat_pada'        => now(),
        ]);

        $rekap = $this->getJson('/api/arus-kas?dari=2026-08-01&sampai=2026-08-31');
        $rekap->assertStatus(200);

        $rows = collect($rekap->json('data.transaksi'))->where('sumber', 'pembelian_sparepart');
        $this->assertCount(0, $rows);
    }
}
