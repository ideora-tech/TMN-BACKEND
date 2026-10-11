<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use ZipArchive;

trait MemeriksaExcel
{
    protected function selExcelBerupaTeks(string $berkas, int $nomorSheet, string $sel): bool
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($berkas) === true, 'File Excel tidak bisa dibuka');
        $xml = (string) $zip->getFromName("xl/worksheets/sheet{$nomorSheet}.xml");
        $zip->close();

        $this->assertSame(1, preg_match('/<c r="' . preg_quote($sel, '/') . '"([^>]*)>/', $xml, $cocok), "Sel {$sel} tidak ada di sheet {$nomorSheet}");

        return str_contains($cocok[1], 't="s"') || str_contains($cocok[1], 't="inlineStr"');
    }
}
