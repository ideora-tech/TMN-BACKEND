<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

// php artisan db:seed --class=VendorDummySeeder
class VendorDummySeeder extends Seeder
{
    private const ID_PERUSAHAAN = 'b8f3c1a2-0000-4000-8000-000000000001';

    public function run(): void
    {
        $now = now();

        // ── Vendor ───────────────────────────────────────────────────────
        $idVendor = [
            'sinar'   => 'd1000001-0000-4000-8000-000000000001',
            'mitra'   => 'd1000001-0000-4000-8000-000000000002',
            'cakra'   => 'd1000001-0000-4000-8000-000000000003',
            'nusantara' => 'd1000001-0000-4000-8000-000000000004',
            'bahari'  => 'd1000001-0000-4000-8000-000000000005',
        ];

        $vendorRows = [
            [
                'id_vendor' => $idVendor['sinar'], 'kode_vendor' => 'VDR-001',
                'nama_vendor' => 'PT Sinar Jaya Trans', 'jenis_vendor' => 'Transporter',
                'pic_nama' => 'Hendra Wijaya', 'telepon' => '081234560001', 'email' => 'ops@sinarjayatrans.co.id',
                'alamat' => 'Jl. Raya Cakung Cilincing KM 3, Jakarta Utara',
                'npwp' => '01.234.567.8-045.000', 'tanggal_bergabung' => '2024-03-10', 'aktif' => 1,
            ],
            [
                'id_vendor' => $idVendor['mitra'], 'kode_vendor' => 'VDR-002',
                'nama_vendor' => 'CV Mitra Angkut Sejahtera', 'jenis_vendor' => 'Transporter',
                'pic_nama' => 'Siti Rahmawati', 'telepon' => '081234560002', 'email' => 'admin@mitraangkut.id',
                'alamat' => 'Jl. Margomulyo Indah No. 18, Surabaya',
                'npwp' => '02.345.678.9-604.000', 'tanggal_bergabung' => '2024-08-22', 'aktif' => 1,
            ],
            [
                'id_vendor' => $idVendor['cakra'], 'kode_vendor' => 'VDR-003',
                'nama_vendor' => 'PT Cakra Logistik Indonesia', 'jenis_vendor' => 'Freight Forwarder',
                'pic_nama' => 'Bambang Prasetyo', 'telepon' => '081234560003', 'email' => 'cs@cakralogistik.co.id',
                'alamat' => 'Kawasan Industri MM2100 Blok C-7, Cibitung, Bekasi',
                'npwp' => '03.456.789.0-413.000', 'tanggal_bergabung' => '2025-01-15', 'aktif' => 1,
            ],
            [
                'id_vendor' => $idVendor['nusantara'], 'kode_vendor' => 'VDR-004',
                'nama_vendor' => 'PT Nusantara Ekspres Kargo', 'jenis_vendor' => 'Ekspedisi',
                'pic_nama' => 'Dewi Lestari', 'telepon' => '081234560004', 'email' => 'dewi@nusantarakargo.com',
                'alamat' => 'Jl. Soekarno Hatta No. 212, Bandung',
                'npwp' => '04.567.890.1-424.000', 'tanggal_bergabung' => '2025-06-01', 'aktif' => 1,
            ],
            [
                'id_vendor' => $idVendor['bahari'], 'kode_vendor' => 'VDR-005',
                'nama_vendor' => 'CV Bahari Trans Mandiri', 'jenis_vendor' => 'Transporter',
                'pic_nama' => 'Agus Salim', 'telepon' => '081234560005', 'email' => 'bahari.trans@gmail.com',
                'alamat' => 'Jl. Yos Sudarso No. 88, Tanjung Priok, Jakarta Utara',
                'npwp' => '05.678.901.2-046.000', 'tanggal_bergabung' => '2023-11-05', 'aktif' => 0,
            ],
        ];

        DB::table('vendor')->upsert(
            array_map(fn (array $v) => array_merge($v, [
                'id_perusahaan' => self::ID_PERUSAHAAN,
                'dibuat_pada'   => $now,
            ]), $vendorRows),
            ['id_vendor'],
            ['kode_vendor', 'nama_vendor', 'jenis_vendor', 'pic_nama', 'telepon', 'email', 'alamat', 'npwp', 'tanggal_bergabung', 'aktif']
        );

        // ── Armada vendor ────────────────────────────────────────────────
        $armadaRows = [
            // [id suffix, vendor, nopol, merk, jenis, tahun, kapasitas, stnk, kir]
            ['01', 'sinar', 'B 9101 SJT', 'Hino Dutro 136 HD', 'CDD Box', 2022, '4 ton', $now->copy()->addMonths(8), $now->copy()->addMonths(4)],
            ['02', 'sinar', 'B 9102 SJT', 'Mitsubishi Fuso Fighter', 'Fuso Bak', 2021, '8 ton', $now->copy()->addDays(20), $now->copy()->addMonths(2)],
            ['03', 'sinar', 'B 9103 SJT', 'Hino Ranger FM 260', 'Tronton Wingbox', 2020, '15 ton', $now->copy()->subDays(15), $now->copy()->addMonths(1)],
            ['04', 'mitra', 'L 8201 MAS', 'Isuzu Elf NMR 71', 'CDD Bak', 2023, '4 ton', $now->copy()->addMonths(10), $now->copy()->addMonths(5)],
            ['05', 'mitra', 'L 8202 MAS', 'Isuzu Giga FVR', 'Fuso Box', 2022, '8 ton', $now->copy()->addMonths(6), $now->copy()->subDays(10)],
            ['06', 'cakra', 'B 9301 CLI', 'Mitsubishi Fuso Tractor Head', 'Trailer 20 ft', 2021, '20 ton', $now->copy()->addMonths(9), $now->copy()->addMonths(3)],
            ['07', 'cakra', 'B 9302 CLI', 'UD Trucks Quester', 'Trailer 40 ft', 2020, '30 ton', $now->copy()->addMonths(5), $now->copy()->addDays(25)],
            ['08', 'cakra', 'B 9303 CLI', 'Hino Dutro 110 SDL', 'CDE Box', 2024, '2 ton', $now->copy()->addMonths(11), $now->copy()->addMonths(7)],
            ['09', 'nusantara', 'D 8401 NEK', 'Daihatsu Gran Max BV', 'Blind Van', 2023, '1 ton', $now->copy()->addMonths(7), $now->copy()->addMonths(6)],
            ['10', 'nusantara', 'D 8402 NEK', 'Isuzu Elf NLR 55', 'Engkel Box', 2022, '2,2 ton', $now->copy()->addMonths(4), $now->copy()->addMonths(2)],
            ['11', 'bahari', 'B 9501 BTM', 'Hino Ranger FG 235', 'Fuso Bak', 2019, '8 ton', $now->copy()->subMonths(2), $now->copy()->subMonths(1)],
        ];

        DB::table('armada_vendor')->upsert(
            array_map(fn (array $a) => [
                'id_armada_vendor'  => 'd2000001-0000-4000-8000-0000000000' . $a[0],
                'id_vendor'         => $idVendor[$a[1]],
                'nopol'             => $a[2],
                'merk'              => $a[3],
                'jenis'             => $a[4],
                'tahun'             => $a[5],
                'kapasitas'         => $a[6],
                'masa_berlaku_stnk' => $a[7]->toDateString(),
                'masa_berlaku_kir'  => $a[8]->toDateString(),
                'aktif'             => 1,
                'dibuat_pada'       => $now,
            ], $armadaRows),
            ['id_armada_vendor'],
            ['nopol', 'merk', 'jenis', 'tahun', 'kapasitas', 'masa_berlaku_stnk', 'masa_berlaku_kir', 'aktif']
        );

        // ── Supir vendor ─────────────────────────────────────────────────
        $supirRows = [
            ['01', 'sinar', 'Joko Susilo', '082111110001', 'SIM-B2-1101-0001', $now->copy()->addMonths(14)],
            ['02', 'sinar', 'Rudi Hartono', '082111110002', 'SIM-B2-1101-0002', $now->copy()->addDays(21)],
            ['03', 'sinar', 'Wawan Kurniawan', '082111110003', 'SIM-B1-1101-0003', $now->copy()->subDays(30)],
            ['04', 'mitra', 'Slamet Riyadi', '082111110004', 'SIM-B2-3578-0004', $now->copy()->addMonths(9)],
            ['05', 'mitra', 'Teguh Santoso', '082111110005', 'SIM-B1-3578-0005', $now->copy()->addMonths(3)],
            ['06', 'cakra', 'Yusuf Maulana', '082111110006', 'SIM-B2-3216-0006', $now->copy()->addMonths(18)],
            ['07', 'cakra', 'Andre Gunawan', '082111110007', 'SIM-B2-3216-0007', $now->copy()->addMonths(6)],
            ['08', 'nusantara', 'Firman Hidayat', '082111110008', 'SIM-B1-1050-0008', $now->copy()->addMonths(11)],
            ['09', 'bahari', 'Samsul Arifin', '082111110009', 'SIM-B2-1072-0009', $now->copy()->subMonths(3)],
        ];

        DB::table('supir_vendor')->upsert(
            array_map(fn (array $s) => [
                'id_supir_vendor'  => 'd3000001-0000-4000-8000-0000000000' . $s[0],
                'id_vendor'        => $idVendor[$s[1]],
                'nama'             => $s[2],
                'telepon'          => $s[3],
                'no_sim'           => $s[4],
                'masa_berlaku_sim' => $s[5]->toDateString(),
                'aktif'            => 1,
                'dibuat_pada'      => $now,
            ], $supirRows),
            ['id_supir_vendor'],
            ['nama', 'telepon', 'no_sim', 'masa_berlaku_sim', 'aktif']
        );

        // ── Kontrak vendor ───────────────────────────────────────────────
        $kontrakRows = [
            ['01', 'sinar', 'KV/2026/001', 'unit_driver', 'Angkutan kontainer Priok - Cikarang', 120000000, 850000, 'per trip', 11, 30, '2026-01-01', '2026-12-31'],
            ['02', 'sinar', 'KV/2026/002', 'unit_only', 'Sewa unit CDD bulanan', 54000000, 9000000, 'per bulan', 11, 14, '2026-03-01', '2026-08-31'],
            ['03', 'mitra', 'KV/2026/003', 'unit_driver', 'Distribusi retail area Jawa Timur', 90000000, 275000, 'per ton', 11, 30, '2026-02-15', '2027-02-14'],
            ['04', 'cakra', 'KV/2026/004', 'full', 'Borongan proyek relokasi gudang', 250000000, null, 'lumpsum', 11, 45, '2026-06-01', '2026-09-30'],
            ['05', 'cakra', 'KV/2026/005', 'unit_driver', 'Trucking trailer ekspor-impor', 180000000, 2250000, 'per trip', 11, 30, '2026-01-10', null],
            ['06', 'nusantara', 'KV/2025/017', 'unit_only', 'Sewa blind van kurir dedicated', 36000000, 6000000, 'per bulan', null, 14, '2025-10-01', '2026-03-31'],
            ['07', 'bahari', 'KV/2025/009', 'unit_driver', 'Angkutan curah pelabuhan', 75000000, 500000, 'per trip', 11, 30, '2025-06-01', '2026-05-31'],
        ];

        DB::table('kontrak_vendor')->upsert(
            array_map(fn (array $k) => [
                'id_kontrak_vendor'      => 'd4000001-0000-4000-8000-0000000000' . $k[0],
                'id_perusahaan'          => self::ID_PERUSAHAAN,
                'id_vendor'              => $idVendor[$k[1]],
                'id_proyek'              => null,
                'nomor_kontrak'          => $k[2],
                'mekanisme'              => $k[3],
                'jenis_layanan'          => $k[4],
                'nilai_kontrak'          => $k[5],
                'rate'                   => $k[6],
                'satuan'                 => $k[7],
                'pajak_persen'           => $k[8],
                'termin_pembayaran_hari' => $k[9],
                'tanggal_mulai'          => $k[10],
                'tanggal_selesai'        => $k[11],
                'status'                 => 'aktif',
                'dibuat_pada'            => $now,
            ], $kontrakRows),
            ['id_kontrak_vendor'],
            ['nomor_kontrak', 'mekanisme', 'jenis_layanan', 'nilai_kontrak', 'rate', 'satuan', 'pajak_persen', 'termin_pembayaran_hari', 'tanggal_mulai', 'tanggal_selesai', 'status']
        );
    }
}
