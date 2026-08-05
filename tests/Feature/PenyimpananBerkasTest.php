<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\PenyimpananBerkas;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PenyimpananBerkasTest extends TestCase
{
    public function test_simpan_mengembalikan_path_relatif_tanpa_http(): void
    {
        Storage::fake('public');

        $path = PenyimpananBerkas::simpan(UploadedFile::fake()->create('kontrak.pdf', 10), 'dokumen');

        $this->assertStringStartsWith('dokumen/', $path);
        $this->assertStringStartsNotWith('http', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_url_menangani_null_legacy_dan_path(): void
    {
        Storage::fake('public');

        $this->assertNull(PenyimpananBerkas::url(null));
        $this->assertNull(PenyimpananBerkas::url(''));
        $this->assertSame('http://localhost:4001/storage/dokumen/a.pdf', PenyimpananBerkas::url('http://localhost:4001/storage/dokumen/a.pdf'));
        $this->assertSame('https://cdn.contoh.id/b.pdf', PenyimpananBerkas::url('https://cdn.contoh.id/b.pdf'));
        $this->assertStringEndsWith('/storage/dokumen/c.pdf', (string) PenyimpananBerkas::url('dokumen/c.pdf'));
    }
}
