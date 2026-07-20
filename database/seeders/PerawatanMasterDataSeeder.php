<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

// php artisan db:seed --class=PerawatanMasterDataSeeder
//
// Sumber acuan interval (km/bulan resmi APM) yang dikonversi ke hari berdasarkan
// estimasi jarak tempuh rata-rata per kelas kendaraan pada operasional logistik aktif:
// - Isuzu Astra (Elf): oli mesin 5.000-10.000 km normal, 4.000-6.000 km beban berat;
//   filter udara/bahan bakar 20.000-30.000 km; servis besar 60.000 km.
// - Mitsubishi Fuso/KTB (Colt Diesel): servis berkala tiap 10.000 km atau 6 bulan.
class PerawatanMasterDataSeeder extends Seeder
{
    private const ID_PERUSAHAAN = 'b8f3c1a2-0000-4000-8000-000000000001';

    private const ID_JK_CDD     = '8dd00ef9-918d-462b-9c64-c02a3456b76f';
    private const ID_JK_PICKUP  = 'e2000001-0000-4000-8000-000000000002';
    private const ID_JK_ENGKEL  = 'e2000001-0000-4000-8000-000000000003';
    private const ID_JK_FUSO    = 'e2000001-0000-4000-8000-000000000004';
    private const ID_JK_TRONTON = 'e2000001-0000-4000-8000-000000000005';
    private const ID_JK_WINGBOX = 'e2000001-0000-4000-8000-000000000006';

    public function run(): void
    {
        $now = now();

        $jenis = [
            'oli_mesin' => [
                'id' => 'p2000001-0000-4000-8000-000000000001',
                'nama' => 'Ganti Oli Mesin & Filter Oli',
                'keterangan' => 'Ganti oli mesin beserta filter oli. Acuan APM: 5.000-10.000 km normal, dipersingkat untuk beban berat/operasional harian.',
                'interval' => ['pickup' => 60, 'cdd' => 45, 'engkel' => 45, 'fuso' => 30, 'tronton' => 30, 'wingbox' => 30],
            ],
            'oli_gardan' => [
                'id' => 'p2000001-0000-4000-8000-000000000002',
                'nama' => 'Ganti Oli Gardan (Differential)',
                'keterangan' => 'Ganti oli gardan/differential sesuai beban dan jarak tempuh kendaraan.',
                'interval' => ['pickup' => 180, 'cdd' => 150, 'engkel' => 150, 'fuso' => 120, 'tronton' => 90, 'wingbox' => 120],
            ],
            'oli_transmisi' => [
                'id' => 'p2000001-0000-4000-8000-000000000003',
                'nama' => 'Ganti Oli Transmisi',
                'keterangan' => 'Ganti oli transmisi manual/matic sesuai rekomendasi APM.',
                'interval' => ['pickup' => 180, 'cdd' => 150, 'engkel' => 150, 'fuso' => 120, 'tronton' => 90, 'wingbox' => 120],
            ],
            'filter_udara' => [
                'id' => 'p2000001-0000-4000-8000-000000000004',
                'nama' => 'Ganti Filter Udara',
                'keterangan' => 'Ganti filter udara mesin. Acuan APM: 20.000-40.000 km.',
                'interval' => ['pickup' => 120, 'cdd' => 90, 'engkel' => 90, 'fuso' => 60, 'tronton' => 60, 'wingbox' => 60],
            ],
            'filter_solar' => [
                'id' => 'p2000001-0000-4000-8000-000000000005',
                'nama' => 'Ganti Filter Solar (Bahan Bakar)',
                'keterangan' => 'Ganti filter bahan bakar/solar. Acuan APM: 20.000-30.000 km.',
                'interval' => ['pickup' => 90, 'cdd' => 75, 'engkel' => 75, 'fuso' => 60, 'tronton' => 45, 'wingbox' => 60],
            ],
            'rotasi_ban' => [
                'id' => 'p2000001-0000-4000-8000-000000000006',
                'nama' => 'Rotasi & Cek Tekanan Ban',
                'keterangan' => 'Rotasi ban dan pengecekan tekanan angin. Acuan umum: tiap 10.000 km.',
                'interval' => ['pickup' => 30, 'cdd' => 30, 'engkel' => 30, 'fuso' => 20, 'tronton' => 20, 'wingbox' => 20],
            ],
            'servis_rem' => [
                'id' => 'p2000001-0000-4000-8000-000000000007',
                'nama' => 'Servis Rem (Kampas & Sistem Pengereman)',
                'keterangan' => 'Cek dan ganti kampas rem beserta sistem pengereman.',
                'interval' => ['pickup' => 90, 'cdd' => 75, 'engkel' => 75, 'fuso' => 60, 'tronton' => 45, 'wingbox' => 60],
            ],
            'aki' => [
                'id' => 'p2000001-0000-4000-8000-000000000008',
                'nama' => 'Cek & Ganti Aki',
                'keterangan' => 'Pengecekan kondisi aki, ganti bila diperlukan.',
                'interval' => ['pickup' => 180, 'cdd' => 180, 'engkel' => 180, 'fuso' => 150, 'tronton' => 150, 'wingbox' => 150],
            ],
            'servis_besar' => [
                'id' => 'p2000001-0000-4000-8000-000000000009',
                'nama' => 'Servis Berkala Besar (Tune Up Lengkap)',
                'keterangan' => 'Servis besar/tune up lengkap termasuk cek sistem emisi. Acuan APM Isuzu: 60.000 km; Mitsubishi Fuso/KTB: tiap 6 bulan.',
                'interval' => ['pickup' => 180, 'cdd' => 180, 'engkel' => 180, 'fuso' => 150, 'tronton' => 120, 'wingbox' => 150],
            ],
            'sistem_pendingin' => [
                'id' => 'p2000001-0000-4000-8000-000000000010',
                'nama' => 'Cek Sistem Pendingin (Coolant/Radiator)',
                'keterangan' => 'Pengecekan coolant dan radiator.',
                'interval' => ['pickup' => 90, 'cdd' => 90, 'engkel' => 90, 'fuso' => 60, 'tronton' => 60, 'wingbox' => 60],
            ],
        ];

        $idJenisKendaraan = [
            'pickup'  => self::ID_JK_PICKUP,
            'cdd'     => self::ID_JK_CDD,
            'engkel'  => self::ID_JK_ENGKEL,
            'fuso'    => self::ID_JK_FUSO,
            'tronton' => self::ID_JK_TRONTON,
            'wingbox' => self::ID_JK_WINGBOX,
        ];

        $jenisRows = array_map(fn (array $j) => [
            'id_jenis_perawatan' => $j['id'],
            'id_perusahaan'      => self::ID_PERUSAHAAN,
            'nama'               => $j['nama'],
            'keterangan'         => $j['keterangan'],
            'aktif'              => 1,
            'dibuat_pada'        => $now,
            'dibuat_oleh'        => null,
        ], $jenis);

        DB::table('jenis_perawatan')->upsert(
            $jenisRows,
            ['id_jenis_perawatan'],
            ['nama', 'keterangan', 'aktif']
        );

        $intervalRows = [];
        $urut = 0;

        foreach ($jenis as $j) {
            foreach ($j['interval'] as $kodeTipe => $hari) {
                $urut++;

                $intervalRows[] = [
                    'id_interval_perawatan' => sprintf('i2000001-0000-4000-8000-%012d', $urut),
                    'id_perusahaan'         => self::ID_PERUSAHAAN,
                    'id_jenis_perawatan'    => $j['id'],
                    'id_jenis_kendaraan'    => $idJenisKendaraan[$kodeTipe],
                    'interval_hari'         => $hari,
                    'aktif'                 => 1,
                    'dibuat_pada'           => $now,
                    'dibuat_oleh'           => null,
                ];
            }
        }

        DB::table('interval_perawatan')->upsert(
            $intervalRows,
            ['id_interval_perawatan'],
            ['interval_hari', 'aktif']
        );
    }
}
