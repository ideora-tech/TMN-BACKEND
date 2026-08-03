<?php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PembelianSparepartTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_create_pengajuan_berhasil_dengan_nomor_dan_total(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/v1/pembelian-sparepart', $this->payloadPengajuan());

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
        $this->postJson('/api/v1/pembelian-sparepart', $this->payloadPengajuan())->assertStatus(201);
        $res2 = $this->postJson('/api/v1/pembelian-sparepart', $this->payloadPengajuan());
        $this->assertMatchesRegularExpression('/-0002$/', $res2->json('data.nomor_pengajuan'));
    }

    public function test_validasi_item_kosong_dan_qty_nol(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->postJson('/api/v1/pembelian-sparepart', $this->payloadPengajuan(['items' => []]))->assertStatus(422);
        $this->postJson('/api/v1/pembelian-sparepart', $this->payloadPengajuan([
            'items' => [['id_sparepart' => $this->makeSparepart(), 'qty' => 0, 'harga_estimasi' => 1000]],
        ]))->assertStatus(422);
    }

    public function test_sparepart_perusahaan_lain_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);

        $this->postJson('/api/v1/pembelian-sparepart', $this->payloadPengajuan([
            'items' => [['id_sparepart' => $this->makeSparepart('Punya Orang', 0, $idLain), 'qty' => 1, 'harga_estimasi' => 1000]],
        ]))->assertStatus(422);
    }

    public function test_kaitan_perawatan_opsional(): void
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

        $res = $this->postJson('/api/v1/pembelian-sparepart', $this->payloadPengajuan(['id_perawatan' => $idPerawatan]));
        $res->assertStatus(201)->assertJsonPath('data.id_perawatan', $idPerawatan);
        $this->assertNotNull($res->json('data.nopol_armada'));
    }

    public function test_list_filter_status_dan_search_nomor(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->postJson('/api/v1/pembelian-sparepart', $this->payloadPengajuan())->json('data.id_pembelian');
        DB::table('pembelian_sparepart')->where('id_pembelian', $id)->update(['status' => 'dibeli']);
        $this->postJson('/api/v1/pembelian-sparepart', $this->payloadPengajuan());

        $this->assertCount(1, $this->getJson('/api/v1/pembelian-sparepart?status=dibeli')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/pembelian-sparepart?search=0002')->json('data'));
    }

    public function test_update_dan_delete_hanya_saat_diajukan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $create = $this->postJson('/api/v1/pembelian-sparepart', $this->payloadPengajuan());
        $id = $create->json('data.id_pembelian');
        $idSparepartBaru = $this->makeSparepart('Kampas Rem');

        $this->putJson("/api/v1/pembelian-sparepart/{$id}", $this->payloadPengajuan([
            'items' => [['id_sparepart' => $idSparepartBaru, 'qty' => 3, 'harga_estimasi' => 40000]],
        ]))->assertStatus(200)->assertJsonPath('data.total_estimasi', 120000);

        DB::table('pembelian_sparepart')->where('id_pembelian', $id)->update(['status' => 'disetujui_manager']);
        $this->putJson("/api/v1/pembelian-sparepart/{$id}", $this->payloadPengajuan())->assertStatus(422);
        $this->deleteJson("/api/v1/pembelian-sparepart/{$id}")->assertStatus(422);

        DB::table('pembelian_sparepart')->where('id_pembelian', $id)->update(['status' => 'diajukan']);
        $this->deleteJson("/api/v1/pembelian-sparepart/{$id}")->assertStatus(200);
        $this->assertSoftDeleted('pembelian_sparepart', ['id_pembelian' => $id]);
    }

    public function test_isolasi_tenant(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->postJson('/api/v1/pembelian-sparepart', $this->payloadPengajuan())->json('data.id_pembelian');

        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        DB::table('pembelian_sparepart')->where('id_pembelian', $id)->update(['id_perusahaan' => $idLain]);

        $this->getJson("/api/v1/pembelian-sparepart/{$id}")->assertStatus(404);
    }

    private function buatPengajuan(): string
    {
        $res = $this->postJson('/api/v1/pembelian-sparepart', $this->payloadPengajuan());
        return $res->json('data.id_pembelian');
    }

    public function test_alur_approval_manager_lalu_finance(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->buatPengajuan();

        $this->actingAsRole('MANAGER');
        $this->patchJson("/api/v1/pembelian-sparepart/{$id}/approve-manager")
            ->assertStatus(200)->assertJsonPath('data.status', 'disetujui_manager');

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/v1/pembelian-sparepart/{$id}/approve-finance")
            ->assertStatus(200)->assertJsonPath('data.status', 'disetujui_finance');

        $row = DB::table('pembelian_sparepart')->where('id_pembelian', $id)->first();
        $this->assertNotNull($row->disetujui_manager_oleh);
        $this->assertNotNull($row->disetujui_manager_pada);
        $this->assertNotNull($row->disetujui_finance_oleh);
        $this->assertNotNull($row->disetujui_finance_pada);
    }

    public function test_approve_finance_sebelum_manager_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->buatPengajuan();
        $this->patchJson("/api/v1/pembelian-sparepart/{$id}/approve-finance")->assertStatus(422);
    }

    public function test_role_salah_ditolak_403(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->buatPengajuan();

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/v1/pembelian-sparepart/{$id}/approve-manager")->assertStatus(403);

        $this->actingAsRole('MANAGER');
        $this->patchJson("/api/v1/pembelian-sparepart/{$id}/approve-finance")->assertStatus(403);
    }

    public function test_tolak_wajib_alasan_dan_final(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->buatPengajuan();

        $this->actingAsRole('MANAGER');
        $this->patchJson("/api/v1/pembelian-sparepart/{$id}/tolak", [])->assertStatus(422);
        $this->patchJson("/api/v1/pembelian-sparepart/{$id}/tolak", ['alasan' => 'Harga kemahalan'])
            ->assertStatus(200)->assertJsonPath('data.status', 'ditolak')
            ->assertJsonPath('data.alasan_ditolak', 'Harga kemahalan');

        $this->patchJson("/api/v1/pembelian-sparepart/{$id}/approve-manager")->assertStatus(422);
    }

    public function test_keuangan_tolak_hanya_setelah_approve_manager(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->buatPengajuan();

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/v1/pembelian-sparepart/{$id}/tolak", ['alasan' => 'Anggaran habis'])->assertStatus(422);

        $this->actingAsRole('MANAGER');
        $this->patchJson("/api/v1/pembelian-sparepart/{$id}/approve-manager")->assertStatus(200);

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/v1/pembelian-sparepart/{$id}/tolak", ['alasan' => 'Anggaran habis'])
            ->assertStatus(200)->assertJsonPath('data.status', 'ditolak');
    }

    public function test_isolasi_tenant_endpoint_approval(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->buatPengajuan();

        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        DB::table('pembelian_sparepart')->where('id_pembelian', $id)->update(['id_perusahaan' => $idLain]);

        $this->patchJson("/api/v1/pembelian-sparepart/{$id}/approve-manager")->assertStatus(404);
        $this->patchJson("/api/v1/pembelian-sparepart/{$id}/approve-finance")->assertStatus(404);
        $this->patchJson("/api/v1/pembelian-sparepart/{$id}/tolak", ['alasan' => 'Uji tenant'])->assertStatus(404);
    }
}
