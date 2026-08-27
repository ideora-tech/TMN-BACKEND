<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Helpers\MigrationHelper;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class MigrasiEventTypeSemuaPerusahaanTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_NAME = '2026_08_25_100006_migrate_approver_keuangan_ke_approval_generik';
    private const MIGRATION_PATH = 'database/migrations/2026_08_25_100006_migrate_approver_keuangan_ke_approval_generik.php';

    private bool $approverKeuanganDibuatUlang = false;

    protected function tearDown(): void
    {
        if ($this->approverKeuanganDibuatUlang) {
            Schema::dropIfExists('approver_keuangan');
        }

        parent::tearDown();
    }

    /**
     * Migration 2026_08_25_100007 (yang sudah berjalan lebih dulu lewat RefreshDatabase) men-drop
     * tabel approver_keuangan. Migration 100006 aslinya berjalan SEBELUM 100007 dalam urutan
     * kronologis produksi (tabel selalu ada saat itu) — di sini kita buat ulang strukturnya supaya
     * migration 100006 bisa di-replay standalone seperti aslinya.
     */
    private function pastikanTabelApproverKeuanganAda(): void
    {
        if (Schema::hasTable('approver_keuangan')) {
            return;
        }

        Schema::create('approver_keuangan', function (Blueprint $table) {
            $table->char('id_approver', 36)->primary();
            $table->char('id_perusahaan', 36)->index();
            $table->string('tipe', 10);
            $table->char('id_jabatan', 36)->nullable();
            $table->char('id_pengguna', 36)->nullable();
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);
        });
        $this->approverKeuanganDibuatUlang = true;
    }

    public function test_migrasi_membuat_event_type_untuk_semua_perusahaan_meski_tanpa_approver(): void
    {
        $this->pastikanTabelApproverKeuanganAda();

        $idPerusahaanDenganApprover = (string) Str::uuid();
        $idPerusahaanTanpaApprover  = (string) Str::uuid();

        DB::table('perusahaan')->insert([
            ['id_perusahaan' => $idPerusahaanDenganApprover, 'nama' => 'Perusahaan Dengan Approver', 'dibuat_pada' => now()],
            ['id_perusahaan' => $idPerusahaanTanpaApprover, 'nama' => 'Perusahaan Tanpa Approver', 'dibuat_pada' => now()],
        ]);

        $idJabatan = (string) Str::uuid();
        DB::table('jabatan')->insert([
            'id_jabatan'    => $idJabatan,
            'id_perusahaan' => $idPerusahaanDenganApprover,
            'kode_jabatan'  => 'JBT-MIG',
            'nama_jabatan'  => 'Direktur Migrasi',
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);

        DB::table('approver_keuangan')->insert([
            'id_approver'   => (string) Str::uuid(),
            'id_perusahaan' => $idPerusahaanDenganApprover,
            'tipe'          => 'jabatan',
            'id_jabatan'    => $idJabatan,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);

        // Hapus tracking row migration ini supaya bisa dijalankan ulang secara terisolasi.
        DB::table('migrations')->where('migration', self::MIGRATION_NAME)->delete();

        $this->artisan('migrate', [
            '--path'  => self::MIGRATION_PATH,
            '--force' => true,
        ]);

        $this->assertDatabaseHas('approval_event_type', [
            'id_perusahaan' => $idPerusahaanDenganApprover,
            'kode'          => 'pengajuan_pengeluaran',
        ]);
        $this->assertDatabaseHas('approval_event_type', [
            'id_perusahaan' => $idPerusahaanTanpaApprover,
            'kode'          => 'pengajuan_pengeluaran',
        ]);

        $idEventTypeDenganApprover = DB::table('approval_event_type')
            ->where('id_perusahaan', $idPerusahaanDenganApprover)
            ->where('kode', 'pengajuan_pengeluaran')
            ->value('id_event_type');

        $this->assertDatabaseHas('approval_config_approver', [
            'id_event_type' => $idEventTypeDenganApprover,
            'tipe'          => 'jabatan',
            'id_jabatan'    => $idJabatan,
        ]);

        $jumlahConfigUntukPerusahaanTanpaApprover = DB::table('approval_config_approver as ac')
            ->join('approval_event_type as et', 'et.id_event_type', '=', 'ac.id_event_type')
            ->where('et.id_perusahaan', $idPerusahaanTanpaApprover)
            ->count();
        $this->assertSame(0, $jumlahConfigUntukPerusahaanTanpaApprover);
    }

    public function test_migrasi_tidak_membuat_duplikat_event_type_jika_dijalankan_ulang(): void
    {
        $this->pastikanTabelApproverKeuanganAda();

        $idPerusahaan = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaan, 'nama' => 'Perusahaan Idempoten', 'dibuat_pada' => now()]);

        DB::table('migrations')->where('migration', self::MIGRATION_NAME)->delete();
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH, '--force' => true]);

        DB::table('migrations')->where('migration', self::MIGRATION_NAME)->delete();
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH, '--force' => true]);

        $jumlah = DB::table('approval_event_type')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('kode', 'pengajuan_pengeluaran')
            ->count();
        $this->assertSame(1, $jumlah);
    }
}
