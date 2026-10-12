<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LampiranMasterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->ensurePerusahaan();
    }

    private function makeVendor(string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('vendor')->insert([
            'id_vendor' => $id, 'id_perusahaan' => $idPerusahaan,
            'kode_vendor' => 'VDR-' . Str::random(8), 'nama_vendor' => 'PT Vendor Uji', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        return $id;
    }

    private function makeSupplier(string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('supplier')->insert([
            'id_supplier' => $id, 'id_perusahaan' => $idPerusahaan,
            'nama' => 'Toko Supplier Uji', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        return $id;
    }

    private function unggah(string $jenis, string $id, array $files): TestResponse
    {
        return $this->post("/api/{$jenis}/{$id}/lampiran", ['lampiran' => $files], ['Accept' => 'application/json']);
    }

    public function test_vendor_dan_supplier_bisa_unggah_banyak_berkas_ke_tabel_yang_sama(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor   = $this->makeVendor();
        $supplier = $this->makeSupplier();

        $res = $this->unggah('vendor', $vendor, [
            UploadedFile::fake()->image('kantor.jpg'),
            UploadedFile::fake()->create('npwp.pdf', 200, 'application/pdf'),
        ]);
        $res->assertStatus(201)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.nama_asli', 'kantor.jpg')
            ->assertJsonPath('data.0.gambar', true)
            ->assertJsonPath('data.1.nama_asli', 'npwp.pdf')
            ->assertJsonPath('data.1.gambar', false);
        $this->assertNotNull($res->json('data.0.url_file'));
        $this->assertGreaterThan(0, $res->json('data.1.ukuran'));

        $this->unggah('vendor', $vendor, [UploadedFile::fake()->image('gudang.png')])
            ->assertStatus(201)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.2.nama_asli', 'gudang.png');

        $this->unggah('supplier', $supplier, [UploadedFile::fake()->create('penawaran.xlsx', 50, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')])
            ->assertStatus(201)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nama_asli', 'penawaran.xlsx');

        $this->getJson("/api/vendor/{$vendor}/lampiran")->assertStatus(200)->assertJsonCount(3, 'data');
        $this->getJson("/api/supplier/{$supplier}/lampiran")->assertStatus(200)->assertJsonCount(1, 'data');

        $this->assertSame(3, DB::table('lampiran_master')->where('jenis_pemilik', 'vendor')->where('id_pemilik', $vendor)->count());
        $this->assertSame(1, DB::table('lampiran_master')->where('jenis_pemilik', 'supplier')->where('id_pemilik', $supplier)->count());
        $this->assertSame(4, DB::table('lampiran_master')->where('id_perusahaan', self::PERUSAHAAN_ID)->count());
        foreach (DB::table('lampiran_master')->pluck('url_file') as $path) {
            Storage::disk('public')->assertExists($path);
        }
    }

    public function test_hapus_lampiran_hanya_lewat_pemilik_yang_benar(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor      = $this->makeVendor();
        $vendorLain  = $this->makeVendor();
        $supplier    = $this->makeSupplier();
        $idLampiran  = $this->unggah('vendor', $vendor, [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')])->json('data.0.id_lampiran');

        $this->deleteJson("/api/vendor/{$vendorLain}/lampiran/{$idLampiran}")->assertStatus(404);
        $this->deleteJson("/api/supplier/{$supplier}/lampiran/{$idLampiran}")->assertStatus(404);
        $this->deleteJson("/api/supplier/{$vendor}/lampiran/{$idLampiran}")->assertStatus(404);
        $this->getJson("/api/vendor/{$vendor}/lampiran")->assertJsonCount(2, 'data');

        $this->deleteJson("/api/vendor/{$vendor}/lampiran/{$idLampiran}")->assertStatus(200);
        $this->getJson("/api/vendor/{$vendor}/lampiran")->assertJsonCount(1, 'data')->assertJsonPath('data.0.nama_asli', 'b.jpg');
        $this->deleteJson("/api/vendor/{$vendor}/lampiran/{$idLampiran}")->assertStatus(404);
        $this->assertNotNull(DB::table('lampiran_master')->where('id_lampiran', $idLampiran)->value('dihapus_pada'));
    }

    public function test_validasi_tipe_ukuran_dan_batas_jumlah(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $supplier = $this->makeSupplier();

        $this->unggah('supplier', $supplier, [])->assertStatus(422)->assertJsonValidationErrors(['lampiran']);
        $this->unggah('supplier', $supplier, [UploadedFile::fake()->create('skrip.exe', 10, 'application/octet-stream')])
            ->assertStatus(422)->assertJsonValidationErrors(['lampiran.0']);
        $this->unggah('supplier', $supplier, [UploadedFile::fake()->create('besar.pdf', 5121, 'application/pdf')])
            ->assertStatus(422)->assertJsonValidationErrors(['lampiran.0']);
        $this->unggah('supplier', $supplier, array_map(fn (int $i) => UploadedFile::fake()->image("f{$i}.jpg"), range(1, 11)))
            ->assertStatus(422)->assertJsonValidationErrors(['lampiran']);
        $this->assertSame(0, DB::table('lampiran_master')->count());

        $sepuluh = fn (string $awalan) => array_map(fn (int $i) => UploadedFile::fake()->image("{$awalan}{$i}.jpg"), range(1, 10));
        $this->unggah('supplier', $supplier, $sepuluh('a'))->assertStatus(201)->assertJsonCount(10, 'data');
        $this->unggah('supplier', $supplier, $sepuluh('b'))->assertStatus(201)->assertJsonCount(20, 'data');
        $this->unggah('supplier', $supplier, [UploadedFile::fake()->image('lebih.jpg')])->assertStatus(422);
        $this->assertSame(20, DB::table('lampiran_master')->whereNull('dihapus_pada')->count());

        $idPertama = $this->getJson("/api/supplier/{$supplier}/lampiran")->json('data.0.id_lampiran');
        $this->deleteJson("/api/supplier/{$supplier}/lampiran/{$idPertama}")->assertStatus(200);
        $this->unggah('supplier', $supplier, [UploadedFile::fake()->image('pengganti.jpg')])
            ->assertStatus(201)->assertJsonCount(20, 'data')->assertJsonPath('data.19.nama_asli', 'pengganti.jpg');
    }

    public function test_lampiran_terisolasi_per_perusahaan_dan_mengikuti_izin_menu(): void
    {
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $vendorLain   = $this->makeVendor($idPerusahaanLain);
        $supplierLain = $this->makeSupplier($idPerusahaanLain);

        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $this->getJson("/api/vendor/{$vendorLain}/lampiran")->assertStatus(404);
        $this->unggah('vendor', $vendorLain, [UploadedFile::fake()->image('a.jpg')])->assertStatus(404);
        $this->getJson("/api/supplier/{$supplierLain}/lampiran")->assertStatus(404);
        $this->unggah('supplier', $supplierLain, [UploadedFile::fake()->image('a.jpg')])->assertStatus(404);
        $this->getJson('/api/vendor/' . Str::uuid() . '/lampiran')->assertStatus(404);

        DB::table('vendor')->where('id_vendor', $vendor)->update(['dihapus_pada' => now()]);
        $this->getJson("/api/vendor/{$vendor}/lampiran")->assertStatus(404);
        $this->assertSame(0, DB::table('lampiran_master')->count());

        $this->actingAsRole('SUPIR');
        $vendorAktif = $this->makeVendor();
        $this->getJson("/api/vendor/{$vendorAktif}/lampiran")->assertStatus(403);
        $this->unggah('vendor', $vendorAktif, [UploadedFile::fake()->image('a.jpg')])->assertStatus(403);
        $this->deleteJson("/api/vendor/{$vendorAktif}/lampiran/" . Str::uuid())->assertStatus(403);
        $this->getJson('/api/supplier/' . $this->makeSupplier() . '/lampiran')->assertStatus(403);
        $this->assertSame(0, DB::table('lampiran_master')->count());
    }

    public function test_kelola_lampiran_cukup_izin_ubah_tanpa_izin_hapus(): void
    {
        $idMenu = DB::table('menu')->where('path', '/supplier')->value('id_menu');
        $this->assertNotNull($idMenu);

        $aturIzin = function (string $aksi, int $diizinkan) use ($idMenu): void {
            DB::table('izin_peran')->where('id_menu', $idMenu)->where('kode_peran', 'PENGADAAN')
                ->where('aksi', $aksi)->where('id_perusahaan', self::PERUSAHAAN_ID)->delete();
            DB::table('izin_peran')->insert([
                'id_izin' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => 'PENGADAAN',
                'id_menu' => $idMenu, 'aksi' => $aksi, 'diizinkan' => $diizinkan, 'dibuat_pada' => now(),
            ]);
        };
        $aturIzin('lihat', 1);
        $aturIzin('tambah', 0);
        $aturIzin('ubah', 1);
        $aturIzin('hapus', 0);

        $this->actingAsRole('PENGADAAN');
        $supplier = $this->makeSupplier();

        $idLampiran = $this->unggah('supplier', $supplier, [UploadedFile::fake()->image('a.jpg')])
            ->assertStatus(201)->json('data.0.id_lampiran');
        $this->deleteJson("/api/supplier/{$supplier}/lampiran/{$idLampiran}")->assertStatus(200);

        $aturIzin('tambah', 1);
        $aturIzin('ubah', 0);
        $aturIzin('hapus', 1);
        $this->getJson("/api/supplier/{$supplier}/lampiran")->assertStatus(200)->assertJsonCount(0, 'data');
        $this->unggah('supplier', $supplier, [UploadedFile::fake()->image('b.jpg')])->assertStatus(403);
        $this->deleteJson("/api/supplier/{$supplier}/lampiran/{$idLampiran}")->assertStatus(403);
        $this->assertSame(0, DB::table('lampiran_master')->whereNull('dihapus_pada')->count());
    }

    public function test_berkas_dibersihkan_bila_penyimpanan_data_gagal(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $supplier = $this->makeSupplier();

        $this->mock(\App\Modules\LampiranMaster\Contracts\LampiranMasterRepositoryInterface::class, function ($mock) {
            $mock->shouldReceive('pemilikAda')->andReturn(true);
            $mock->shouldReceive('hitung')->andReturn(0);
            $mock->shouldReceive('kunciPemilik');
            $mock->shouldReceive('urutanTerakhir')->andReturn(0);
            $mock->shouldReceive('insert')->andThrow(new \RuntimeException('gagal simpan'));
        });

        $this->unggah('supplier', $supplier, [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')])
            ->assertStatus(500);

        $this->assertSame([], Storage::disk('public')->allFiles('lampiran-master'));
    }
}
