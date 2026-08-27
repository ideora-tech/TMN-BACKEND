<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MigrasiStatusSiapTransferTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_NAME = '2026_08_28_100001_migrasi_status_dicek_ke_siap_transfer';
    private const MIGRATION_PATH = 'database/migrations/2026_08_28_100001_migrasi_status_dicek_ke_siap_transfer.php';

    private function buatPengajuan(string $status): string
    {
        $id = (string) Str::uuid();
        DB::table('pengajuan_pengeluaran')->insert([
            'id_pengajuan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nomor_pengajuan' => 'PP-MIG-' . Str::random(6), 'kategori' => 'lainnya', 'nominal' => 100000,
            'tanggal_pengajuan' => now()->toDateString(), 'penerima' => 'Test Migrasi',
            'status' => $status, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    public function test_migrasi_ubah_dicek_menjadi_siap_transfer_tanpa_menyentuh_status_lain(): void
    {
        $this->ensurePerusahaan();
        $idDicek = $this->buatPengajuan('dicek');
        $idDisetujui = $this->buatPengajuan('disetujui');
        $idDitransfer = $this->buatPengajuan('ditransfer');

        DB::table('migrations')->where('migration', self::MIGRATION_NAME)->delete();
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH, '--force' => true]);

        $this->assertSame('siap_transfer', DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idDicek)->value('status'));
        $this->assertSame('disetujui', DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idDisetujui)->value('status'));
        $this->assertSame('ditransfer', DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idDitransfer)->value('status'));
    }

    public function test_migrasi_down_mengembalikan_siap_transfer_menjadi_dicek(): void
    {
        $this->ensurePerusahaan();
        $idDicek = $this->buatPengajuan('dicek');

        DB::table('migrations')->where('migration', self::MIGRATION_NAME)->delete();
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH, '--force' => true]);
        $this->assertSame('siap_transfer', DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idDicek)->value('status'));

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH, '--force' => true]);
        $this->assertSame('dicek', DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idDicek)->value('status'));
    }
}
