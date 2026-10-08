<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IzinReferensiInvoiceVendorTest extends TestCase
{
    use RefreshDatabase;

    private function idMenu(string $path): string
    {
        $id = DB::table('menu')->where('path', $path)->value('id_menu');
        if ($id === null) {
            $id = (string) Str::uuid();
            DB::table('menu')->insert([
                'id_menu' => $id, 'nama_menu' => trim($path, '/'), 'path' => $path,
                'aktif' => 1, 'dibuat_pada' => now(),
            ]);
        }
        return (string) $id;
    }

    private function setIzin(string $path, string $aksi, int $diizinkan): void
    {
        $idMenu = $this->idMenu($path);
        DB::table('izin_peran')->where('id_menu', $idMenu)->where('kode_peran', 'KEUANGAN')->where('aksi', $aksi)->delete();
        DB::table('izin_peran')->insert([
            'id_izin' => (string) Str::uuid(), 'id_perusahaan' => null, 'kode_peran' => 'KEUANGAN',
            'id_menu' => $idMenu, 'aksi' => $aksi, 'diizinkan' => $diizinkan, 'dibuat_pada' => now(),
        ]);
    }

    private function tutupIzinMaster(): void
    {
        foreach (['/vendor', '/tipe-pembayaran', '/invoice-vendor'] as $path) {
            foreach (['lihat', 'tambah', 'ubah', 'hapus'] as $aksi) {
                $this->setIzin($path, $aksi, 0);
            }
        }
    }

    private function actingAsKeuangan(): Pengguna
    {
        $this->ensurePerusahaan();
        $pengguna = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran' => 'KEUANGAN', 'username' => 'keu_' . Str::random(6),
            'email' => Str::random(6) . '@test.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        Sanctum::actingAs($pengguna, ['*']);
        return $pengguna;
    }

    private function buatVendor(): string
    {
        $this->ensurePerusahaan();
        $id = (string) Str::uuid();
        DB::table('vendor')->insert([
            'id_vendor' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_vendor' => 'VND-' . Str::random(6),
            'nama_vendor' => 'PT Sinar Jaya Trans', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function buatTipePembayaran(): string
    {
        $this->ensurePerusahaan();
        $id = (string) Str::uuid();
        DB::table('tipe_pembayaran')->insert([
            'id_tipe_pembayaran' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_tipe' => 'uji_' . Str::random(6),
            'nama_tipe' => 'Tipe Uji', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    public function test_izin_invoice_vendor_cukup_untuk_membaca_data_referensi_form(): void
    {
        $this->tutupIzinMaster();
        $this->setIzin('/invoice-vendor', 'lihat', 1);
        $idVendor = $this->buatVendor();
        $this->buatTipePembayaran();
        $this->actingAsKeuangan();

        $this->getJson('/api/vendor?limit=999')->assertStatus(200)->assertJsonFragment(['id_vendor' => $idVendor]);
        $this->getJson("/api/vendor/{$idVendor}")->assertStatus(200);
        $this->getJson("/api/kontrak-vendor?id_vendor={$idVendor}&limit=999")->assertStatus(200);
        $this->getJson("/api/armada-vendor?page=1&limit=999&id_vendor={$idVendor}")->assertStatus(200);
        $this->getJson('/api/tipe-pembayaran/opsi-aktif')->assertStatus(200);
        $this->getJson('/api/tipe-pembayaran?page=1&limit=999')->assertStatus(200);
    }

    public function test_tanpa_izin_invoice_vendor_maupun_master_tetap_403(): void
    {
        $this->tutupIzinMaster();
        $idVendor = $this->buatVendor();
        $this->actingAsKeuangan();

        $this->getJson('/api/vendor?limit=999')->assertStatus(403);
        $this->getJson("/api/kontrak-vendor?id_vendor={$idVendor}")->assertStatus(403);
        $this->getJson('/api/armada-vendor')->assertStatus(403);
        $this->getJson('/api/tipe-pembayaran/opsi-aktif')->assertStatus(403);
    }

    public function test_menulis_data_master_tetap_butuh_izin_menu_masternya(): void
    {
        $this->tutupIzinMaster();
        foreach (['lihat', 'tambah', 'ubah', 'hapus'] as $aksi) {
            $this->setIzin('/invoice-vendor', $aksi, 1);
        }
        $idVendor = $this->buatVendor();
        $idTipe = $this->buatTipePembayaran();
        $this->actingAsKeuangan();

        $this->postJson('/api/vendor', ['nama_vendor' => 'Vendor Baru'])->assertStatus(403);
        $this->putJson("/api/vendor/{$idVendor}", ['nama_vendor' => 'Diubah'])->assertStatus(403);
        $this->deleteJson("/api/vendor/{$idVendor}")->assertStatus(403);
        $this->postJson('/api/kontrak-vendor', ['id_vendor' => $idVendor])->assertStatus(403);
        $this->postJson('/api/armada-vendor', ['id_vendor' => $idVendor])->assertStatus(403);
        $this->getJson("/api/armada-vendor/{$idVendor}")->assertStatus(403);
        $this->postJson('/api/tipe-pembayaran', ['nama_tipe' => 'Baru'])->assertStatus(403);
        $this->getJson("/api/tipe-pembayaran/{$idTipe}")->assertStatus(403);
        $this->deleteJson("/api/tipe-pembayaran/{$idTipe}")->assertStatus(403);
        $this->assertSame('PT Sinar Jaya Trans', DB::table('vendor')->where('id_vendor', $idVendor)->value('nama_vendor'));
    }
}
