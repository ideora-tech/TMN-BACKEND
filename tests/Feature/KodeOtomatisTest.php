<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\KodeOtomatis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KodeOtomatisTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_entitas_rute_reset_tidak_urut_naik_tanpa_periode(): void
    {
        $this->ensurePerusahaan();

        $this->assertSame('RT-0001', KodeOtomatis::berikutnya(self::PERUSAHAAN_ID, 'rute'));
        $this->assertSame('RT-0002', KodeOtomatis::berikutnya(self::PERUSAHAAN_ID, 'rute'));
    }

    public function test_entitas_proyek_reset_tahunan(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-17'));
        $this->ensurePerusahaan();

        $this->assertSame('PRJ-2026-0001', KodeOtomatis::berikutnya(self::PERUSAHAAN_ID, 'proyek'));
    }

    public function test_entitas_penawaran_reset_bulanan(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-17'));
        $this->ensurePerusahaan();

        $this->assertSame('PNW-202608-0001', KodeOtomatis::berikutnya(self::PERUSAHAAN_ID, 'penawaran'));
    }

    public function test_sequence_perusahaan_berbeda_terisolasi(): void
    {
        $this->ensurePerusahaan();
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert([
            'id_perusahaan' => $idPerusahaanLain,
            'nama'          => 'Perusahaan Lain Kode Otomatis',
            'dibuat_pada'   => now(),
        ]);

        $this->assertSame('RT-0001', KodeOtomatis::berikutnya(self::PERUSAHAAN_ID, 'rute'));
        $this->assertSame('RT-0001', KodeOtomatis::berikutnya($idPerusahaanLain, 'rute'));
        $this->assertSame('RT-0002', KodeOtomatis::berikutnya(self::PERUSAHAAN_ID, 'rute'));
        $this->assertSame('RT-0002', KodeOtomatis::berikutnya($idPerusahaanLain, 'rute'));
    }

    public function test_entitas_tanpa_baris_pengaturan_pakai_fallback_default(): void
    {
        $this->ensurePerusahaan();
        DB::table('pengaturan_kode')->insert([
            'id_pengaturan_kode' => (string) Str::uuid(),
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'entitas'            => 'rute',
            'prefix'             => 'XX',
            'panjang_digit'      => 4,
            'reset'              => 'tidak',
            'dibuat_pada'        => now(),
        ]);
        DB::table('pengaturan_kode')
            ->where('id_perusahaan', self::PERUSAHAAN_ID)
            ->where('entitas', 'rute')
            ->delete();

        $this->assertSame('RT-0001', KodeOtomatis::berikutnya(self::PERUSAHAAN_ID, 'rute'));
    }

    public function test_ganti_prefix_pengaturan_kode_berikut_pakai_prefix_baru_sequence_lanjut(): void
    {
        $this->ensurePerusahaan();
        DB::table('pengaturan_kode')->insert([
            'id_pengaturan_kode' => (string) Str::uuid(),
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'entitas'            => 'rute',
            'prefix'             => 'RT',
            'panjang_digit'      => 4,
            'reset'              => 'tidak',
            'dibuat_pada'        => now(),
        ]);

        $this->assertSame('RT-0001', KodeOtomatis::berikutnya(self::PERUSAHAAN_ID, 'rute'));

        DB::table('pengaturan_kode')
            ->where('id_perusahaan', self::PERUSAHAAN_ID)
            ->where('entitas', 'rute')
            ->update(['prefix' => 'RUT']);

        $this->assertSame('RUT-0002', KodeOtomatis::berikutnya(self::PERUSAHAAN_ID, 'rute'));
    }
}
