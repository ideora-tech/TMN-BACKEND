<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Pengguna;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Akun tes login mobile (driver via nomor telepon, staff via nomor telepon).
 * Sengaja TIDAK didaftarkan di DatabaseSeeder — jalankan manual saat dibutuhkan:
 *   php artisan db:seed --class=AkunTesMobileSeeder --force
 * Idempoten: aman dijalankan ulang, dilewati kalau akun tes sudah ada.
 */
class AkunTesMobileSeeder extends Seeder
{
    private const PASSWORD = 'Test1234!';

    public function run(): void
    {
        $this->buatAkunDriver();
        $this->buatAkunStaff();
    }

    private function buatAkunDriver(): void
    {
        if (Pengguna::where('email', 'like', 'test.driver.%@tmn.test')->exists()) {
            $this->command->warn('Akun tes driver sudah ada, dilewati.');
            return;
        }

        $supir = DB::table('supir')
            ->whereNull('id_pengguna')
            ->whereNull('dihapus_pada')
            ->whereNotNull('telepon')
            ->where('telepon', '!=', '')
            ->orderBy('dibuat_pada')
            ->first();

        if ($supir === null) {
            $this->command->warn('Tidak ada supir tanpa akun login yang punya nomor telepon, akun tes driver dilewati.');
            return;
        }

        if (Pengguna::where('username', $supir->telepon)->exists()) {
            $this->command->warn("Nomor telepon supir ({$supir->telepon}) sudah dipakai username lain, akun tes driver dilewati.");
            return;
        }

        $pengguna = Pengguna::create([
            'id_perusahaan' => $supir->id_perusahaan,
            'kode_peran'    => 'SUPIR',
            'username'      => $supir->telepon,
            'email'         => 'test.driver.' . Str::random(6) . '@tmn.test',
            'kata_sandi'    => Hash::make(self::PASSWORD),
            'aktif'         => 1,
        ]);

        DB::table('supir')->where('id_supir', $supir->id_supir)->update(['id_pengguna' => $pengguna->id_pengguna]);

        $this->command->info("Akun tes driver dibuat: {$supir->telepon} / " . self::PASSWORD);
    }

    private function buatAkunStaff(): void
    {
        if (Pengguna::where('email', 'like', 'test.staff.%@tmn.test')->exists()) {
            $this->command->warn('Akun tes staff sudah ada, dilewati.');
            return;
        }

        $karyawanIdsTerpakai = Pengguna::whereNotNull('id_karyawan')->pluck('id_karyawan')->all();

        $karyawan = DB::table('karyawan')
            ->whereNull('dihapus_pada')
            ->whereNotNull('telepon')
            ->where('telepon', '!=', '')
            ->when(!empty($karyawanIdsTerpakai), fn ($q) => $q->whereNotIn('id_karyawan', $karyawanIdsTerpakai))
            ->orderBy('dibuat_pada')
            ->first();

        if ($karyawan === null) {
            $this->command->warn('Tidak ada karyawan dengan nomor telepon yang belum tertaut akun, akun tes staff dilewati.');
            return;
        }

        if (Pengguna::where('username', $karyawan->telepon)->exists()) {
            $this->command->warn("Nomor telepon karyawan ({$karyawan->telepon}) sudah dipakai username lain, akun tes staff dilewati.");
            return;
        }

        $pengguna = Pengguna::create([
            'id_perusahaan' => $karyawan->id_perusahaan,
            'id_karyawan'   => $karyawan->id_karyawan,
            'kode_peran'    => 'MANAGER',
            'username'      => $karyawan->telepon,
            'email'         => 'test.staff.' . Str::random(6) . '@tmn.test',
            'kata_sandi'    => Hash::make(self::PASSWORD),
            'aktif'         => 1,
        ]);

        $this->command->info("Akun tes staff dibuat: {$karyawan->telepon} / " . self::PASSWORD);
    }
}
