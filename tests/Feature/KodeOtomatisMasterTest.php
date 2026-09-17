<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KodeOtomatisMasterTest extends TestCase
{
    use RefreshDatabase;

    public function test_jenis_kendaraan_kode_dibuat_otomatis(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/jenis-kendaraan', ['nama_jenis' => 'Engkel Box']);

        $res->assertStatus(201);
        $this->assertMatchesRegularExpression('/^JNS-\d{4}$/', (string) $res->json('data.kode_jenis'));
    }

    public function test_klien_kode_dibuat_otomatis(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/klien', ['nama_klien' => 'PT Uji Kode']);

        $res->assertStatus(201);
        $this->assertMatchesRegularExpression('/^KLN-\d{4}$/', (string) $res->json('data.kode_klien'));
    }

    public function test_lokasi_kantor_kode_dibuat_otomatis(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/lokasi-kantor', ['nama_lokasi' => 'Kantor Pusat']);

        $res->assertStatus(201);
        $this->assertMatchesRegularExpression('/^LOK-\d{4}$/', (string) $res->json('data.kode_lokasi'));
    }

    public function test_tipe_pembayaran_kode_dibuat_otomatis(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/tipe-pembayaran', ['nama_tipe' => 'Termin 30 Hari']);

        $res->assertStatus(201);
        $this->assertMatchesRegularExpression('/^TPB-\d{4}$/', (string) $res->json('data.kode_tipe'));
    }

    public function test_vendor_kode_dibuat_otomatis(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/vendor', ['nama_vendor' => 'PT Vendor Uji']);

        $res->assertStatus(201);
        $this->assertMatchesRegularExpression('/^VDR-\d{4}$/', (string) $res->json('data.kode_vendor'));
    }

    public function test_sparepart_kode_dibuat_otomatis(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/sparepart', [
            'nama'          => 'Filter Oli Uji',
            'serial_number' => 'SN-' . Str::random(6),
        ]);

        $res->assertStatus(201);
        $this->assertMatchesRegularExpression('/^SPR-\d{4}$/', (string) $res->json('data.kode'));
    }

    public function test_nomor_urut_berlanjut_dan_prefix_mengikuti_pengaturan(): void
    {
        $this->actingAsRole('SUPERADMIN');

        DB::table('pengaturan_kode')->insert([
            'id_pengaturan_kode' => (string) Str::uuid(),
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'entitas'            => 'klien',
            'prefix'             => 'CUST',
            'panjang_digit'      => 3,
            'reset'              => 'tidak',
            'dibuat_pada'        => now(),
        ]);

        $pertama = $this->postJson('/api/klien', ['nama_klien' => 'Klien Satu']);
        $kedua   = $this->postJson('/api/klien', ['nama_klien' => 'Klien Dua']);

        $this->assertSame('CUST-001', $pertama->json('data.kode_klien'));
        $this->assertSame('CUST-002', $kedua->json('data.kode_klien'));
    }

    public function test_kode_kiriman_user_diabaikan(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/tipe-pembayaran', [
            'nama_tipe' => 'Tunai Uji',
            'kode_tipe' => 'KODE-NGAWUR',
        ]);

        $res->assertStatus(201);
        $this->assertNotSame('KODE-NGAWUR', $res->json('data.kode_tipe'));
        $this->assertMatchesRegularExpression('/^TPB-\d{4}$/', (string) $res->json('data.kode_tipe'));
    }
}
