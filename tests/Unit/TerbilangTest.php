<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Terbilang;
use PHPUnit\Framework\TestCase;

class TerbilangTest extends TestCase
{
    public function test_nol(): void
    {
        $this->assertSame('Nol', Terbilang::dariAngka(0));
    }

    public function test_angka_dasar(): void
    {
        $this->assertSame('Sebelas', Terbilang::dariAngka(11));
        $this->assertSame('Dua Belas', Terbilang::dariAngka(12));
        $this->assertSame('Dua Puluh Satu', Terbilang::dariAngka(21));
        $this->assertSame('Seratus', Terbilang::dariAngka(100));
        $this->assertSame('Seratus Satu', Terbilang::dariAngka(101));
        $this->assertSame('Seribu', Terbilang::dariAngka(1000));
        $this->assertSame('Seribu Satu', Terbilang::dariAngka(1001));
    }

    public function test_ribuan_dan_jutaan_tanpa_sisa(): void
    {
        $this->assertSame('Sepuluh Ribu', Terbilang::dariAngka(10000));
        $this->assertSame('Satu Juta', Terbilang::dariAngka(1000000));
    }

    public function test_angka_besar_dari_contoh_invoice(): void
    {
        $this->assertSame(
            'Enam Ratus Tiga Puluh Satu Juta Delapan Ratus Tiga Puluh Satu Ribu Lima Ratus Dua Puluh Tujuh',
            Terbilang::dariAngka(631831527),
        );
    }

    public function test_rupiah_membulatkan_dan_menambah_akhiran(): void
    {
        $this->assertSame('Empat Juta Rupiah', Terbilang::rupiah(4000000));
        $this->assertSame('Satu Juta Enam Ratus Enam Puluh Lima Ribu Rupiah', Terbilang::rupiah(1665000.0));
    }

    public function test_minus(): void
    {
        $this->assertSame('Minus Seratus', Terbilang::dariAngka(-100));
    }
}
