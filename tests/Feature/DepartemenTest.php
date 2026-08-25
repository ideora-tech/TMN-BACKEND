<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DepartemenTest extends TestCase
{
    use RefreshDatabase;

    private function makeDepartemen(string $idPerusahaan, string $nama, ?string $idInduk = null): object
    {
        $id = (string) Str::uuid();
        DB::table('departemen')->insert([
            'id_departemen'       => $id,
            'id_perusahaan'       => $idPerusahaan,
            'id_departemen_induk' => $idInduk,
            'kode_departemen'     => 'DEP-' . Str::random(4),
            'nama_departemen'     => $nama,
            'aktif'               => 1,
            'dibuat_pada'         => now(),
        ]);
        return DB::table('departemen')->where('id_departemen', $id)->first();
    }

    public function test_membuat_departemen_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/departemen', [
            'nama_departemen' => 'Operasional',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nama_departemen', 'Operasional');
        $this->assertMatchesRegularExpression('/^DEP-\d{4}$/', $res->json('data.kode_departemen'));

        $this->assertDatabaseHas('departemen', [
            'kode_departemen' => $res->json('data.kode_departemen'),
            'id_perusahaan'   => self::PERUSAHAAN_ID,
        ]);
    }

    public function test_kode_departemen_dikirim_client_diabaikan_dan_dibuat_otomatis(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/departemen', [
            'kode_departemen' => 'KODE-BEBAS',
            'nama_departemen' => 'Keuangan',
        ]);

        $res->assertStatus(201);
        $this->assertMatchesRegularExpression('/^DEP-\d{4}$/', $res->json('data.kode_departemen'));
        $this->assertNotSame('KODE-BEBAS', $res->json('data.kode_departemen'));
        $this->assertDatabaseMissing('departemen', ['kode_departemen' => 'KODE-BEBAS']);
    }

    public function test_list_departemen_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->makeDepartemen(self::PERUSAHAAN_ID, 'HR');

        $res = $this->getJson('/api/departemen');

        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
    }

    public function test_show_departemen_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $item = $this->makeDepartemen(self::PERUSAHAAN_ID, 'Keuangan');

        $res = $this->getJson("/api/departemen/{$item->id_departemen}");

        $res->assertStatus(200)->assertJsonPath('data.nama_departemen', 'Keuangan');
    }

    public function test_update_departemen_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $item = $this->makeDepartemen(self::PERUSAHAAN_ID, 'Lama');

        $res = $this->putJson("/api/departemen/{$item->id_departemen}", [
            'nama_departemen' => 'Baru',
        ]);

        $res->assertStatus(200)->assertJsonPath('data.nama_departemen', 'Baru');
    }

    public function test_hapus_departemen_soft_delete(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $item = $this->makeDepartemen(self::PERUSAHAAN_ID, 'Dihapus');

        $res = $this->deleteJson("/api/departemen/{$item->id_departemen}");
        $res->assertStatus(200);

        $row = DB::table('departemen')->where('id_departemen', $item->id_departemen)->first();
        $this->assertNotNull($row->dihapus_pada);
    }

    public function test_tree_departemen_menyusun_struktur_induk_anak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $induk = $this->makeDepartemen(self::PERUSAHAAN_ID, 'Operasional');
        $anak = $this->makeDepartemen(self::PERUSAHAAN_ID, 'Armada', $induk->id_departemen);

        $res = $this->getJson('/api/departemen/tree');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Operasional', $data[0]['nama_departemen']);
        $this->assertCount(1, $data[0]['children']);
        $this->assertSame('Armada', $data[0]['children'][0]['nama_departemen']);
    }
}
