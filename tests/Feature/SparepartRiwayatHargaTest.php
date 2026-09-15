<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Excel as ExcelWriterType;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class SparepartRiwayatHargaTest extends TestCase
{
    use RefreshDatabase;

    private const HEADINGS = ['kode', 'nama', 'serial_number', 'merek', 'tahun', 'kategori', 'satuan', 'harga_standar', 'stok_awal'];
    private const MIGRASI = 'migrations/2026_09_15_100003_buat_sparepart_riwayat_harga.php';

    private function makeSparepart(array $override = []): object
    {
        $id = (string) Str::uuid();
        DB::table('sparepart')->insert(array_merge([
            'id_sparepart'  => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode'          => 'SP-' . Str::upper(Str::random(5)),
            'nama'          => 'Filter Oli',
            'serial_number' => 'FO-12345',
            'satuan'        => 'pcs',
            'harga_standar' => 50000,
            'stok'          => 0,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ], $override));
        return DB::table('sparepart')->where('id_sparepart', $id)->first();
    }

    private function makePerusahaanLain(): string
    {
        $id = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $id, 'nama' => 'Perusahaan Lain Test', 'dibuat_pada' => now()]);
        return $id;
    }

    private function insertRiwayat(string $idSparepart, ?float $lama, float $baru, string $sumber, $dibuatPada, ?string $dibuatOleh = null, ?string $keterangan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('sparepart_riwayat_harga')->insert([
            'id_riwayat'   => $id,
            'id_sparepart' => $idSparepart,
            'harga_lama'   => $lama,
            'harga_baru'   => $baru,
            'sumber'       => $sumber,
            'keterangan'   => $keterangan,
            'dibuat_pada'  => $dibuatPada,
            'dibuat_oleh'  => $dibuatOleh,
        ]);
        return $id;
    }

    private function makeXlsxUploadedFile(array $rows, array $headings = self::HEADINGS): UploadedFile
    {
        $export = new class($rows, $headings) implements FromArray, WithHeadings {
            public function __construct(private array $rows, private array $headings) {}

            public function array(): array
            {
                return $this->rows;
            }

            public function headings(): array
            {
                return $this->headings;
            }
        };

        $contents = Excel::raw($export, ExcelWriterType::XLSX);
        $path = sys_get_temp_dir() . '/' . Str::random(10) . '.xlsx';
        file_put_contents($path, $contents);

        return new UploadedFile(
            $path,
            'import.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    public function test_create_manual_mencatat_satu_riwayat_harga_awal(): void
    {
        $user = $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/sparepart', [
            'kode'          => 'SP-100',
            'nama'          => 'Kampas Rem',
            'serial_number' => 'KR-9001',
            'harga_standar' => 50000,
        ]);

        $res->assertStatus(201);
        $idSparepart = $res->json('data.id_sparepart');

        $riwayat = DB::table('sparepart_riwayat_harga')->where('id_sparepart', $idSparepart)->get();
        $this->assertCount(1, $riwayat);
        $this->assertNull($riwayat[0]->harga_lama);
        $this->assertSame(50000.0, (float) $riwayat[0]->harga_baru);
        $this->assertSame('manual', $riwayat[0]->sumber);
        $this->assertNull($riwayat[0]->keterangan);
        $this->assertSame($user->id_pengguna, $riwayat[0]->dibuat_oleh);
    }

    public function test_create_manual_tanpa_harga_mencatat_harga_awal_nol(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/sparepart', [
            'kode' => 'SP-101', 'nama' => 'Busi', 'serial_number' => 'BS-1',
        ]);

        $res->assertStatus(201);
        $riwayat = DB::table('sparepart_riwayat_harga')->where('id_sparepart', $res->json('data.id_sparepart'))->get();
        $this->assertCount(1, $riwayat);
        $this->assertSame(0.0, (float) $riwayat[0]->harga_baru);
    }

    public function test_update_harga_mencatat_riwayat_dengan_keterangan_dan_tanpa_perubahan_tidak_mencatat(): void
    {
        $user = $this->actingAsRole('SUPERADMIN');
        $sp = $this->makeSparepart(['harga_standar' => 50000]);

        $res = $this->putJson("/api/sparepart/{$sp->id_sparepart}", [
            'harga_standar'    => 75000,
            'keterangan_harga' => 'Kenaikan supplier',
        ]);

        $res->assertStatus(200)->assertJsonPath('data.harga_standar', 75000);

        $this->assertFalse(Schema::hasColumn('sparepart', 'keterangan_harga'));
        $this->assertSame(75000.0, (float) DB::table('sparepart')->where('id_sparepart', $sp->id_sparepart)->value('harga_standar'));

        $riwayat = DB::table('sparepart_riwayat_harga')->where('id_sparepart', $sp->id_sparepart)->get();
        $this->assertCount(1, $riwayat);
        $this->assertSame(50000.0, (float) $riwayat[0]->harga_lama);
        $this->assertSame(75000.0, (float) $riwayat[0]->harga_baru);
        $this->assertSame('manual', $riwayat[0]->sumber);
        $this->assertSame('Kenaikan supplier', $riwayat[0]->keterangan);
        $this->assertSame($user->id_pengguna, $riwayat[0]->dibuat_oleh);

        $this->putJson("/api/sparepart/{$sp->id_sparepart}", ['nama' => 'Filter Oli Baru'])->assertStatus(200);
        $this->assertSame(1, DB::table('sparepart_riwayat_harga')->where('id_sparepart', $sp->id_sparepart)->count());

        $this->putJson("/api/sparepart/{$sp->id_sparepart}", ['harga_standar' => 75000, 'keterangan_harga' => 'Tidak berubah'])->assertStatus(200);
        $this->assertSame(1, DB::table('sparepart_riwayat_harga')->where('id_sparepart', $sp->id_sparepart)->count());

        $this->putJson("/api/sparepart/{$sp->id_sparepart}", ['keterangan_harga' => str_repeat('x', 201)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['keterangan_harga']);
    }

    public function test_import_excel_mencatat_riwayat_sumber_import(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $file = $this->makeXlsxUploadedFile([
            ['SP-IMP', 'Filter Udara', 'FU-1', 'Sakura', '', '', 'pcs', 85000, ''],
        ]);

        $res = $this->postJson('/api/sparepart/import', ['file' => $file]);
        $res->assertStatus(200)->assertJsonPath('data.berhasil', 1)->assertJsonPath('data.gagal', []);

        $sp = DB::table('sparepart')->where('kode', 'SP-IMP')->first();
        $riwayat = DB::table('sparepart_riwayat_harga')->where('id_sparepart', $sp->id_sparepart)->get();
        $this->assertCount(1, $riwayat);
        $this->assertNull($riwayat[0]->harga_lama);
        $this->assertSame(85000.0, (float) $riwayat[0]->harga_baru);
        $this->assertSame('import', $riwayat[0]->sumber);
        $this->assertSame('Import Excel', $riwayat[0]->keterangan);
    }

    public function test_endpoint_riwayat_harga_urut_terbaru_dengan_selisih_dan_persen(): void
    {
        $user = $this->actingAsRole('SUPERADMIN');
        $sp = $this->makeSparepart(['harga_standar' => 75000]);

        $idAwal = $this->insertRiwayat($sp->id_sparepart, null, 50000, 'migrasi', now()->subMinutes(5));
        $idNaik = $this->insertRiwayat($sp->id_sparepart, 50000, 75000, 'manual', now(), $user->id_pengguna, 'Kenaikan supplier');

        $res = $this->getJson("/api/sparepart/{$sp->id_sparepart}/riwayat-harga");

        $res->assertStatus(200)
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.limit', 20)
            ->assertJsonPath('meta.total', 2);

        $data = $res->json('data');
        $this->assertCount(2, $data);

        $this->assertSame($idNaik, $data[0]['id_riwayat']);
        $this->assertSame(50000.0, (float) $data[0]['harga_lama']);
        $this->assertSame(75000.0, (float) $data[0]['harga_baru']);
        $this->assertSame(25000.0, (float) $data[0]['selisih']);
        $this->assertSame(50.0, (float) $data[0]['persen']);
        $this->assertSame('manual', $data[0]['sumber']);
        $this->assertSame('Kenaikan supplier', $data[0]['keterangan']);
        $this->assertSame($user->username, $data[0]['dibuat_oleh_nama']);
        $this->assertNotNull($data[0]['tanggal']);

        $this->assertSame($idAwal, $data[1]['id_riwayat']);
        $this->assertNull($data[1]['harga_lama']);
        $this->assertSame(50000.0, (float) $data[1]['harga_baru']);
        $this->assertNull($data[1]['selisih']);
        $this->assertNull($data[1]['persen']);
        $this->assertSame('migrasi', $data[1]['sumber']);
        $this->assertNull($data[1]['dibuat_oleh_nama']);
    }

    public function test_endpoint_riwayat_harga_persen_null_bila_harga_lama_nol_dan_pagination_jalan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $sp = $this->makeSparepart(['harga_standar' => 10000]);

        $this->insertRiwayat($sp->id_sparepart, 0, 10000, 'manual', now()->subMinute());
        $this->insertRiwayat($sp->id_sparepart, 10000, 12000, 'manual', now());

        $res = $this->getJson("/api/sparepart/{$sp->id_sparepart}/riwayat-harga?page=2&limit=1");

        $res->assertStatus(200)
            ->assertJsonPath('meta.page', 2)
            ->assertJsonPath('meta.limit', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.totalPages', 2);

        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame(0.0, (float) $data[0]['harga_lama']);
        $this->assertSame(10000.0, (float) $data[0]['selisih']);
        $this->assertNull($data[0]['persen']);
    }

    public function test_riwayat_harga_sparepart_perusahaan_lain_mengembalikan_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $sp = $this->makeSparepart(['id_perusahaan' => $this->makePerusahaanLain()]);
        $this->insertRiwayat($sp->id_sparepart, null, 50000, 'migrasi', now());

        $this->getJson("/api/sparepart/{$sp->id_sparepart}/riwayat-harga")->assertStatus(404);
        $this->getJson('/api/sparepart/' . Str::uuid() . '/riwayat-harga')->assertStatus(404);
    }

    public function test_harga_beli_terakhir_tampil_di_show_dan_list(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $sp = $this->makeSparepart(['kode' => 'SP-BELI', 'harga_standar' => 50000]);
        $spTanpaMutasi = $this->makeSparepart(['kode' => 'SP-KOSONG']);

        $idSupplier = (string) Str::uuid();
        DB::table('supplier')->insert([
            'id_supplier'   => $idSupplier,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => 'Toko A',
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);

        $idPembelian = (string) Str::uuid();
        DB::table('pembelian_sparepart')->insert([
            'id_pembelian'      => $idPembelian,
            'id_perusahaan'     => self::PERUSAHAAN_ID,
            'nomor_pengajuan'   => 'PS-TEST',
            'id_supplier'       => $idSupplier,
            'status'            => 'dibeli',
            'total_estimasi'    => 0,
            'tanggal_pengajuan' => now()->toDateString(),
            'dibuat_pada'       => now(),
        ]);

        DB::table('sparepart_mutasi')->insert([
            [
                'id_mutasi'    => (string) Str::uuid(),
                'id_sparepart' => $sp->id_sparepart,
                'jenis'        => 'masuk',
                'qty'          => 5,
                'harga'        => 40000,
                'id_pembelian' => null,
                'tanggal'      => now()->subDay()->toDateString(),
                'dibuat_pada'  => now()->subDay(),
                'dihapus_pada' => null,
            ],
            [
                'id_mutasi'    => (string) Str::uuid(),
                'id_sparepart' => $sp->id_sparepart,
                'jenis'        => 'masuk',
                'qty'          => 3,
                'harga'        => 45000,
                'id_pembelian' => $idPembelian,
                'tanggal'      => now()->toDateString(),
                'dibuat_pada'  => now(),
                'dihapus_pada' => null,
            ],
            [
                'id_mutasi'    => (string) Str::uuid(),
                'id_sparepart' => $sp->id_sparepart,
                'jenis'        => 'keluar',
                'qty'          => 1,
                'harga'        => 99000,
                'id_pembelian' => null,
                'tanggal'      => now()->toDateString(),
                'dibuat_pada'  => now(),
                'dihapus_pada' => null,
            ],
            [
                'id_mutasi'    => (string) Str::uuid(),
                'id_sparepart' => $sp->id_sparepart,
                'jenis'        => 'masuk',
                'qty'          => 2,
                'harga'        => 88000,
                'id_pembelian' => null,
                'tanggal'      => now()->addDay()->toDateString(),
                'dibuat_pada'  => now()->addDay(),
                'dihapus_pada' => now(),
            ],
            [
                'id_mutasi'    => (string) Str::uuid(),
                'id_sparepart' => $sp->id_sparepart,
                'jenis'        => 'masuk',
                'qty'          => 4,
                'harga'        => null,
                'id_pembelian' => null,
                'tanggal'      => now()->addDay()->toDateString(),
                'dibuat_pada'  => now()->addDay(),
                'dihapus_pada' => null,
            ],
        ]);

        $show = $this->getJson("/api/sparepart/{$sp->id_sparepart}");
        $show->assertStatus(200)
            ->assertJsonPath('data.harga_beli_terakhir.nomor_pengajuan', 'PS-TEST')
            ->assertJsonPath('data.harga_beli_terakhir.nama_supplier', 'Toko A')
            ->assertJsonPath('data.harga_beli_terakhir.id_pembelian', $idPembelian)
            ->assertJsonPath('data.harga_beli_terakhir.tanggal', now()->toDateString());
        $this->assertSame(45000.0, (float) $show->json('data.harga_beli_terakhir.harga'));

        $this->getJson("/api/sparepart/{$spTanpaMutasi->id_sparepart}")
            ->assertStatus(200)
            ->assertJsonPath('data.harga_beli_terakhir', null);

        $list = $this->getJson('/api/sparepart');
        $list->assertStatus(200);
        $rows = collect($list->json('data'))->keyBy('kode');
        $this->assertSame(45000.0, (float) $rows['SP-BELI']['harga_beli_terakhir']['harga']);
        $this->assertSame('PS-TEST', $rows['SP-BELI']['harga_beli_terakhir']['nomor_pengajuan']);
        $this->assertSame('Toko A', $rows['SP-BELI']['harga_beli_terakhir']['nama_supplier']);
        $this->assertNull($rows['SP-KOSONG']['harga_beli_terakhir']);
    }

    public function test_migration_backfill_hanya_sparepart_aktif_dengan_sumber_migrasi(): void
    {
        $this->ensurePerusahaan();
        Schema::dropIfExists('sparepart_riwayat_harga');

        $dibuatPada = now()->subDays(3)->startOfSecond();
        $aktif = $this->makeSparepart(['kode' => 'SP-AKTIF', 'harga_standar' => 123456.78, 'dibuat_pada' => $dibuatPada]);
        $this->makeSparepart(['kode' => 'SP-HAPUS', 'harga_standar' => 999, 'dihapus_pada' => now()]);

        $migration = require database_path(self::MIGRASI);
        $migration->up();

        $this->assertTrue(Schema::hasTable('sparepart_riwayat_harga'));

        $riwayat = DB::table('sparepart_riwayat_harga')->get();
        $this->assertCount(1, $riwayat);
        $this->assertSame($aktif->id_sparepart, $riwayat[0]->id_sparepart);
        $this->assertNull($riwayat[0]->harga_lama);
        $this->assertSame(123456.78, (float) $riwayat[0]->harga_baru);
        $this->assertSame('migrasi', $riwayat[0]->sumber);
        $this->assertNull($riwayat[0]->keterangan);
        $this->assertSame($dibuatPada->toDateTimeString(), (string) $riwayat[0]->dibuat_pada);
    }
}
