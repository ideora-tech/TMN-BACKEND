<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class KodeOtomatis
{
    public const DEFAULT = [
        'proyek'    => ['prefix' => 'PRJ', 'panjang_digit' => 4, 'reset' => 'tahunan'],
        'rute'      => ['prefix' => 'RT',  'panjang_digit' => 4, 'reset' => 'tidak'],
        'penawaran' => ['prefix' => 'PNW', 'panjang_digit' => 4, 'reset' => 'bulanan'],
        'permintaan_vendor' => ['prefix' => 'PMV', 'panjang_digit' => 4, 'reset' => 'bulanan'],
        'jabatan'   => ['prefix' => 'JBT', 'panjang_digit' => 4, 'reset' => 'tidak'],
        'departemen' => ['prefix' => 'DEP', 'panjang_digit' => 4, 'reset' => 'tidak'],
        'jenis_kendaraan'  => ['prefix' => 'JNS', 'panjang_digit' => 4, 'reset' => 'tidak'],
        'klien'            => ['prefix' => 'KLN', 'panjang_digit' => 4, 'reset' => 'tidak'],
        'lokasi_kantor'    => ['prefix' => 'LOK', 'panjang_digit' => 4, 'reset' => 'tidak'],
        'tipe_pembayaran'  => ['prefix' => 'TPB', 'panjang_digit' => 4, 'reset' => 'tidak'],
        'vendor'           => ['prefix' => 'VDR', 'panjang_digit' => 4, 'reset' => 'tidak'],
        'sparepart'        => ['prefix' => 'SPR', 'panjang_digit' => 4, 'reset' => 'tidak'],
        'barang'               => ['prefix' => 'BRG', 'panjang_digit' => 4, 'reset' => 'tidak'],
        'permintaan_pembelian' => ['prefix' => 'PR',  'panjang_digit' => 4, 'reset' => 'bulanan'],
        'purchase_order'       => ['prefix' => 'PO',  'panjang_digit' => 4, 'reset' => 'bulanan'],
        'nik_supir'            => ['prefix' => 'DRV', 'panjang_digit' => 4, 'reset' => 'tidak'],
        'kontrak_vendor'       => ['prefix' => 'PKS/VM/SLI', 'panjang_digit' => 3, 'reset' => 'tahunan', 'format' => 'surat'],
        'kontrak_karyawan'     => ['prefix' => '{JENIS}/HRD/SLI', 'panjang_digit' => 3, 'reset' => 'tahunan', 'format' => 'surat', 'placeholder' => ['JENIS']],
    ];

    /**
     * Placeholder yang boleh dipakai di prefix entitas ini, mis. {JENIS} pada kontrak
     * karyawan diganti jenis kontraknya (PKWT, PKWTT, MAGANG, ...) saat nomor diterbitkan.
     *
     * @return list<string>
     */
    public static function placeholder(string $entitas): array
    {
        return self::DEFAULT[$entitas]['placeholder'] ?? [];
    }

    /**
     * 'standar' → PREFIX-PERIODE-URUT (mis. PO-202611-0001).
     * 'surat'   → URUT/PREFIX/BULAN/TAHUN ala nomor surat (mis. 010/PKS/VM/SLI/09/2026);
     *             bulan & tahun selalu tanggal terbit, terlepas dari periode reset.
     */
    public static function format(string $entitas): string
    {
        return self::DEFAULT[$entitas]['format'] ?? 'standar';
    }

    /** @param array<string, string> $isian nilai placeholder, mis. ['JENIS' => 'pkwt'] */
    public static function berikutnya(string $idPerusahaan, string $entitas, array $isian = []): string
    {
        $aturan = DB::table('pengaturan_kode')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('entitas', $entitas)
            ->whereNull('dihapus_pada')
            ->first();

        $prefix  = $aturan->prefix ?? self::DEFAULT[$entitas]['prefix'] ?? strtoupper(substr($entitas, 0, 3));
        $digit   = (int) ($aturan->panjang_digit ?? self::DEFAULT[$entitas]['panjang_digit'] ?? 4);
        $reset   = $aturan->reset ?? self::DEFAULT[$entitas]['reset'] ?? 'tidak';
        foreach (self::placeholder($entitas) as $nama) {
            $prefix = str_replace('{' . $nama . '}', mb_strtoupper(trim((string) ($isian[$nama] ?? ''))), $prefix);
        }
        $periode = match ($reset) {
            'tahunan' => now()->format('Y'),
            'bulanan' => now()->format('Ym'),
            default   => '',
        };

        return DB::transaction(function () use ($idPerusahaan, $entitas, $periode, $prefix, $digit) {
            $baris = DB::table('kode_sequence')
                ->where('id_perusahaan', $idPerusahaan)
                ->where('entitas', $entitas)
                ->where('periode', $periode)
                ->lockForUpdate()
                ->first();

            if ($baris === null) {
                DB::table('kode_sequence')->insert([
                    'id_sequence'    => (string) Str::uuid(),
                    'id_perusahaan'  => $idPerusahaan,
                    'entitas'        => $entitas,
                    'periode'        => $periode,
                    'nilai_terakhir' => 1,
                ]);
                $nilai = 1;
            } else {
                $nilai = (int) $baris->nilai_terakhir + 1;
                DB::table('kode_sequence')->where('id_sequence', $baris->id_sequence)
                    ->update(['nilai_terakhir' => $nilai]);
            }

            $urut = str_pad((string) $nilai, $digit, '0', STR_PAD_LEFT);
            if (self::format($entitas) === 'surat') {
                return "{$urut}/{$prefix}/" . now()->format('m/Y');
            }
            return $periode === '' ? "{$prefix}-{$urut}" : "{$prefix}-{$periode}-{$urut}";
        });
    }
}
