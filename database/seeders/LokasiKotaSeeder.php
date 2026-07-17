<?php
declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seed master lokasi dengan 98 kotamadya resmi Indonesia untuk semua
 * perusahaan aktif. Idempoten per (id_perusahaan, nama_lokasi) — kota yang
 * sudah ada (termasuk buatan manual dengan nama sama) di-skip.
 *
 * Jalankan: php artisan db:seed --class=LokasiKotaSeeder --force
 */
class LokasiKotaSeeder extends Seeder
{
    /** @var string[] 98 kotamadya (termasuk 5 kota administrasi DKI Jakarta) */
    private const KOTA = [
        // ── Sumatera ──────────────────────────────────────────────────────
        // Aceh
        'Banda Aceh', 'Langsa', 'Lhokseumawe', 'Sabang', 'Subulussalam',
        // Sumatera Utara
        'Medan', 'Binjai', 'Gunungsitoli', 'Padangsidimpuan', 'Pematangsiantar',
        'Sibolga', 'Tanjungbalai', 'Tebing Tinggi',
        // Sumatera Barat
        'Padang', 'Bukittinggi', 'Padang Panjang', 'Pariaman', 'Payakumbuh',
        'Sawahlunto', 'Solok',
        // Riau
        'Pekanbaru', 'Dumai',
        // Kepulauan Riau
        'Batam', 'Tanjungpinang',
        // Jambi
        'Jambi', 'Sungai Penuh',
        // Sumatera Selatan
        'Palembang', 'Lubuklinggau', 'Pagar Alam', 'Prabumulih',
        // Kep. Bangka Belitung
        'Pangkalpinang',
        // Bengkulu
        'Bengkulu',
        // Lampung
        'Bandar Lampung', 'Metro',

        // ── Jawa ──────────────────────────────────────────────────────────
        // DKI Jakarta (kota administrasi)
        'Jakarta Pusat', 'Jakarta Utara', 'Jakarta Barat', 'Jakarta Selatan', 'Jakarta Timur',
        // Jawa Barat
        'Bandung', 'Banjar', 'Bekasi', 'Bogor', 'Cimahi', 'Cirebon', 'Depok',
        'Sukabumi', 'Tasikmalaya',
        // Banten
        'Serang', 'Cilegon', 'Tangerang', 'Tangerang Selatan',
        // Jawa Tengah
        'Semarang', 'Magelang', 'Pekalongan', 'Salatiga', 'Surakarta', 'Tegal',
        // DI Yogyakarta
        'Yogyakarta',
        // Jawa Timur
        'Surabaya', 'Batu', 'Blitar', 'Kediri', 'Madiun', 'Malang', 'Mojokerto',
        'Pasuruan', 'Probolinggo',

        // ── Kalimantan ────────────────────────────────────────────────────
        'Pontianak', 'Singkawang',           // Kalimantan Barat
        'Palangka Raya',                     // Kalimantan Tengah
        'Banjarmasin', 'Banjarbaru',         // Kalimantan Selatan
        'Samarinda', 'Balikpapan', 'Bontang', // Kalimantan Timur
        'Tarakan',                           // Kalimantan Utara

        // ── Sulawesi ──────────────────────────────────────────────────────
        'Manado', 'Bitung', 'Kotamobagu', 'Tomohon', // Sulawesi Utara
        'Gorontalo',                                 // Gorontalo
        'Palu',                                      // Sulawesi Tengah
        'Makassar', 'Palopo', 'Parepare',            // Sulawesi Selatan
        'Kendari', 'Baubau',                         // Sulawesi Tenggara

        // ── Bali & Nusa Tenggara ──────────────────────────────────────────
        'Denpasar',         // Bali
        'Mataram', 'Bima',  // Nusa Tenggara Barat
        'Kupang',           // Nusa Tenggara Timur

        // ── Maluku & Papua ────────────────────────────────────────────────
        'Ambon', 'Tual',                 // Maluku
        'Ternate', 'Tidore Kepulauan',   // Maluku Utara
        'Jayapura',                      // Papua
        'Sorong',                        // Papua Barat Daya
    ];

    public function run(): void
    {
        $now = now();

        $perusahaanIds = DB::table('perusahaan')
            ->whereNull('dihapus_pada')
            ->pluck('id_perusahaan');

        foreach ($perusahaanIds as $idPerusahaan) {
            $sudahAda = DB::table('lokasi')
                ->whereNull('dihapus_pada')
                ->where('id_perusahaan', $idPerusahaan)
                ->pluck('nama_lokasi')
                ->all();

            $baris = [];
            foreach (self::KOTA as $kota) {
                if (in_array($kota, $sudahAda, true)) {
                    continue;
                }
                $baris[] = [
                    'id_lokasi'     => (string) Str::uuid(),
                    'id_perusahaan' => $idPerusahaan,
                    'nama_lokasi'   => $kota,
                    'alamat'        => null,
                    'kota'          => $kota,
                    'aktif'         => 1,
                    'dibuat_pada'   => $now,
                    'dibuat_oleh'   => null,
                ];
            }

            if ($baris !== []) {
                DB::table('lokasi')->insert($baris);
            }
        }
    }
}
