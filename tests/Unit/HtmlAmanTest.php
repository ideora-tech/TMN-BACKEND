<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\HtmlAman;
use PHPUnit\Framework\TestCase;

class HtmlAmanTest extends TestCase
{
    public function test_format_dasar_editor_dipertahankan(): void
    {
        $html = '<h2>Syarat</h2><p>Bayar <strong>dimuka</strong> dan <em>tepat waktu</em>, <s>tanpa</s> denda.</p><ul><li><p>Satu</p></li><li><p>Dua</p></li></ul><blockquote><p>Kutipan</p></blockquote><hr><pre><code>kode</code></pre>';

        $this->assertSame($html, HtmlAman::bersihkan($html));
    }

    public function test_skrip_gaya_frame_dan_atribut_berbahaya_dibuang(): void
    {
        $masuk = '<p onclick="alert(1)" style="color:red">Halo</p><script>alert(1)</script><style>p{}</style><iframe src="x"></iframe>';

        $this->assertSame('<p>Halo</p>', HtmlAman::bersihkan($masuk));
    }

    public function test_skrip_di_awal_tidak_lolos(): void
    {
        $this->assertSame('<p>setelah</p>', HtmlAman::bersihkan('<script>alert(1)</script><p>setelah</p>'));
    }

    public function test_tag_tak_dikenal_dilepas_dan_isinya_dipertahankan(): void
    {
        $masuk = '<div><span class="x">teks</span> <a href="javascript:alert(1)">tautan</a></div>';

        $this->assertSame('teks tautan', HtmlAman::bersihkan($masuk));
    }

    public function test_teks_biasa_tidak_diubah(): void
    {
        $teks = "Bayar 50% < 5 hari & selesai\nBaris dua";

        $this->assertSame($teks, HtmlAman::bersihkan($teks));
    }

    public function test_unicode_dan_entitas_dipertahankan(): void
    {
        $html = '<p>Café ☕ — ok &amp; &lt;b&gt;</p>';

        $this->assertSame($html, HtmlAman::bersihkan($html));
    }

    public function test_html_rusak_diperbaiki(): void
    {
        $this->assertSame('<p>tidak <strong>ditutup</strong></p>', HtmlAman::bersihkan('<p>tidak <strong>ditutup'));
    }

    public function test_konten_kosong_menjadi_null(): void
    {
        foreach ([null, '', '   ', '<p></p>', '<p> </p>', '<p><br></p>', '<p>&nbsp;</p>'] as $kosong) {
            $this->assertNull(HtmlAman::bersihkan($kosong), var_export($kosong, true));
        }
    }

    public function test_garis_pemisah_saja_bukan_kosong(): void
    {
        $this->assertSame('<hr>', HtmlAman::bersihkan('<hr>'));
    }

    public function test_untuk_tampilan_teks_biasa_jadi_baris_baru_dan_aman(): void
    {
        $this->assertSame("A &amp; B<br />\nBaris dua", HtmlAman::untukTampilan("A & B\nBaris dua"));
    }

    public function test_untuk_tampilan_html_disaring_lagi(): void
    {
        $this->assertSame('<p>Ok</p>', HtmlAman::untukTampilan('<p>Ok</p><script>x</script>'));
    }

    public function test_untuk_tampilan_kosong_menghasilkan_string_kosong(): void
    {
        $this->assertSame('', HtmlAman::untukTampilan(null));
        $this->assertSame('', HtmlAman::untukTampilan('<p></p>'));
    }
}
