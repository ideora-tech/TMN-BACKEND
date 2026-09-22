<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KetersediaanVendorTest extends TestCase
{
    use RefreshDatabase;

    private function tanggal(int $offset): string
    {
        return now()->addDays($offset)->toDateString();
    }

    private function makeVendor(string $nama = 'Vendor A', ?string $idPerusahaan = null): string
    {
        $this->ensurePerusahaan();
        $id = (string) Str::uuid();
        DB::table('vendor')->insert([
            'id_vendor' => $id, 'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'kode_vendor' => 'VDR-' . Str::random(8), 'nama_vendor' => $nama, 'telepon' => '0812000111', 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeJenis(string $nama): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jenis' => 'JK-' . Str::random(6), 'nama_jenis' => $nama, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeUnit(string $idVendor, string $nopol, array $override = []): string
    {
        $id = (string) Str::uuid();
        DB::table('armada_vendor')->insert(array_merge([
            'id_armada_vendor' => $id, 'id_vendor' => $idVendor, 'nopol' => $nopol,
            'merk' => 'Hino', 'aktif' => 1, 'dibuat_pada' => now(),
        ], $override));
        return $id;
    }

    private function makeKlien(string $nama): string
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien' => 'KL-' . Str::random(6), 'nama_klien' => $nama, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeProyek(string $nama = 'Proyek X', ?string $idPerusahaan = null, ?string $idKlien = null, array $override = []): string
    {
        $this->ensurePerusahaan();
        $id = (string) Str::uuid();
        DB::table('proyek')->insert(array_merge([
            'id_proyek' => $id, 'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'id_klien' => $idKlien ?? (string) Str::uuid(), 'kode_proyek' => 'PRJ-' . Str::random(8),
            'nama_proyek' => $nama, 'dibuat_pada' => now(),
        ], $override));
        return $id;
    }

    private function makePenugasan(string $idUnit, string $idProyek, string $tanggal, string $status = 'selesai', array $override = []): string
    {
        $id = (string) Str::uuid();
        DB::table('penugasan')->insert(array_merge([
            'id_penugasan' => $id, 'id_proyek' => $idProyek, 'sumber' => 'vendor', 'id_armada_vendor' => $idUnit,
            'tanggal_tugas' => $tanggal, 'status' => $status, 'dibuat_pada' => now(),
        ], $override));
        return $id;
    }

    private function makeTripBerjalan(string $idPenugasan): void
    {
        $idJadwal = (string) Str::uuid();
        DB::table('jadwal_keberangkatan')->insert([
            'id_jadwal' => $idJadwal, 'id_penugasan' => $idPenugasan, 'dibuat_pada' => now(),
        ]);
        DB::table('trip')->insert([
            'id_trip' => (string) Str::uuid(), 'id_jadwal' => $idJadwal, 'status' => 'berjalan', 'dibuat_pada' => now(),
        ]);
    }

    private function makeAset(string $nopol, array $override = []): string
    {
        $this->ensurePerusahaan();
        $id = (string) Str::uuid();
        DB::table('armada')->insert(array_merge([
            'id_armada' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nopol' => $nopol,
            'merk' => 'Mitsubishi', 'kepemilikan' => 'internal', 'status' => 'tersedia', 'aktif' => 1, 'dibuat_pada' => now(),
        ], $override));
        return $id;
    }

    private function makePenugasanAset(string $idArmada, string $idProyek, string $tanggal, string $status = 'selesai'): string
    {
        $id = (string) Str::uuid();
        DB::table('penugasan')->insert([
            'id_penugasan' => $id, 'id_proyek' => $idProyek, 'sumber' => 'internal', 'id_armada' => $idArmada,
            'tanggal_tugas' => $tanggal, 'status' => $status, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeDokumenAset(string $idArmada, string $jenis, string $berlakuSampai, int $aktif = 1): void
    {
        DB::table('dokumen_armada')->insert([
            'id_dokumen_armada' => (string) Str::uuid(), 'id_armada' => $idArmada, 'jenis_dokumen' => $jenis,
            'berlaku_sampai' => $berlakuSampai, 'aktif' => $aktif, 'dibuat_pada' => now(),
        ]);
    }

    private function makePerawatanAset(string $idArmada, string $status, string $tanggal): void
    {
        DB::table('perawatan_armada')->insert([
            'id_perawatan' => (string) Str::uuid(), 'id_armada' => $idArmada, 'tanggal' => $tanggal,
            'jenis_perawatan' => 'Servis', 'biaya' => 0, 'status' => $status, 'dibuat_pada' => now(),
        ]);
    }

    private function nopolMap(string $url = '/api/ketersediaan-vendor?limit=100'): array
    {
        $res = $this->getJson($url)->assertStatus(200);
        return collect($res->json('data'))->keyBy('nopol')->all();
    }

    public function test_hanya_unit_yang_pernah_dipakai_di_proyek_yang_tampil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $proyek = $this->makeProyek();
        $pernah = $this->makeUnit($vendor, 'B 1111 AA');
        $this->makeUnit($vendor, 'B 2222 BB');
        $this->makePenugasan($pernah, $proyek, $this->tanggal(-3));

        $this->getJson('/api/ketersediaan-vendor')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nopol', 'B 1111 AA')
            ->assertJsonPath('data.0.status_ketersediaan', 'tersedia')
            ->assertJsonPath('data.0.nama_vendor', 'Vendor A')
            ->assertJsonPath('data.0.jumlah_proyek', 1)
            ->assertJsonPath('data.0.hari_pakai', 1)
            ->assertJsonPath('data.0.terakhir_dipakai', $this->tanggal(-3))
            ->assertJsonPath('data.0.proyek_terakhir.nama_proyek', 'Proyek X');
    }

    public function test_status_tersedia_terjadwal_dan_dipakai(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $proyek = $this->makeProyek('Proyek Lama');
        $proyekBaru = $this->makeProyek('Proyek Baru');

        $u1 = $this->makeUnit($vendor, 'B 1 AA');
        $this->makePenugasan($u1, $proyek, $this->tanggal(-10));

        $u2 = $this->makeUnit($vendor, 'B 2 AA');
        $this->makePenugasan($u2, $proyek, $this->tanggal(-10));
        $this->makePenugasan($u2, $proyekBaru, $this->tanggal(5), 'pending');
        $this->makePenugasan($u2, $proyekBaru, $this->tanggal(7), 'pending');

        $u3 = $this->makeUnit($vendor, 'B 3 AA');
        $this->makePenugasan($u3, $proyek, $this->tanggal(-10));
        $this->makePenugasan($u3, $proyekBaru, $this->tanggal(0), 'aktif');
        $this->makePenugasan($u3, $proyekBaru, $this->tanggal(2), 'pending');

        $u4 = $this->makeUnit($vendor, 'B 4 AA');
        $kemarin = $this->makePenugasan($u4, $proyek, $this->tanggal(-1), 'aktif');
        $this->makeTripBerjalan($kemarin);

        $unit = $this->nopolMap();

        $this->assertSame('tersedia', $unit['B 1 AA']['status_ketersediaan']);
        $this->assertNull($unit['B 1 AA']['perkiraan_bebas']);

        $this->assertSame('terjadwal', $unit['B 2 AA']['status_ketersediaan']);
        $this->assertSame($this->tanggal(5), $unit['B 2 AA']['jadwal_berikutnya']);
        $this->assertSame($this->tanggal(7), $unit['B 2 AA']['terjadwal_sampai']);
        $this->assertSame($this->tanggal(8), $unit['B 2 AA']['perkiraan_bebas']);
        $this->assertSame('Proyek Baru', $unit['B 2 AA']['proyek_jadwal']['nama_proyek']);

        $this->assertSame('dipakai', $unit['B 3 AA']['status_ketersediaan']);
        $this->assertSame($this->tanggal(2), $unit['B 3 AA']['terjadwal_sampai']);
        $this->assertSame($this->tanggal(3), $unit['B 3 AA']['perkiraan_bebas']);

        $this->assertSame('dipakai', $unit['B 4 AA']['status_ketersediaan']);

        $res = $this->getJson('/api/ketersediaan-vendor');
        $res->assertJsonPath('meta.ringkasan.total', 4)
            ->assertJsonPath('meta.ringkasan.tersedia', 1)
            ->assertJsonPath('meta.ringkasan.terjadwal', 1)
            ->assertJsonPath('meta.ringkasan.dipakai', 2);
        $this->assertSame('B 1 AA', $res->json('data.0.nopol'));
        $this->assertSame('B 2 AA', $res->json('data.1.nopol'));
    }

    public function test_filter_status_membatasi_data_tetapi_ringkasan_tetap_lengkap(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $proyek = $this->makeProyek();

        $bebas = $this->makeUnit($vendor, 'B 10 AA');
        $this->makePenugasan($bebas, $proyek, $this->tanggal(-4));
        $sibuk = $this->makeUnit($vendor, 'B 11 AA');
        $this->makePenugasan($sibuk, $proyek, $this->tanggal(-4));
        $this->makePenugasan($sibuk, $proyek, $this->tanggal(0), 'aktif');

        $this->getJson('/api/ketersediaan-vendor?status=tersedia')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nopol', 'B 10 AA')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.ringkasan.total', 2)
            ->assertJsonPath('meta.ringkasan.dipakai', 1);

        $this->getJson('/api/ketersediaan-vendor?status=dipakai')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nopol', 'B 11 AA');
    }

    public function test_data_yang_tidak_boleh_dihitung_diabaikan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $proyek = $this->makeProyek();

        $batal = $this->makeUnit($vendor, 'B 21 AA');
        $this->makePenugasan($batal, $proyek, $this->tanggal(-5), 'batal');

        $terhapus = $this->makeUnit($vendor, 'B 22 AA');
        $this->makePenugasan($terhapus, $proyek, $this->tanggal(-5), 'selesai', ['dihapus_pada' => now()]);

        $proyekHilang = $this->makeProyek('Proyek Hilang', null, null, ['dihapus_pada' => now()]);
        $diProyekHilang = $this->makeUnit($vendor, 'B 23 AA');
        $this->makePenugasan($diProyekHilang, $proyekHilang, $this->tanggal(-5));

        $hanyaJadwal = $this->makeUnit($vendor, 'B 24 AA');
        $this->makePenugasan($hanyaJadwal, $proyek, $this->tanggal(3), 'pending');

        $nonaktif = $this->makeUnit($vendor, 'B 25 AA', ['aktif' => 0]);
        $this->makePenugasan($nonaktif, $proyek, $this->tanggal(-5));

        $unitHapus = $this->makeUnit($vendor, 'B 26 AA', ['dihapus_pada' => now()]);
        $this->makePenugasan($unitHapus, $proyek, $this->tanggal(-5));

        $vendorHapus = $this->makeVendor('Vendor Hapus');
        DB::table('vendor')->where('id_vendor', $vendorHapus)->update(['dihapus_pada' => now()]);
        $unitVendorHapus = $this->makeUnit($vendorHapus, 'B 27 AA');
        $this->makePenugasan($unitVendorHapus, $proyek, $this->tanggal(-5));

        $valid = $this->makeUnit($vendor, 'B 28 AA');
        $this->makePenugasan($valid, $proyek, $this->tanggal(-5));
        $this->makePenugasan($valid, $proyek, $this->tanggal(4), 'batal');

        $unit = $this->nopolMap();

        $this->assertSame(['B 28 AA'], array_keys($unit));
        $this->assertSame('tersedia', $unit['B 28 AA']['status_ketersediaan']);
    }

    public function test_data_perusahaan_lain_tidak_bocor(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);

        $vendorLain = $this->makeVendor('Vendor Lain', $idLain);
        $proyekLain = $this->makeProyek('Proyek Lain', $idLain);
        $unitLain = $this->makeUnit($vendorLain, 'D 9 ZZ');
        $this->makePenugasan($unitLain, $proyekLain, $this->tanggal(-3));

        $vendorSaya = $this->makeVendor();
        $unitDiProyekLain = $this->makeUnit($vendorSaya, 'B 31 AA');
        $this->makePenugasan($unitDiProyekLain, $proyekLain, $this->tanggal(-3));

        $this->getJson('/api/ketersediaan-vendor')->assertStatus(200)->assertJsonCount(0, 'data');
        $this->getJson("/api/ketersediaan-vendor/vendor/{$unitLain}")->assertStatus(404);
        $this->getJson("/api/ketersediaan-vendor/vendor/{$unitDiProyekLain}")->assertStatus(404);
    }

    public function test_detail_unit_memuat_riwayat_proyek(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $klien = $this->makeKlien('PT Klien Satu');
        $p1 = $this->makeProyek('Proyek Satu', null, $klien);
        $p2 = $this->makeProyek('Proyek Dua');
        $unit = $this->makeUnit($vendor, 'B 41 AA');

        foreach ([-20, -19, -18] as $offset) {
            $this->makePenugasan($unit, $p1, $this->tanggal($offset));
        }
        $this->makePenugasan($unit, $p2, $this->tanggal(-5));
        $this->makePenugasan($unit, $p2, $this->tanggal(-1), 'batal');
        $this->makePenugasan($unit, $p2, $this->tanggal(3), 'pending');

        $res = $this->getJson("/api/ketersediaan-vendor/vendor/{$unit}");

        $res->assertStatus(200)
            ->assertJsonPath('data.nopol', 'B 41 AA')
            ->assertJsonPath('data.status_ketersediaan', 'terjadwal')
            ->assertJsonCount(2, 'data.riwayat_proyek')
            ->assertJsonPath('data.riwayat_proyek.0.nama_proyek', 'Proyek Dua')
            ->assertJsonPath('data.riwayat_proyek.0.hari_pakai', 1)
            ->assertJsonPath('data.riwayat_proyek.0.hari_terjadwal', 1)
            ->assertJsonPath('data.riwayat_proyek.1.nama_proyek', 'Proyek Satu')
            ->assertJsonPath('data.riwayat_proyek.1.nama_klien', 'PT Klien Satu')
            ->assertJsonPath('data.riwayat_proyek.1.hari_pakai', 3)
            ->assertJsonPath('data.riwayat_proyek.1.pertama_dipakai', $this->tanggal(-20))
            ->assertJsonPath('data.riwayat_proyek.1.terakhir_dipakai', $this->tanggal(-18));
    }

    public function test_detail_unit_yang_belum_pernah_dipakai_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $unit = $this->makeUnit($this->makeVendor(), 'B 51 AA');

        $this->getJson("/api/ketersediaan-vendor/vendor/{$unit}")->assertStatus(404);
        $this->getJson('/api/ketersediaan-vendor/vendor/' . Str::uuid())->assertStatus(404);
    }

    public function test_filter_vendor_jenis_dan_pencarian(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendorA = $this->makeVendor('Vendor Alfa');
        $vendorB = $this->makeVendor('Vendor Beta');
        $cdd = $this->makeJenis('CDD');
        $tronton = $this->makeJenis('Tronton');
        $proyek = $this->makeProyek();

        $a = $this->makeUnit($vendorA, 'B 61 AA', ['id_jenis_kendaraan' => $cdd, 'merk' => 'Mitsubishi']);
        $b = $this->makeUnit($vendorB, 'B 62 BB', ['id_jenis_kendaraan' => $tronton, 'merk' => 'Hino']);
        $c = $this->makeUnit($vendorB, 'B 63 CC', ['id_jenis_kendaraan' => $cdd, 'merk' => 'Isuzu']);
        foreach ([$a, $b, $c] as $unit) {
            $this->makePenugasan($unit, $proyek, $this->tanggal(-2));
        }

        $this->assertSame(['B 61 AA', 'B 63 CC'], collect($this->getJson("/api/ketersediaan-vendor?id_jenis_kendaraan={$cdd}")->json('data'))->pluck('nopol')->sort()->values()->all());
        $this->assertSame(['B 62 BB', 'B 63 CC'], collect($this->getJson("/api/ketersediaan-vendor?id_vendor={$vendorB}")->json('data'))->pluck('nopol')->sort()->values()->all());
        $this->assertSame(['B 62 BB'], collect($this->getJson('/api/ketersediaan-vendor?search=hino')->json('data'))->pluck('nopol')->all());
        $this->assertSame(['B 61 AA'], collect($this->getJson('/api/ketersediaan-vendor?search=alfa')->json('data'))->pluck('nopol')->all());
        $this->assertSame(['B 62 BB'], collect($this->getJson('/api/ketersediaan-vendor?search=tronton')->json('data'))->pluck('nopol')->all());
    }

    public function test_per_jenis_menghitung_status_tiap_jenis(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $cdd = $this->makeJenis('CDD');
        $tronton = $this->makeJenis('Tronton');
        $proyek = $this->makeProyek();

        $bebas1 = $this->makeUnit($vendor, 'B 71 AA', ['id_jenis_kendaraan' => $cdd]);
        $bebas2 = $this->makeUnit($vendor, 'B 72 AA', ['id_jenis_kendaraan' => $cdd]);
        $sibuk = $this->makeUnit($vendor, 'B 73 AA', ['id_jenis_kendaraan' => $tronton]);
        foreach ([$bebas1, $bebas2, $sibuk] as $unit) {
            $this->makePenugasan($unit, $proyek, $this->tanggal(-2));
        }
        $this->makePenugasan($sibuk, $proyek, $this->tanggal(0), 'aktif');

        $perJenis = collect($this->getJson('/api/ketersediaan-vendor')->json('meta.per_jenis'))->keyBy('nama_jenis');

        $this->assertSame(2, $perJenis['CDD']['tersedia']);
        $this->assertSame(2, $perJenis['CDD']['total']);
        $this->assertSame($cdd, $perJenis['CDD']['id_jenis_kendaraan']);
        $this->assertSame(0, $perJenis['Tronton']['tersedia']);
        $this->assertSame(1, $perJenis['Tronton']['dipakai']);
    }

    public function test_pilihan_jenis_dan_vendor_tetap_lengkap_saat_filter_jenis_aktif(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendorA = $this->makeVendor('Vendor Alfa');
        $vendorB = $this->makeVendor('Vendor Beta');
        $cdd = $this->makeJenis('CDD');
        $tronton = $this->makeJenis('Tronton');
        $proyek = $this->makeProyek();

        $a = $this->makeUnit($vendorA, 'B 91 AA', ['id_jenis_kendaraan' => $cdd]);
        $b = $this->makeUnit($vendorB, 'B 92 BB', ['id_jenis_kendaraan' => $tronton]);
        foreach ([$a, $b] as $unit) {
            $this->makePenugasan($unit, $proyek, $this->tanggal(-2));
        }
        $this->makePenugasan($b, $proyek, $this->tanggal(0), 'aktif');

        $res = $this->getJson("/api/ketersediaan-vendor?id_jenis_kendaraan={$cdd}&status=tersedia");

        $res->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nopol', 'B 91 AA')
            ->assertJsonPath('meta.ringkasan.total', 1)
            ->assertJsonPath('meta.ringkasan.tersedia', 1)
            ->assertJsonPath('meta.ringkasan.dipakai', 0)
            ->assertJsonCount(2, 'meta.per_jenis')
            ->assertJsonCount(2, 'meta.opsi_vendor')
            ->assertJsonPath('meta.opsi_vendor.0.nama_vendor', 'Vendor Alfa')
            ->assertJsonPath('meta.opsi_vendor.1.nama_vendor', 'Vendor Beta');

        $filterVendor = $this->getJson("/api/ketersediaan-vendor?id_vendor={$vendorA}");
        $filterVendor->assertJsonCount(2, 'meta.per_jenis')->assertJsonCount(2, 'meta.opsi_vendor');

        $perJenis = collect($filterVendor->json('meta.per_jenis'))->keyBy('nama_jenis');
        $this->assertSame(1, $perJenis['CDD']['tersedia']);
        $this->assertSame(0, $perJenis['Tronton']['total']);
        $this->assertSame($tronton, $perJenis['Tronton']['id_jenis_kendaraan']);
    }

    public function test_hari_dipakai_menghitung_tanggal_berbeda_bukan_jumlah_penugasan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $proyek = $this->makeProyek();
        $unit = $this->makeUnit($vendor, 'B 101 AA');

        foreach ([-9, -8] as $offset) {
            foreach (['selesai', 'selesai', 'selesai'] as $rit) {
                $this->makePenugasan($unit, $proyek, $this->tanggal($offset), $rit);
            }
        }
        $this->makePenugasan($unit, $proyek, $this->tanggal(4), 'pending');
        $this->makePenugasan($unit, $proyek, $this->tanggal(4), 'pending');

        $this->getJson('/api/ketersediaan-vendor')
            ->assertStatus(200)
            ->assertJsonPath('data.0.hari_pakai', 2)
            ->assertJsonPath('data.0.jumlah_proyek', 1);

        $this->getJson("/api/ketersediaan-vendor/vendor/{$unit}")
            ->assertStatus(200)
            ->assertJsonPath('data.riwayat_proyek.0.hari_pakai', 2)
            ->assertJsonPath('data.riwayat_proyek.0.hari_terjadwal', 1);
    }

    public function test_pencarian_angka_nol_dan_tanda_persen_bukan_wildcard(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor('Vendor Satu');
        $proyek = $this->makeProyek();
        $a = $this->makeUnit($vendor, 'B 1000 XX', ['merk' => 'Hino']);
        $b = $this->makeUnit($vendor, 'B 2222 XX', ['merk' => 'Isuzu']);
        foreach ([$a, $b] as $unit) {
            $this->makePenugasan($unit, $proyek, $this->tanggal(-2));
        }

        $this->assertSame(['B 1000 XX'], collect($this->getJson('/api/ketersediaan-vendor?search=0')->json('data'))->pluck('nopol')->all());
        $this->getJson('/api/ketersediaan-vendor?search=%25')->assertJsonCount(0, 'data');
        $this->getJson('/api/ketersediaan-vendor?search=_')->assertJsonCount(0, 'data');
        $this->getJson('/api/ketersediaan-vendor?search=ISUZU')->assertJsonCount(1, 'data')->assertJsonPath('data.0.nopol', 'B 2222 XX');
    }

    public function test_unit_milik_vendor_nonaktif_tidak_tampil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendorAktif = $this->makeVendor('Vendor Aktif');
        $vendorMati = $this->makeVendor('Vendor Mati');
        DB::table('vendor')->where('id_vendor', $vendorMati)->update(['aktif' => 0]);
        $proyek = $this->makeProyek();

        $aktif = $this->makeUnit($vendorAktif, 'B 111 AA');
        $mati = $this->makeUnit($vendorMati, 'B 222 AA');
        foreach ([$aktif, $mati] as $unit) {
            $this->makePenugasan($unit, $proyek, $this->tanggal(-2));
        }

        $res = $this->getJson('/api/ketersediaan-vendor');

        $res->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nopol', 'B 111 AA')
            ->assertJsonCount(1, 'meta.opsi_vendor');
        $this->getJson("/api/ketersediaan-vendor/vendor/{$mati}")->assertStatus(404);
    }

    public function test_proyek_tidak_disebut_bila_dipakai_karena_trip_tanpa_penugasan_hari_ini(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $proyekTrip = $this->makeProyek('Proyek Trip');
        $proyekBesok = $this->makeProyek('Proyek Besok');
        $unit = $this->makeUnit($vendor, 'B 121 AA');

        $kemarin = $this->makePenugasan($unit, $proyekTrip, $this->tanggal(-1), 'aktif');
        $this->makeTripBerjalan($kemarin);
        $this->makePenugasan($unit, $proyekBesok, $this->tanggal(1), 'pending');

        $res = $this->getJson('/api/ketersediaan-vendor');

        $res->assertStatus(200)
            ->assertJsonPath('data.0.status_ketersediaan', 'dipakai')
            ->assertJsonPath('data.0.proyek_jadwal', null)
            ->assertJsonPath('data.0.jadwal_berikutnya', $this->tanggal(1));
    }

    public function test_paginasi_membagi_data_dan_total_tetap_benar(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $proyek = $this->makeProyek();
        foreach (['B 131 AA', 'B 132 AA', 'B 133 AA'] as $nopol) {
            $this->makePenugasan($this->makeUnit($vendor, $nopol), $proyek, $this->tanggal(-2));
        }

        $halaman1 = $this->getJson('/api/ketersediaan-vendor?limit=2&page=1');
        $halaman1->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.totalPages', 2)
            ->assertJsonPath('meta.page', 1);

        $halaman2 = $this->getJson('/api/ketersediaan-vendor?limit=2&page=2');
        $halaman2->assertJsonCount(1, 'data')->assertJsonPath('meta.page', 2);

        $this->assertSame(
            ['B 131 AA', 'B 132 AA', 'B 133 AA'],
            collect([...$halaman1->json('data'), ...$halaman2->json('data')])->pluck('nopol')->sort()->values()->all(),
        );
        $this->getJson('/api/ketersediaan-vendor?limit=2&page=9')->assertJsonCount(0, 'data');
    }

    public function test_export_excel_menghasilkan_file(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $unit = $this->makeUnit($this->makeVendor(), 'B 81 AA');
        $this->makePenugasan($unit, $this->makeProyek(), $this->tanggal(-2));

        $res = $this->get('/api/ketersediaan-vendor/export/excel?status=tersedia');

        $res->assertStatus(200);
        $this->assertStringContainsString('.xlsx', (string) $res->headers->get('content-disposition'));
    }

    public function test_parameter_tidak_valid_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $this->getJson('/api/ketersediaan-vendor?status=rusak')->assertStatus(422);
        $this->getJson('/api/ketersediaan-vendor?limit=1000')->assertStatus(422);
        $this->getJson('/api/ketersediaan-vendor/export/excel?status=rusak')->assertStatus(422);
    }

    public function test_izin_menu_untuk_sales_dan_ditolak_untuk_keuangan(): void
    {
        $this->actingAsRole('SALES');
        $this->getJson('/api/ketersediaan-vendor')->assertStatus(200);
        $this->assertStringContainsString('/ketersediaan-vendor', (string) $this->getJson('/api/menu/tree')->getContent());

        $this->actingAsRole('DISPATCHER');
        $this->getJson('/api/ketersediaan-vendor')->assertStatus(200);

        $this->actingAsRole('KEUANGAN');
        $this->getJson('/api/ketersediaan-vendor')->assertStatus(403);
        $this->assertStringNotContainsString('/ketersediaan-vendor', (string) $this->getJson('/api/menu/tree')->getContent());
    }

    public function test_nama_menu_menjadi_ketersediaan_unit(): void
    {
        $this->assertSame('Ketersediaan Unit', DB::table('menu')->where('path', '/ketersediaan-vendor')->value('nama_menu'));
    }

    public function test_aset_milik_tampil_walau_belum_pernah_dipakai_dengan_dokumen_dan_kapasitas(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $cdd = $this->makeJenis('CDD');
        $aset = $this->makeAset('B 3001 AS', ['id_jenis_kendaraan' => $cdd, 'model' => 'Colt Diesel', 'tahun' => 2022, 'kapasitas_muatan_kg' => 1500]);
        $this->makeDokumenAset($aset, 'STNK', $this->tanggal(200));
        $this->makeDokumenAset($aset, 'STNK', $this->tanggal(900), 0);
        $this->makeDokumenAset($aset, 'KIR', $this->tanggal(20));

        $res = $this->getJson('/api/ketersediaan-vendor')->assertStatus(200)->assertJsonCount(1, 'data');

        $res->assertJsonPath('data.0.sumber', 'aset')
            ->assertJsonPath('data.0.id_unit', $aset)
            ->assertJsonPath('data.0.nopol', 'B 3001 AS')
            ->assertJsonPath('data.0.model', 'Colt Diesel')
            ->assertJsonPath('data.0.nama_jenis_kendaraan', 'CDD')
            ->assertJsonPath('data.0.kapasitas', '1.500 kg')
            ->assertJsonPath('data.0.tahun', 2022)
            ->assertJsonPath('data.0.status_ketersediaan', 'tersedia')
            ->assertJsonPath('data.0.jumlah_proyek', 0)
            ->assertJsonPath('data.0.hari_pakai', 0)
            ->assertJsonPath('data.0.terakhir_dipakai', null)
            ->assertJsonPath('data.0.nama_vendor', null)
            ->assertJsonPath('data.0.masa_berlaku_stnk', $this->tanggal(200))
            ->assertJsonPath('data.0.masa_berlaku_kir', $this->tanggal(20));
    }

    public function test_status_aset_perawatan_dipakai_terjadwal_dan_tersedia(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek('Proyek Aset');

        $this->makeAset('B 4001 AS', ['status' => 'perawatan']);

        $diBengkel = $this->makeAset('B 4002 AS');
        $this->makePerawatanAset($diBengkel, 'dalam_proses', $this->tanggal(-1));

        $this->makeAset('B 4003 AS', ['status' => 'digunakan']);

        $hariIni = $this->makeAset('B 4004 AS');
        $this->makePenugasanAset($hariIni, $proyek, $this->tanggal(-5));
        $this->makePenugasanAset($hariIni, $proyek, $this->tanggal(0), 'aktif');
        $this->makePenugasanAset($hariIni, $proyek, $this->tanggal(3), 'pending');

        $besok = $this->makeAset('B 4005 AS');
        $this->makePenugasanAset($besok, $proyek, $this->tanggal(6), 'pending');
        $this->makePenugasanAset($besok, $proyek, $this->tanggal(8), 'pending');

        $servisNanti = $this->makeAset('B 4006 AS');
        $this->makePerawatanAset($servisNanti, 'terjadwal', $this->tanggal(9));
        $this->makePerawatanAset($servisNanti, 'terjadwal', $this->tanggal(-30));
        $this->makePerawatanAset($servisNanti, 'selesai', $this->tanggal(12));

        $tripAset = $this->makeAset('B 4007 AS');
        $kemarin = $this->makePenugasanAset($tripAset, $proyek, $this->tanggal(-1), 'aktif');
        $this->makeTripBerjalan($kemarin);

        $unit = $this->nopolMap();

        $this->assertSame('perawatan', $unit['B 4001 AS']['status_ketersediaan']);
        $this->assertSame('perawatan', $unit['B 4002 AS']['status_ketersediaan']);
        $this->assertSame('dipakai', $unit['B 4003 AS']['status_ketersediaan']);
        $this->assertSame('dipakai', $unit['B 4004 AS']['status_ketersediaan']);
        $this->assertSame($this->tanggal(4), $unit['B 4004 AS']['perkiraan_bebas']);
        $this->assertSame('terjadwal', $unit['B 4005 AS']['status_ketersediaan']);
        $this->assertSame($this->tanggal(6), $unit['B 4005 AS']['jadwal_berikutnya']);
        $this->assertSame($this->tanggal(9), $unit['B 4005 AS']['perkiraan_bebas']);
        $this->assertSame('tersedia', $unit['B 4006 AS']['status_ketersediaan']);
        $this->assertSame($this->tanggal(9), $unit['B 4006 AS']['perawatan_berikutnya']);
        $this->assertNull($unit['B 4001 AS']['perawatan_berikutnya']);
        $this->assertSame('dipakai', $unit['B 4007 AS']['status_ketersediaan']);
        $this->assertNull($unit['B 4007 AS']['proyek_jadwal']);

        $this->getJson('/api/ketersediaan-vendor')
            ->assertJsonPath('meta.ringkasan.total', 7)
            ->assertJsonPath('meta.ringkasan.tersedia', 1)
            ->assertJsonPath('meta.ringkasan.terjadwal', 1)
            ->assertJsonPath('meta.ringkasan.dipakai', 3)
            ->assertJsonPath('meta.ringkasan.perawatan', 2);

        $this->getJson('/api/ketersediaan-vendor?status=perawatan')->assertJsonCount(2, 'data');
    }

    public function test_aset_yang_tidak_boleh_tampil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);

        $this->makeAset('B 5001 AS', ['status' => 'tidak_aktif']);
        $this->makeAset('B 5002 AS', ['aktif' => 0]);
        $this->makeAset('B 5003 AS', ['dihapus_pada' => now()]);
        $this->makeAset('B 5004 AS', ['kepemilikan' => 'vendor']);
        $this->makeAset('B 5005 AS', ['id_perusahaan' => $idLain]);
        $valid = $this->makeAset('B 5006 AS');

        $this->assertSame(['B 5006 AS'], array_keys($this->nopolMap()));
        $this->getJson("/api/ketersediaan-vendor/aset/{$valid}")->assertStatus(200);
    }

    public function test_filter_sumber_dan_hitungan_gabungan_per_jenis(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $cdd = $this->makeJenis('CDD');
        $vendor = $this->makeVendor('Vendor Gabung');
        $proyek = $this->makeProyek();

        $this->makeAset('B 6001 AS', ['id_jenis_kendaraan' => $cdd]);
        $unitVendor = $this->makeUnit($vendor, 'B 6002 VN', ['id_jenis_kendaraan' => $cdd]);
        $this->makePenugasan($unitVendor, $proyek, $this->tanggal(-3));

        $this->getJson('/api/ketersediaan-vendor')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.ringkasan.tersedia', 2)
            ->assertJsonCount(1, 'meta.per_jenis')
            ->assertJsonPath('meta.per_jenis.0.tersedia', 2)
            ->assertJsonPath('meta.per_jenis.0.tersedia_aset', 1)
            ->assertJsonPath('meta.per_jenis.0.tersedia_vendor', 1)
            ->assertJsonCount(1, 'meta.opsi_vendor');

        $this->getJson('/api/ketersediaan-vendor?sumber=aset')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nopol', 'B 6001 AS')
            ->assertJsonPath('meta.ringkasan.total', 1)
            ->assertJsonPath('meta.per_jenis.0.tersedia_vendor', 0);

        $this->getJson('/api/ketersediaan-vendor?sumber=vendor')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nopol', 'B 6002 VN');

        $this->getJson("/api/ketersediaan-vendor?id_vendor={$vendor}")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sumber', 'vendor');

        $this->getJson('/api/ketersediaan-vendor?search=mitsubishi')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sumber', 'aset');
    }

    public function test_urutan_status_lalu_terakhir_dipakai_lalu_nopol(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $proyek = $this->makeProyek();

        $this->makeAset('B 7003 AS', ['status' => 'perawatan']);
        $this->makeAset('B 7002 AS');
        $lama = $this->makeUnit($vendor, 'B 7004 VN');
        $this->makePenugasan($lama, $proyek, $this->tanggal(-20));
        $baru = $this->makeUnit($vendor, 'B 7005 VN');
        $this->makePenugasan($baru, $proyek, $this->tanggal(-2));
        $sibuk = $this->makeAset('B 7001 AS');
        $this->makePenugasanAset($sibuk, $proyek, $this->tanggal(0), 'aktif');

        $urutan = collect($this->getJson('/api/ketersediaan-vendor')->json('data'))->pluck('nopol')->all();

        $this->assertSame(['B 7005 VN', 'B 7004 VN', 'B 7002 AS', 'B 7001 AS', 'B 7003 AS'], $urutan);
    }

    public function test_detail_aset_dan_penolakan_lintas_sumber_atau_perusahaan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek('Proyek Aset');
        $aset = $this->makeAset('B 8001 AS');
        $this->makePenugasanAset($aset, $proyek, $this->tanggal(-4));
        $this->makePenugasanAset($aset, $proyek, $this->tanggal(-4));
        $this->makePenugasanAset($aset, $proyek, $this->tanggal(-3));
        $this->makePenugasanAset($aset, $proyek, $this->tanggal(2), 'pending');

        $unitVendor = $this->makeUnit($this->makeVendor(), 'B 8002 VN');
        $this->makePenugasan($unitVendor, $proyek, $this->tanggal(-1));

        $this->getJson("/api/ketersediaan-vendor/aset/{$aset}")
            ->assertStatus(200)
            ->assertJsonPath('data.sumber', 'aset')
            ->assertJsonPath('data.status_ketersediaan', 'terjadwal')
            ->assertJsonCount(1, 'data.riwayat_proyek')
            ->assertJsonPath('data.riwayat_proyek.0.nama_proyek', 'Proyek Aset')
            ->assertJsonPath('data.riwayat_proyek.0.hari_pakai', 2)
            ->assertJsonPath('data.riwayat_proyek.0.hari_terjadwal', 1);

        $this->getJson("/api/ketersediaan-vendor/vendor/{$aset}")->assertStatus(404);
        $this->getJson("/api/ketersediaan-vendor/aset/{$unitVendor}")->assertStatus(404);
        $this->getJson("/api/ketersediaan-vendor/lain/{$aset}")->assertStatus(404);

        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $asetLain = $this->makeAset('D 1 ZZ', ['id_perusahaan' => $idLain]);
        $this->getJson("/api/ketersediaan-vendor/aset/{$asetLain}")->assertStatus(404);
    }

    public function test_export_excel_memuat_aset_dan_vendor(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->makeAset('B 9001 AS');
        $unit = $this->makeUnit($this->makeVendor(), 'B 9002 VN');
        $this->makePenugasan($unit, $this->makeProyek(), $this->tanggal(-2));

        $res = $this->get('/api/ketersediaan-vendor/export/excel?sumber=aset');

        $res->assertStatus(200);
        $this->assertStringContainsString('ketersediaan-unit-', (string) $res->headers->get('content-disposition'));
        $this->get('/api/ketersediaan-vendor/export/excel')->assertStatus(200);
    }

    public function test_parameter_sumber_tidak_valid_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $this->getJson('/api/ketersediaan-vendor?sumber=lain')->assertStatus(422);
        $this->getJson('/api/ketersediaan-vendor?status=perawatan&sumber=aset')->assertStatus(200);
    }
}
