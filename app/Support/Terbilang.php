<?php

declare(strict_types=1);

namespace App\Support;

class Terbilang
{
    private const SATUAN = ['', 'Satu', 'Dua', 'Tiga', 'Empat', 'Lima', 'Enam', 'Tujuh', 'Delapan', 'Sembilan', 'Sepuluh', 'Sebelas'];

    public static function dariAngka(int $n): string
    {
        if ($n < 0) {
            return 'Minus ' . self::dariAngka(abs($n));
        }
        if ($n === 0) {
            return 'Nol';
        }

        return self::konversi($n);
    }

    public static function rupiah(float $nominal): string
    {
        return self::dariAngka((int) round($nominal)) . ' Rupiah';
    }

    private static function konversi(int $n): string
    {
        if ($n < 12) {
            return self::SATUAN[$n];
        }
        if ($n < 20) {
            return self::gabung([self::konversi($n - 10), 'Belas']);
        }
        if ($n < 100) {
            return self::gabung([self::konversi(intdiv($n, 10)), 'Puluh', self::konversi($n % 10)]);
        }
        if ($n < 200) {
            return self::gabung(['Seratus', self::konversi($n - 100)]);
        }
        if ($n < 1000) {
            return self::gabung([self::konversi(intdiv($n, 100)), 'Ratus', self::konversi($n % 100)]);
        }
        if ($n < 2000) {
            return self::gabung(['Seribu', self::konversi($n - 1000)]);
        }
        if ($n < 1000000) {
            return self::gabung([self::konversi(intdiv($n, 1000)), 'Ribu', self::konversi($n % 1000)]);
        }
        if ($n < 1000000000) {
            return self::gabung([self::konversi(intdiv($n, 1000000)), 'Juta', self::konversi($n % 1000000)]);
        }
        if ($n < 1000000000000) {
            return self::gabung([self::konversi(intdiv($n, 1000000000)), 'Miliar', self::konversi($n % 1000000000)]);
        }

        return self::gabung([self::konversi(intdiv($n, 1000000000000)), 'Triliun', self::konversi($n % 1000000000000)]);
    }

    private static function gabung(array $bagian): string
    {
        return implode(' ', array_filter($bagian, fn (string $b) => $b !== ''));
    }
}
