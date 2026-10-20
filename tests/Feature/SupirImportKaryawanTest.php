<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Excel as ExcelWriterType;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class SupirImportKaryawanTest extends TestCase
{
    use RefreshDatabase;

    private const JUDUL = ['nik_karyawan', 'nama', 'no_sim', 'jenis_sim', 'tgl_kadaluarsa_sim', 'telepon', 'status', 'nopol_armada_default'];

    private function berkas(array $baris): UploadedFile
    {
        $export = new class($baris) implements FromArray, WithHeadings {
            public function __construct(private array $baris) {}

            public function array(): array
            {
                return $this->baris;
            }

            public function headings(): array
            {
                return ['nik_karyawan', 'nama', 'no_sim', 'jenis_sim', 'tgl_kadaluarsa_sim', 'telepon', 'status', 'nopol_armada_default'];
            }
        };

        $path = sys_get_temp_dir() . '/' . Str::random(10) . '.xlsx';
        file_put_contents($path, Excel::raw($export, ExcelWriterType::XLSX));

        return new UploadedFile($path, 'supir.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function jabatan(bool $isSupir, string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('jabatan')->insert([
            'id_jabatan' => $id, 'id_perusahaan' => $idPerusahaan, 'kode_jabatan' => 'J-' . Str::random(5),
            'nama_jabatan' => $isSupir ? 'Pengemudi' : 'Staf', 'is_supir' => $isSupir ? 1 : 0, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        return $id;
    }

    private function karyawan(string $nik, string $nama, ?string $idJabatan, array $lain = []): string
    {
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert(array_merge([
            'id_karyawan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_jabatan' => $idJabatan,
            'nik' => $nik, 'nama_karyawan' => $nama, 'telepon' => '0811' . substr($nik, -6), 'aktif' => 1, 'dibuat_pada' => now(),
        ], $lain));

        return $id;
    }

    private function supir(string $nama, ?string $idKaryawan, ?string $noSim): string
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_karyawan' => $idKaryawan,
            'nama' => $nama, 'no_sim' => $noSim, 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);

        return $id;
    }

    private function baris(array $isi): array
    {
        return array_map(static fn ($k) => $isi[$k] ?? null, self::JUDUL);
    }

    public function test_opsi_karyawan_hanya_karyawan_aktif_berjabatan_supir_dengan_info_tautan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $pengemudi = $this->jabatan(true);
        $staf      = $this->jabatan(false);
        $sudah     = $this->karyawan('1001', 'Andi Sudah', $pengemudi);
        $this->supir('Andi Sudah', $sudah, null);
        $belum     = $this->karyawan('1002', 'Budi Belum', $pengemudi);
        $this->karyawan('1003', 'Citra Staf', $staf);
        $this->karyawan('1004', 'Dodi Keluar', $pengemudi, ['aktif' => 0]);

        $res = $this->getJson('/api/supir/opsi-karyawan')->assertStatus(200);

        $this->assertSame(['1001', '1002'], collect($res->json('data'))->pluck('nik')->sort()->values()->all());
        $data = collect($res->json('data'))->keyBy('nik');
        $this->assertNotNull($data['1001']['id_supir_tertaut']);
        $this->assertNull($data['1002']['id_supir_tertaut']);
        $this->assertSame($belum, $data['1002']['id_karyawan']);
        $this->assertSame('08111002', $data['1002']['telepon']);
    }

    public function test_opsi_karyawan_dengan_id_supir_ikut_menampilkan_karyawan_tertautnya_walau_bukan_jabatan_supir(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $staf  = $this->karyawan('2001', 'Eko Staf', $this->jabatan(false));
        $idSupir = $this->supir('Eko Staf', $staf, 'SIM-EKO');

        $tanpa = collect($this->getJson('/api/supir/opsi-karyawan')->json('data'))->pluck('nik')->all();
        $dengan = collect($this->getJson('/api/supir/opsi-karyawan?id_supir=' . $idSupir)->json('data'))->pluck('nik')->all();

        $this->assertNotContains('2001', $tanpa);
        $this->assertContains('2001', $dengan);
    }

    public function test_import_melengkapi_sim_profil_otomatis_tanpa_membuat_supir_baru(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->karyawan('3001', 'Fajar Otomatis', $this->jabatan(true));
        $idSupir = $this->supir('Fajar Otomatis', $idKaryawan, null);

        $res = $this->postJson('/api/supir/import', ['file' => $this->berkas([
            $this->baris(['nik_karyawan' => '3001', 'nama' => 'Nama Lain', 'no_sim' => 'SIM-3001', 'jenis_sim' => 'b2', 'tgl_kadaluarsa_sim' => '2028-05-31']),
        ])]);

        $res->assertStatus(200)->assertJsonPath('data.berhasil', 1)->assertJsonPath('data.gagal', []);
        $this->assertSame(1, DB::table('supir')->where('id_karyawan', $idKaryawan)->whereNull('dihapus_pada')->count());
        $supir = DB::table('supir')->where('id_supir', $idSupir)->first();
        $this->assertSame('SIM-3001', $supir->no_sim);
        $this->assertSame('B2', $supir->jenis_sim);
        $this->assertSame('2028-05-31', substr((string) $supir->tgl_kadaluarsa_sim, 0, 10));
        $this->assertSame('Fajar Otomatis', $supir->nama);
    }

    public function test_import_melengkapi_profil_ditolak_tanpa_izin_ubah_supir(): void
    {
        $this->actingAsRole('OPS_UJI');
        $idMenu = DB::table('menu')->where('path', '/supir')->value('id_menu');
        if ($idMenu === null) {
            $idMenu = (string) Str::uuid();
            DB::table('menu')->insert(['id_menu' => $idMenu, 'nama_menu' => 'Supir', 'path' => '/supir', 'aktif' => 1, 'dibuat_pada' => now()]);
        }
        foreach (['lihat', 'tambah'] as $aksi) {
            DB::table('izin_peran')->insert([
                'id_izin' => (string) Str::uuid(), 'kode_peran' => 'OPS_UJI', 'id_menu' => $idMenu,
                'aksi' => $aksi, 'diizinkan' => 1, 'dibuat_pada' => now(),
            ]);
        }
        $pengemudi = $this->jabatan(true);
        $idKaryawan = $this->karyawan('3501', 'Galih Tanpa Izin', $pengemudi);
        $idSupir = $this->supir('Galih Tanpa Izin', $idKaryawan, null);
        $this->karyawan('3502', 'Hana Baru', $pengemudi);

        $res = $this->postJson('/api/supir/import', ['file' => $this->berkas([
            $this->baris(['nik_karyawan' => '3501', 'no_sim' => 'SIM-3501', 'status' => 'nonaktif']),
            $this->baris(['nik_karyawan' => '3502', 'no_sim' => 'SIM-3502']),
        ])]);

        $res->assertStatus(200)->assertJsonPath('data.berhasil', 1);
        $this->assertStringContainsString('butuh izin Ubah Supir', $res->json('data.gagal.0.alasan'));
        $supir = DB::table('supir')->where('id_supir', $idSupir)->first();
        $this->assertNull($supir->no_sim);
        $this->assertSame('aktif', $supir->status);
        $this->assertSame(1, DB::table('supir')->where('no_sim', 'SIM-3502')->count());
    }

    public function test_import_membuat_supir_tertaut_dengan_nama_dan_telepon_dari_karyawan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->karyawan('4001', 'Gilang Baru', $this->jabatan(true));

        $res = $this->postJson('/api/supir/import', ['file' => $this->berkas([
            $this->baris(['nik_karyawan' => '4001', 'no_sim' => 'SIM-4001']),
        ])]);

        $res->assertStatus(200)->assertJsonPath('data.berhasil', 1);
        $supir = DB::table('supir')->where('id_karyawan', $idKaryawan)->first();
        $this->assertNotNull($supir);
        $this->assertSame('Gilang Baru', $supir->nama);
        $this->assertSame('08114001', $supir->telepon);
        $this->assertSame('SIM-4001', $supir->no_sim);
        $this->assertSame('aktif', $supir->status);
    }

    public function test_import_menolak_karyawan_bukan_jabatan_supir_sudah_ber_sim_luar_perusahaan_dan_nik_ganda(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $pengemudi = $this->jabatan(true);
        $this->karyawan('5001', 'Hadi Staf', $this->jabatan(false));
        $lengkap = $this->karyawan('5002', 'Indra Lengkap', $pengemudi);
        $this->supir('Indra Lengkap', $lengkap, 'SIM-LAMA');
        $this->karyawan('5003', 'Joko Ganda', $pengemudi);

        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $this->karyawan('5004', 'Kiki Lain', $this->jabatan(true, $idLain), ['id_perusahaan' => $idLain]);

        $res = $this->postJson('/api/supir/import', ['file' => $this->berkas([
            $this->baris(['nik_karyawan' => '5001', 'no_sim' => 'SIM-A']),
            $this->baris(['nik_karyawan' => '5002', 'no_sim' => 'SIM-B']),
            $this->baris(['nik_karyawan' => '5003', 'no_sim' => 'SIM-C']),
            $this->baris(['nik_karyawan' => '5003', 'no_sim' => 'SIM-D']),
            $this->baris(['nik_karyawan' => '5004', 'no_sim' => 'SIM-E']),
        ])]);

        $res->assertStatus(200)->assertJsonPath('data.berhasil', 0);
        $alasan = collect($res->json('data.gagal'))->pluck('alasan', 'baris');
        $this->assertStringContainsString('bukan jabatan supir', $alasan[2]);
        $this->assertStringContainsString('sudah punya profil supir dengan SIM SIM-LAMA', $alasan[3]);
        $this->assertSame('NIK karyawan duplikat di dalam file', $alasan[4]);
        $this->assertSame('NIK karyawan duplikat di dalam file', $alasan[5]);
        $this->assertSame('Karyawan dengan NIK 5004 tidak ditemukan', $alasan[6]);
        $this->assertSame(0, DB::table('supir')->whereIn('no_sim', ['SIM-A', 'SIM-B', 'SIM-C', 'SIM-D', 'SIM-E'])->count());
    }

    public function test_import_tanpa_nik_tetap_membuat_supir_biasa(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/supir/import', ['file' => $this->berkas([
            $this->baris(['nama' => 'Lukman Lepas', 'no_sim' => 'SIM-6001', 'tgl_kadaluarsa_sim' => '31/12/2027']),
        ])]);

        $res->assertStatus(200)->assertJsonPath('data.berhasil', 1);
        $supir = DB::table('supir')->where('no_sim', 'SIM-6001')->first();
        $this->assertNull($supir->id_karyawan);
        $this->assertSame('2027-12-31', substr((string) $supir->tgl_kadaluarsa_sim, 0, 10));
    }

    public function test_template_berisi_sheet_referensi_karyawan_supir(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->karyawan('7001', 'Mamat Referensi', $this->jabatan(true));

        $res = $this->get('/api/supir/import/template');

        $res->assertStatus(200);
        $path = $res->baseResponse->getFile()->getPathname();
        $buku = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $this->assertSame('Supir', $buku->getSheet(0)->getTitle());
        $this->assertSame('nik_karyawan', $buku->getSheet(0)->getCell('A1')->getValue());
        $referensi = $buku->getSheetByName('Referensi');
        $this->assertNotNull($referensi);
        $isi = array_merge(...$referensi->toArray());
        $this->assertContains('Mamat Referensi', $isi);
        $this->assertContains('Belum ada', $isi);
    }
}
