<?php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupplierTest extends TestCase
{
    use RefreshDatabase;

    private function makeSupplier(string $nama = 'Toko Sparepart Jaya', ?string $idPerusahaan = null): object
    {
        $id = (string) Str::uuid();
        DB::table('supplier')->insert([
            'id_supplier'   => $id,
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'nama'          => $nama,
            'telepon'       => '081234567890',
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return DB::table('supplier')->where('id_supplier', $id)->first();
    }

    private function makePerusahaanLain(): string
    {
        $id = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $id, 'nama' => 'Perusahaan Lain Test', 'dibuat_pada' => now()]);
        return $id;
    }

    public function test_create_supplier_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/supplier', [
            'nama' => 'Sinar Motor', 'telepon' => '0811111111', 'alamat' => 'Jl. Raya 1',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.nama', 'Sinar Motor')
            ->assertJsonPath('data.aktif', true);
        $this->assertDatabaseHas('supplier', ['nama' => 'Sinar Motor', 'id_perusahaan' => self::PERUSAHAAN_ID]);
    }

    public function test_validasi_nama_wajib(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->postJson('/api/supplier', ['telepon' => '08'])->assertStatus(422);
    }

    public function test_list_search_dan_scope_perusahaan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->makeSupplier('Milik Sendiri');
        $this->makeSupplier('Milik Orang', $this->makePerusahaanLain());

        $res = $this->getJson('/api/supplier');
        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));

        $resSearch = $this->getJson('/api/supplier?search=Sendiri');
        $this->assertCount(1, $resSearch->json('data'));
        $this->getJson('/api/supplier?search=TidakAda')->assertStatus(200)->assertJsonCount(0, 'data');
    }

    public function test_filter_aktif(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->makeSupplier('Aktif Satu');
        $nonaktif = $this->makeSupplier('Nonaktif');
        DB::table('supplier')->where('id_supplier', $nonaktif->id_supplier)->update(['aktif' => 0]);

        $res = $this->getJson('/api/supplier?aktif=1');
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Aktif Satu', $res->json('data.0.nama'));
    }

    public function test_update_dan_show(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $supplier = $this->makeSupplier();

        $this->putJson("/api/supplier/{$supplier->id_supplier}", ['nama' => 'Jaya Abadi', 'aktif' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.nama', 'Jaya Abadi')
            ->assertJsonPath('data.aktif', false);

        $this->getJson("/api/supplier/{$supplier->id_supplier}")
            ->assertStatus(200)->assertJsonPath('data.nama', 'Jaya Abadi');
    }

    public function test_soft_delete(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $supplier = $this->makeSupplier();

        $this->deleteJson("/api/supplier/{$supplier->id_supplier}")->assertStatus(200);
        $this->assertSoftDeleted('supplier', ['id_supplier' => $supplier->id_supplier]);
        $this->getJson("/api/supplier/{$supplier->id_supplier}")->assertStatus(404);
    }

    public function test_isolasi_tenant(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $supplier = $this->makeSupplier('Milik Orang', $this->makePerusahaanLain());

        $this->getJson("/api/supplier/{$supplier->id_supplier}")->assertStatus(404);
        $this->putJson("/api/supplier/{$supplier->id_supplier}", ['nama' => 'x'])->assertStatus(404);
        $this->deleteJson("/api/supplier/{$supplier->id_supplier}")->assertStatus(404);
    }
}
