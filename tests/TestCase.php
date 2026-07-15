<?php

namespace Tests;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    public const PERUSAHAAN_ID = 'b8f3c1a2-0000-4000-8000-000000000001';

    protected function ensurePerusahaan(): void
    {
        DB::table('perusahaan')->insertOrIgnore([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => 'TMN Transport Test',
            'dibuat_pada' => now(),
        ]);
    }

    protected function actingAsRole(string $kodePeran = 'ADMIN'): Pengguna
    {
        $this->ensurePerusahaan();
        $pengguna = Pengguna::create([
            'id_pengguna'   => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran'    => $kodePeran,
            'username'      => 'test_' . Str::random(8),
            'email'         => Str::random(8) . '@test.id',
            'kata_sandi'    => bcrypt('Password123!'),
            'aktif'         => 1,
        ]);
        Sanctum::actingAs($pengguna, ['*']);
        return $pengguna;
    }
}
