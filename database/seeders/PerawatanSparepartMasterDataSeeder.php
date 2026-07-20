<?php
declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

// php artisan db:seed --class=PerawatanSparepartMasterDataSeeder
// Prasyarat: PerawatanMasterDataSeeder sudah dijalankan lebih dulu (menyediakan
// 5 id_jenis_perawatan berikut dengan UUID statis p2000001-...-1..5).
class PerawatanSparepartMasterDataSeeder extends Seeder
{
    private const ID_PERUSAHAAN = 'b8f3c1a2-0000-4000-8000-000000000001';

    // UUID id_jenis_perawatan statis dari PerawatanMasterDataSeeder (sudah ada di repo).
    private const ID_JP_OLI_MESIN    = 'p2000001-0000-4000-8000-000000000001';
    private const ID_JP_OLI_GARDAN   = 'p2000001-0000-4000-8000-000000000002';
    private const ID_JP_OLI_TRANS    = 'p2000001-0000-4000-8000-000000000003';
    private const ID_JP_FILTER_UDARA = 'p2000001-0000-4000-8000-000000000004';
    private const ID_JP_FILTER_SOLAR = 'p2000001-0000-4000-8000-000000000005';

    private const ID_JK_CDD     = '8dd00ef9-918d-462b-9c64-c02a3456b76f';
    private const ID_JK_PICKUP  = 'e2000001-0000-4000-8000-000000000002';
    private const ID_JK_ENGKEL  = 'e2000001-0000-4000-8000-000000000003';
    private const ID_JK_FUSO    = 'e2000001-0000-4000-8000-000000000004';
    private const ID_JK_TRONTON = 'e2000001-0000-4000-8000-000000000005';
    private const ID_JK_WINGBOX = 'e2000001-0000-4000-8000-000000000006';

    public function run(): void
    {
        $now = now();

        // ── Kategori Sparepart ───────────────────────────────────────────
        $idKategoriOli    = 'k2000001-0000-4000-8000-000000000001';
        $idKategoriFilter = 'k2000001-0000-4000-8000-000000000002';

        DB::table('kategori_sparepart')->upsert([
            ['id_kategori_sparepart' => $idKategoriOli,    'id_perusahaan' => self::ID_PERUSAHAAN, 'nama' => 'Oli & Pelumas', 'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null],
            ['id_kategori_sparepart' => $idKategoriFilter, 'id_perusahaan' => self::ID_PERUSAHAAN, 'nama' => 'Filter',       'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null],
        ], ['id_kategori_sparepart'], ['nama', 'aktif']);

        // ── Sparepart master ─────────────────────────────────────────────
        $sparepart = [
            'oli_mesin'    => ['id' => 's2000001-0000-4000-8000-000000000001', 'kode' => 'SP-OLI-MESIN',    'nama' => 'Oli Mesin Diesel 15W-40',           'satuan' => 'liter', 'harga' => 60000,  'kategori' => $idKategoriOli],
            'oli_gardan'   => ['id' => 's2000001-0000-4000-8000-000000000002', 'kode' => 'SP-OLI-GARDAN',   'nama' => 'Oli Gardan (Gear Oil 85W-140)',     'satuan' => 'liter', 'harga' => 70000,  'kategori' => $idKategoriOli],
            'oli_transmisi'=> ['id' => 's2000001-0000-4000-8000-000000000003', 'kode' => 'SP-OLI-TRANS',    'nama' => 'Oli Transmisi',                     'satuan' => 'liter', 'harga' => 65000,  'kategori' => $idKategoriOli],
            'filter_oli'   => ['id' => 's2000001-0000-4000-8000-000000000004', 'kode' => 'SP-FILTER-OLI',   'nama' => 'Filter Oli',                        'satuan' => 'pcs',   'harga' => 85000,  'kategori' => $idKategoriFilter],
            'filter_udara' => ['id' => 's2000001-0000-4000-8000-000000000005', 'kode' => 'SP-FILTER-UDARA', 'nama' => 'Filter Udara',                      'satuan' => 'pcs',   'harga' => 150000, 'kategori' => $idKategoriFilter],
            'filter_solar' => ['id' => 's2000001-0000-4000-8000-000000000006', 'kode' => 'SP-FILTER-SOLAR', 'nama' => 'Filter Solar',                      'satuan' => 'pcs',   'harga' => 120000, 'kategori' => $idKategoriFilter],
        ];

        $sparepartRows = array_map(fn (array $s) => [
            'id_sparepart'          => $s['id'],
            'id_perusahaan'         => self::ID_PERUSAHAAN,
            'kode'                  => $s['kode'],
            'nama'                  => $s['nama'],
            'id_kategori_sparepart' => $s['kategori'],
            'satuan'                => $s['satuan'],
            'harga_standar'         => $s['harga'],
            'stok'                  => 0,
            'aktif'                 => 1,
            'dibuat_pada'           => $now,
            'dibuat_oleh'           => null,
        ], $sparepart);

        DB::table('sparepart')->upsert(
            $sparepartRows,
            ['id_sparepart'],
            ['kode', 'nama', 'id_kategori_sparepart', 'satuan', 'harga_standar', 'aktif']
        );

        // ── Paket Perawatan Sparepart (BOM) ──────────────────────────────
        // qty per jenis kendaraan: pickup, cdd, engkel, fuso, tronton, wingbox
        $paket = [
            [self::ID_JP_OLI_MESIN,    'oli_mesin',     ['pickup' => 4, 'cdd' => 6, 'engkel' => 6, 'fuso' => 12, 'tronton' => 20, 'wingbox' => 15]],
            [self::ID_JP_OLI_MESIN,    'filter_oli',    ['pickup' => 1, 'cdd' => 1, 'engkel' => 1, 'fuso' => 1,  'tronton' => 1,  'wingbox' => 1]],
            [self::ID_JP_OLI_GARDAN,   'oli_gardan',    ['pickup' => 1, 'cdd' => 2, 'engkel' => 2, 'fuso' => 4,  'tronton' => 6,  'wingbox' => 5]],
            [self::ID_JP_OLI_TRANS,    'oli_transmisi', ['pickup' => 2, 'cdd' => 3, 'engkel' => 3, 'fuso' => 6,  'tronton' => 8,  'wingbox' => 7]],
            [self::ID_JP_FILTER_UDARA, 'filter_udara',  ['pickup' => 1, 'cdd' => 1, 'engkel' => 1, 'fuso' => 1,  'tronton' => 1,  'wingbox' => 1]],
            [self::ID_JP_FILTER_SOLAR, 'filter_solar',  ['pickup' => 1, 'cdd' => 1, 'engkel' => 1, 'fuso' => 1,  'tronton' => 1,  'wingbox' => 1]],
        ];

        $idJenisKendaraan = [
            'pickup'  => self::ID_JK_PICKUP,
            'cdd'     => self::ID_JK_CDD,
            'engkel'  => self::ID_JK_ENGKEL,
            'fuso'    => self::ID_JK_FUSO,
            'tronton' => self::ID_JK_TRONTON,
            'wingbox' => self::ID_JK_WINGBOX,
        ];

        $paketRows = [];
        $urut = 0;
        foreach ($paket as [$idJenisPerawatan, $kodeSparepart, $qtyPerKendaraan]) {
            foreach ($qtyPerKendaraan as $kodeTipe => $qty) {
                $urut++;
                $paketRows[] = [
                    'id_paket_perawatan_sparepart' => sprintf('b2000001-0000-4000-8000-%012d', $urut),
                    'id_perusahaan'                => self::ID_PERUSAHAAN,
                    'id_jenis_perawatan'           => $idJenisPerawatan,
                    'id_jenis_kendaraan'           => $idJenisKendaraan[$kodeTipe],
                    'id_sparepart'                 => $sparepart[$kodeSparepart]['id'],
                    'qty_standar'                  => $qty,
                    'aktif'                        => 1,
                    'dibuat_pada'                  => $now,
                    'dibuat_oleh'                  => null,
                ];
            }
        }

        DB::table('paket_perawatan_sparepart')->upsert(
            $paketRows,
            ['id_paket_perawatan_sparepart'],
            ['qty_standar', 'aktif']
        );
    }
}
