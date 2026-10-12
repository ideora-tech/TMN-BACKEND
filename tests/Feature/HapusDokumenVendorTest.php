<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class HapusDokumenVendorTest extends TestCase
{
    use RefreshDatabase;

    private function migrasi(): object
    {
        return require database_path('migrations/2026_10_13_100000_hapus_dokumen_vendor.php');
    }

    private function buatVendor(string $nama, array $override = []): string
    {
        $this->ensurePerusahaan();
        $id = (string) Str::uuid();
        DB::table('vendor')->insert(array_merge([
            'id_vendor' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_vendor' => 'VDR-' . Str::random(8),
            'nama_vendor' => $nama, 'dibuat_pada' => now(),
        ], $override));

        return $id;
    }

    private function buatDokumen(string $idVendor, array $override = []): string
    {
        $id = (string) Str::uuid();
        DB::table('dokumen_vendor')->insert(array_merge([
            'id_dokumen_vendor' => $id, 'id_vendor' => $idVendor, 'jenis_dokumen' => 'SIUP',
            'nomor' => '12/PKS/2026', 'berlaku_sampai' => '2026-12-31', 'url_file' => 'dokumen/siup.pdf', 'dibuat_pada' => now(),
        ], $override));

        return $id;
    }

    public function test_tabel_dan_endpoint_dokumen_vendor_sudah_tidak_ada(): void
    {
        $this->assertFalse(Schema::hasTable('dokumen_vendor'));

        $this->actingAsRole('SUPERADMIN');
        $idVendor = $this->buatVendor('PT Tanpa Dokumen');
        $this->getJson("/api/vendor/{$idVendor}/dokumen")->assertStatus(404);
        $this->getJson('/api/dokumen-vendor/expiring')->assertStatus(404);
        $this->getJson('/api/dashboard/stats')->assertStatus(200);
        $this->artisan('notifikasi:dokumen-kadaluarsa')->assertExitCode(0);
    }

    public function test_migrasi_memindahkan_berkas_ke_foto_dan_lampiran_sebelum_menghapus_tabel(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('dokumen/siup.pdf', str_repeat('x', 2048));

        $migrasi = $this->migrasi();
        $migrasi->down();
        $this->assertTrue(Schema::hasTable('dokumen_vendor'));

        $idVendor  = $this->buatVendor('PT Ada Dokumen');
        $idTerhapus = $this->buatVendor('PT Sudah Dihapus', ['dihapus_pada' => now()]);
        DB::table('lampiran_master')->insert([
            'id_lampiran' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'jenis_pemilik' => 'vendor',
            'id_pemilik' => $idVendor, 'url_file' => 'lampiran-master/vendor/foto.jpg', 'nama_asli' => 'foto.jpg',
            'tipe_mime' => 'image/jpeg', 'ukuran' => 10, 'urutan' => 3, 'dibuat_pada' => now(),
        ]);

        $idDokumen = $this->buatDokumen($idVendor);
        $this->buatDokumen($idVendor, ['jenis_dokumen' => 'NPWP', 'nomor' => null, 'berlaku_sampai' => null, 'url_file' => 'dokumen/npwp.png']);
        $this->buatDokumen($idVendor, ['jenis_dokumen' => 'TDP', 'url_file' => null]);
        $this->buatDokumen($idVendor, ['jenis_dokumen' => 'NIB', 'url_file' => 'dokumen/nib.pdf', 'dihapus_pada' => now()]);
        $this->buatDokumen($idTerhapus, ['url_file' => 'dokumen/lain.pdf']);

        DB::table('notifikasi')->insert([
            ['id_notifikasi' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'judul' => 'Dokumen SIUP kadaluarsa', 'isi' => 'x',
                'tipe' => 'alert_dokumen', 'referensi_id' => $idDokumen, 'referensi_tipe' => 'dokumen_vendor', 'dibaca' => 0, 'dibuat_pada' => now()],
            ['id_notifikasi' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'judul' => 'Dokumen STNK kadaluarsa', 'isi' => 'x',
                'tipe' => 'alert_dokumen', 'referensi_id' => (string) Str::uuid(), 'referensi_tipe' => 'dokumen_armada', 'dibaca' => 0, 'dibuat_pada' => now()],
        ]);

        $migrasi->up();

        $this->assertFalse(Schema::hasTable('dokumen_vendor'));
        $this->assertSame(0, DB::table('notifikasi')->where('referensi_tipe', 'dokumen_vendor')->count());
        $this->assertSame(1, DB::table('notifikasi')->where('referensi_tipe', 'dokumen_armada')->count());

        $lampiran = DB::table('lampiran_master')->where('id_pemilik', $idVendor)->orderBy('urutan')->get();
        $this->assertCount(3, $lampiran);
        $this->assertSame(['foto.jpg', 'SIUP 12-PKS-2026 (berlaku s.d. 31-12-2026).pdf', 'NPWP.png'], $lampiran->pluck('nama_asli')->all());
        $this->assertSame([3, 4, 5], $lampiran->pluck('urutan')->map(fn ($u) => (int) $u)->all());
        $this->assertSame('dokumen/siup.pdf', $lampiran[1]->url_file);
        $this->assertSame('application/pdf', $lampiran[1]->tipe_mime);
        $this->assertSame(2048, (int) $lampiran[1]->ukuran);
        $this->assertSame('image/png', $lampiran[2]->tipe_mime);
        $this->assertSame(0, (int) $lampiran[2]->ukuran);
        $this->assertSame(self::PERUSAHAAN_ID, $lampiran[1]->id_perusahaan);
        $this->assertSame(0, DB::table('lampiran_master')->where('id_pemilik', $idTerhapus)->count());

        $this->actingAsRole('SUPERADMIN');
        $daftar = $this->getJson("/api/vendor/{$idVendor}/lampiran");
        if ($daftar->status() === 200) {
            $this->assertCount(3, $daftar->json('data'));
        }

        $migrasi->up();
        $this->assertSame(3, DB::table('lampiran_master')->where('id_pemilik', $idVendor)->count());
    }
}
