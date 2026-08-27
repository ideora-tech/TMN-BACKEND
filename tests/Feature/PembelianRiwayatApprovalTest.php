<?php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PembelianRiwayatApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function buatSupplier(): string
    {
        $id = (string) Str::uuid();
        DB::table('supplier')->insert([
            'id_supplier'   => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => 'Toko Test',
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function buatPembelian(string $idSupplier, array $override = []): string
    {
        $id = (string) Str::uuid();
        DB::table('pembelian_sparepart')->insert(array_merge([
            'id_pembelian'      => $id,
            'id_perusahaan'     => self::PERUSAHAAN_ID,
            'nomor_pengajuan'   => 'PS-TEST-' . Str::random(6),
            'id_supplier'       => $idSupplier,
            'status'            => 'diajukan',
            'total_estimasi'    => 300000,
            'tanggal_pengajuan' => '2026-08-10',
            'dibuat_pada'       => now(),
        ], $override));
        return $id;
    }

    private function buatPengajuanPembelian(string $idPembelian, array $override = []): string
    {
        $id = (string) Str::uuid();
        DB::table('pengajuan_pengeluaran')->insert(array_merge([
            'id_pengajuan'      => $id,
            'id_perusahaan'     => self::PERUSAHAAN_ID,
            'id_pembelian'      => $idPembelian,
            'nomor_pengajuan'   => 'PP-TEST-' . Str::random(6),
            'kategori'          => 'sparepart',
            'nominal'           => 300000,
            'tanggal_pengajuan' => '2026-08-10',
            'penerima'          => 'Toko Test',
            'status'            => 'diajukan',
            'dibuat_pada'       => '2026-08-10 08:00:00',
        ], $override));
        return $id;
    }

    public function test_detail_pembelian_memuat_riwayat_pengajuan_keuangan(): void
    {
        $pengguna  = $this->actingAsRole('SUPERADMIN');
        $supplier  = $this->buatSupplier();
        $pembelian = $this->buatPembelian($supplier, ['status' => 'disetujui_finance']);
        $this->buatPengajuanPembelian($pembelian, [
            'status'         => 'dicek',
            'dibuat_oleh'    => $pengguna->id_pengguna,
            'disetujui_oleh' => $pengguna->id_pengguna,
            'disetujui_pada' => '2026-08-11 09:00:00',
            'dicek_oleh'     => $pengguna->id_pengguna,
            'dicek_pada'     => '2026-08-12 10:00:00',
        ]);

        $res = $this->getJson("/api/pembelian-sparepart/{$pembelian}");
        $res->assertStatus(200);

        $info = $res->json('data.pengajuan_keuangan');
        $this->assertNotNull($info);
        $this->assertSame('dicek', $info['status']);
        $this->assertSame(300000, $info['nominal']);
        $this->assertStringStartsWith('PP-', (string) $info['nomor_pengajuan']);

        $statusRiwayat = array_column($info['riwayat'], 'status');
        $this->assertSame(['diajukan', 'disetujui', 'dicek'], $statusRiwayat);
        $this->assertSame($pengguna->username, $info['riwayat'][1]['oleh']);
        $this->assertSame($pengguna->username, $info['riwayat'][2]['oleh']);
    }

    public function test_pengajuan_keuangan_null_bila_tidak_ada_pengajuan_terkait(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $supplier  = $this->buatSupplier();
        $pembelian = $this->buatPembelian($supplier, ['id_perawatan' => (string) Str::uuid()]);

        $res = $this->getJson("/api/pembelian-sparepart/{$pembelian}");
        $res->assertStatus(200);
        $this->assertNull($res->json('data.pengajuan_keuangan'));
    }

    public function test_riwayat_ditolak_memuat_alasan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $supplier  = $this->buatSupplier();
        $pembelian = $this->buatPembelian($supplier, ['status' => 'ditolak']);
        $this->buatPengajuanPembelian($pembelian, [
            'status'         => 'ditolak',
            'alasan_ditolak' => 'Nominal terlalu besar',
            'diubah_pada'    => '2026-08-11 11:00:00',
        ]);

        $res = $this->getJson("/api/pembelian-sparepart/{$pembelian}");
        $res->assertStatus(200);

        $riwayat = $res->json('data.pengajuan_keuangan.riwayat');
        $ditolak = collect($riwayat)->firstWhere('status', 'ditolak');
        $this->assertNotNull($ditolak);
        $this->assertSame('Nominal terlalu besar', $ditolak['keterangan']);
    }
}
