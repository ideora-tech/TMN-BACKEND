<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

// php artisan db:seed --class=ArmadaSupirDummySeeder
class ArmadaSupirDummySeeder extends Seeder
{
    private const ID_PERUSAHAAN = 'b8f3c1a2-0000-4000-8000-000000000001';
    private const ID_JK_CDD     = '8dd00ef9-918d-462b-9c64-c02a3456b76f'; // jenis "CDD" existing di live DB

    public function run(): void
    {
        $now = now();

        // ── Jenis kendaraan tambahan ─────────────────────────────────────
        $idJenis = [
            'pickup'  => 'e2000001-0000-4000-8000-000000000002',
            'engkel'  => 'e2000001-0000-4000-8000-000000000003',
            'fuso'    => 'e2000001-0000-4000-8000-000000000004',
            'tronton' => 'e2000001-0000-4000-8000-000000000005',
            'wingbox' => 'e2000001-0000-4000-8000-000000000006',
            'cdd'     => self::ID_JK_CDD,
        ];

        $jenisBaru = [
            ['id' => $idJenis['pickup'],  'kode' => '02', 'nama' => 'Pickup',  'kapasitas' => 1000],
            ['id' => $idJenis['engkel'],  'kode' => '03', 'nama' => 'Engkel (CDE)', 'kapasitas' => 2200],
            ['id' => $idJenis['fuso'],    'kode' => '04', 'nama' => 'Fuso',    'kapasitas' => 8000],
            ['id' => $idJenis['tronton'], 'kode' => '05', 'nama' => 'Tronton', 'kapasitas' => 15000],
            ['id' => $idJenis['wingbox'], 'kode' => '06', 'nama' => 'Wingbox', 'kapasitas' => 12000],
        ];

        $jkRows = array_map(fn (array $j) => [
            'id_jenis_kendaraan' => $j['id'],
            'id_perusahaan'      => self::ID_PERUSAHAAN,
            'kode_jenis'         => $j['kode'],
            'nama_jenis'         => $j['nama'],
            'kapasitas_muatan'   => $j['kapasitas'],
            'aktif'              => 1,
            'dibuat_pada'        => $now,
            'dibuat_oleh'        => null,
        ], $jenisBaru);

        DB::table('jenis_kendaraan')->upsert(
            $jkRows,
            ['id_jenis_kendaraan'],
            ['kode_jenis', 'nama_jenis', 'kapasitas_muatan', 'aktif']
        );

        // ── Spesifikasi per tipe armada ──────────────────────────────────
        $tipe = [
            'pickup'  => ['bbm' => 'bensin', 'kapasitas' => 1000,  'harga' => 195000000.00,  'sim' => 'B1',
                          'unit' => [['Daihatsu', 'Gran Max PU 1.5'], ['Suzuki', 'New Carry Pick Up']]],
            'engkel'  => ['bbm' => 'solar',  'kapasitas' => 2200,  'harga' => 330000000.00,  'sim' => 'B1',
                          'unit' => [['Isuzu', 'Elf NLR 55'], ['Mitsubishi', 'Colt Diesel FE 71']]],
            'cdd'     => ['bbm' => 'solar',  'kapasitas' => 5000,  'harga' => 465000000.00,  'sim' => 'B1',
                          'unit' => [['Mitsubishi', 'Canter FE 74 HD'], ['Hino', 'Dutro 130 HD'], ['Isuzu', 'Elf NMR 81']]],
            'fuso'    => ['bbm' => 'solar',  'kapasitas' => 8000,  'harga' => 780000000.00,  'sim' => 'B2',
                          'unit' => [['Mitsubishi', 'Fuso Fighter FN 62'], ['Hino', 'Ranger FG 260']]],
            'tronton' => ['bbm' => 'solar',  'kapasitas' => 15000, 'harga' => 1150000000.00, 'sim' => 'B2',
                          'unit' => [['Hino', 'Ranger FL 260'], ['Isuzu', 'Giga FVR 34']]],
            'wingbox' => ['bbm' => 'solar',  'kapasitas' => 12000, 'harga' => 950000000.00,  'sim' => 'B2',
                          'unit' => [['Mitsubishi', 'Fuso Fighter Wingbox'], ['Hino', 'Ranger Wingbox']]],
        ];

        $distribusi = array_merge(
            array_fill(0, 6,  'pickup'),
            array_fill(0, 8,  'engkel'),
            array_fill(0, 16, 'cdd'),
            array_fill(0, 8,  'fuso'),
            array_fill(0, 6,  'tronton'),
            array_fill(0, 6,  'wingbox'),
        );

        $warna = ['Putih', 'Kuning', 'Silver', 'Biru', 'Hitam'];

        $namaSupir = [
            'Ahmad Fauzi', 'Slamet Riyadi', 'Dedi Kurniawan', 'Eko Prasetyo', 'Wahyu Hidayat',
            'Andi Saputra', 'Bambang Sutrisno', 'Tono Sumarno', 'Asep Sunandar', 'Ujang Suryana',
            'Dadang Hermawan', 'Yanto Wibowo', 'Sugeng Waluyo', 'Muhammad Ridwan', 'Iwan Setiawan',
            'Hermansyah Putra', 'Taufik Hidayat', 'Samsul Bahri', 'Zainal Abidin', 'Rahmat Hakim',
            'Fajar Nugroho', 'Galih Pratama', 'Hasan Basri', 'Imam Syafii', 'Jumadi Santoso',
            'Karno Widodo', 'Lukman Hakim', 'Mulyono Saputro', 'Nur Kholis', 'Oman Suparman',
            'Purwanto Adi', 'Rusdi Hamdani', 'Sardi Prayitno', 'Sutarjo Mulyadi', 'Umar Dani',
            'Firman Maulana', 'Gilang Ramadhan', 'Hadi Susanto', 'Irfan Saputra', 'Joko Susilo',
            'Kusnadi Rahman', 'Lilik Hariyanto', 'Maman Sudirman', 'Nanang Kosim', 'Rizal Fahmi',
            'Saiful Anwar', 'Teguh Santoso', 'Usman Hadi', 'Wawan Gunawan', 'Yusuf Maulana',
        ];

        $armadaRows = [];
        $supirRows  = [];

        foreach ($distribusi as $i => $kodeTipe) {
            $urut = $i + 1;
            $spek = $tipe[$kodeTipe];
            [$merk, $model] = $spek['unit'][$i % count($spek['unit'])];

            $tahun       = 2017 + ($i % 8);
            $idArmada    = sprintf('a2000001-0000-4000-8000-%012d', $urut);
            $tanggalBeli = sprintf('%d-%02d-%02d', $tahun, ($i % 12) + 1, ($i % 28) + 1);

            $armadaRows[] = [
                'id_armada'           => $idArmada,
                'id_perusahaan'       => self::ID_PERUSAHAAN,
                'id_jenis_kendaraan'  => $idJenis[$kodeTipe],
                'id_vendor'           => null,
                'nopol'               => sprintf('B 9%03d TMN', $urut),
                'merk'                => $merk,
                'model'               => $model,
                'tahun'               => $tahun,
                'kepemilikan'         => 'internal',
                'status'              => 'tersedia',
                'aktif'               => 1,
                'nomor_rangka'        => sprintf('MHKTMN26%08d', $urut),
                'nomor_mesin'         => sprintf('4D34TMN%06d', $urut),
                'warna'               => $warna[$i % count($warna)],
                'jenis_bahan_bakar'   => $spek['bbm'],
                'kapasitas_muatan_kg' => $spek['kapasitas'],
                'tanggal_beli'        => $tanggalBeli,
                'harga_beli'          => $spek['harga'],
                'kondisi_beli'        => $i % 5 === 0 ? 'bekas' : 'baru',
                'url_foto'            => null,
                'keterangan'          => null,
                'dibuat_pada'         => $now,
                'dibuat_oleh'         => null,
            ];

            $supirRows[] = [
                'id_supir'           => sprintf('d2000001-0000-4000-8000-%012d', $urut),
                'id_pengguna'        => null,
                'id_armada_default'  => $idArmada,
                'id_perusahaan'      => self::ID_PERUSAHAAN,
                'nama'               => $namaSupir[$i],
                'no_sim'             => sprintf('3201%08d', 24000000 + $urut),
                'jenis_sim'          => $spek['sim'],
                'tgl_kadaluarsa_sim' => sprintf('%d-%02d-%02d', 2027 + ($i % 4), (($i * 3) % 12) + 1, ($i % 28) + 1),
                'telepon'            => sprintf('0812-5550-%04d', $urut),
                'status'             => 'aktif',
                'foto'               => null,
                'dibuat_pada'        => $now,
                'dibuat_oleh'        => null,
            ];
        }

        DB::table('armada')->upsert(
            $armadaRows,
            ['id_armada'],
            ['id_jenis_kendaraan', 'nopol', 'merk', 'model', 'tahun', 'kepemilikan', 'aktif',
             'nomor_rangka', 'nomor_mesin', 'warna', 'jenis_bahan_bakar', 'kapasitas_muatan_kg',
             'tanggal_beli', 'harga_beli', 'kondisi_beli']
        );

        DB::table('supir')->upsert(
            $supirRows,
            ['id_supir'],
            ['id_armada_default', 'nama', 'no_sim', 'jenis_sim', 'tgl_kadaluarsa_sim', 'telepon', 'status']
        );
    }
}
