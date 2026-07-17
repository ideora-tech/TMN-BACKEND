<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

// php artisan db:seed --class=KlienLogistikSeeder
class KlienLogistikSeeder extends Seeder
{
    private const ID_PERUSAHAAN = 'b8f3c1a2-0000-4000-8000-000000000001';

    public function run(): void
    {
        $now = now();

        $pics = [
            'Budi Hartono', 'Siti Rahayu', 'Agus Wijaya', 'Dewi Lestari', 'Rudi Santoso',
            'Rina Marlina', 'Joko Prasetyo', 'Maya Sari', 'Hendra Gunawan', 'Fitri Handayani',
        ];

        // [nama_klien, slug email, kota, kode area telepon]
        $perusahaanLogistik = [
            ['PT Tiki Jalur Nugraha Ekakurir (JNE)',           'jne',            'Jakarta Barat',   '021'],
            ['PT Citra Van Titipan Kilat (TIKI)',              'tiki',           'Jakarta Pusat',   '021'],
            ['PT Pos Indonesia (Persero)',                     'posindonesia',   'Bandung',         '022'],
            ['PT Global Jet Express (J&T Express)',            'jtexpress',      'Jakarta Barat',   '021'],
            ['PT SiCepat Ekspres Indonesia',                   'sicepat',        'Jakarta Pusat',   '021'],
            ['PT Tri Adi Bersama (AnterAja)',                  'anteraja',       'Jakarta Selatan', '021'],
            ['PT Andiarta Muzizat (Ninja Xpress)',             'ninjaxpress',    'Jakarta Selatan', '021'],
            ['PT Wahana Prestasi Logistik',                    'wahana',         'Jakarta Selatan', '021'],
            ['PT Lion Express (Lion Parcel)',                  'lionparcel',     'Jakarta Pusat',   '021'],
            ['PT Nusantara Ekspres Kilat (ID Express)',        'idexpress',      'Jakarta Utara',   '021'],
            ['PT Satria Antaran Prima Tbk (SAP Express)',      'sapexpress',     'Jakarta Timur',   '021'],
            ['PT Repex Wahana (RPX Holding)',                  'rpx',            'Jakarta Selatan', '021'],
            ['PT Pandu Siwi Sentosa (Pandu Logistics)',        'pandulogistics', 'Jakarta Timur',   '021'],
            ['PT Indah Logistik (Indah Cargo)',                'indahcargo',     'Pekanbaru',       '0761'],
            ['PT Dakota Buana Semesta (Dakota Cargo)',         'dakotacargo',    'Jakarta Barat',   '021'],
            ['PT Herona Express',                              'herona',         'Jakarta Pusat',   '021'],
            ['PT Kereta Api Logistik (KAI Logistik)',          'kailogistik',    'Jakarta Pusat',   '021'],
            ['PT Iron Bird (Iron Bird Logistics)',             'ironbird',       'Jakarta Selatan', '021'],
            ['PT Puninar Jaya (Puninar Logistics)',            'puninar',        'Jakarta Timur',   '021'],
            ['PT Cipta Krida Bahari (CKB Logistics)',          'ckb',            'Jakarta Timur',   '021'],
            ['PT Kamadjaja Logistics',                         'kamadjaja',      'Surabaya',        '031'],
            ['PT Seino Indomobil Logistics',                   'seinoindomobil', 'Jakarta Timur',   '021'],
            ['PT Linfox Logistics Indonesia',                  'linfox',         'Bekasi',          '021'],
            ['PT DHL Supply Chain Indonesia',                  'dhlsupply',      'Jakarta Selatan', '021'],
            ['PT CEVA Logistik Indonesia',                     'cevalogistics',  'Jakarta Selatan', '021'],
            ['PT Yusen Logistics Indonesia',                   'yusen',          'Jakarta Utara',   '021'],
            ['PT Kintetsu World Express Indonesia',            'kwe',            'Tangerang',       '021'],
            ['PT Schenker Petrolog Utama (DB Schenker)',       'dbschenker',     'Jakarta Timur',   '021'],
            ['PT Kuehne Nagel Indonesia',                      'kuehnenagel',    'Jakarta Selatan', '021'],
            ['PT Samudera Indonesia Tbk',                      'samudera',       'Jakarta Pusat',   '021'],
            ['PT Meratus Line',                                'meratus',        'Surabaya',        '031'],
            ['PT Tanto Intim Line',                            'tantonet',       'Surabaya',        '031'],
            ['PT Salam Pacific Indonesia Lines (SPIL)',        'spil',           'Surabaya',        '031'],
            ['PT Temas Tbk (Temas Line)',                      'temasline',      'Jakarta Utara',   '021'],
            ['PT Bhanda Ghara Reksa (BGR Logistik)',           'bgrlogistik',    'Jakarta Pusat',   '021'],
            ['PT Dunia Express Transindo (Dunex)',             'dunex',          'Jakarta Utara',   '021'],
            ['PT Lookman Djaja Logistics',                     'lookmandjaja',   'Jakarta Timur',   '021'],
            ['PT Siba Surya',                                  'sibasurya',      'Semarang',        '024'],
            ['PT Pancaran Darat Transport',                    'pancarangroup',  'Jakarta Utara',   '021'],
            ['PT MGM Bosco Logistics',                         'mgmbosco',       'Jakarta Utara',   '021'],
            ['PT First Logistics',                             'firstlogistics', 'Jakarta Selatan', '021'],
            ['PT Sentral Cargo Indonesia',                     'sentralcargo',   'Jakarta Barat',   '021'],
            ['PT Rosalia Express',                             'rosaliaexpress', 'Karanganyar',     '0271'],
            ['PT Eka Sari Lorena (ESL Express)',               'eslexpress',     'Jakarta Timur',   '021'],
            ['PT Nusantara Card Semesta (NCS)',                'ncs',            'Jakarta Barat',   '021'],
            ['PT Priority Cargo and Package (PCP Express)',    'pcpexpress',     'Jakarta Pusat',   '021'],
            ['PT Paxel Algorita Unggul (Paxel)',               'paxel',          'Jakarta Selatan', '021'],
            ['PT Shipper Logistik Indonesia',                  'shipper',        'Jakarta Barat',   '021'],
            ['PT Waresix Teknologi Indonesia',                 'waresix',        'Jakarta Selatan', '021'],
            ['PT Kargo Teknologi Indonesia (Kargo Tech)',      'kargotech',      'Jakarta Selatan', '021'],
        ];

        $jalan = [
            'Jl. Raya Industri', 'Jl. Logistik Utama', 'Jl. Pergudangan Sentosa', 'Jl. Kawasan Niaga',
            'Jl. Terminal Kargo', 'Jl. Pelabuhan Raya', 'Jl. Distribusi Utama', 'Jl. Gudang Selatan',
        ];

        $rows = [];
        foreach ($perusahaanLogistik as $i => [$nama, $slug, $kota, $kodeArea]) {
            $urut   = $i + 1;
            $rows[] = [
                'id_klien'      => sprintf('c1000001-0000-4000-8000-%012d', $urut),
                'id_perusahaan' => self::ID_PERUSAHAAN,
                'kode_klien'    => sprintf('KLI-%03d', $urut),
                'nama_klien'    => $nama,
                'email'         => 'info@' . $slug . '.co.id',
                'telepon'       => sprintf('%s-555-%04d', $kodeArea, 1000 + $urut),
                'alamat'        => sprintf('%s No. %d, %s', $jalan[$i % count($jalan)], ($i % 45) + 1, $kota),
                'kontak_pic'    => $pics[$i % count($pics)],
                'aktif'         => 1,
                'dibuat_pada'   => $now,
                'dibuat_oleh'   => null,
            ];
        }

        DB::table('klien')->upsert(
            $rows,
            ['id_klien'],
            ['kode_klien', 'nama_klien', 'email', 'telepon', 'alamat', 'kontak_pic', 'aktif']
        );
    }
}
