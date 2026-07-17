<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Models\Pengguna;
use Illuminate\Support\Facades\DB;

class AuthRepository
{
    private const KARYAWAN_COLUMNS = [
        'id_karyawan', 'id_perusahaan', 'id_jabatan', 'id_lokasi', 'nik', 'nama_karyawan',
        'email', 'telepon', 'jenis_kelamin', 'tanggal_lahir', 'tanggal_masuk',
        'status_kepegawaian', 'gaji_pokok', 'aktif',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    public function findActiveByEmail(string $email): ?Pengguna
    {
        return Pengguna::where('email', $email)
            ->whereNull('dihapus_pada')
            ->where('aktif', 1)
            ->first();
    }

    public function findActiveByUsername(string $username): ?Pengguna
    {
        return Pengguna::where('username', $username)
            ->whereNull('dihapus_pada')
            ->where('aktif', 1)
            ->first();
    }

    public function findActiveByUsernameOrEmail(string $identifier): ?Pengguna
    {
        $pengguna = Pengguna::where(function ($q) use ($identifier) {
                $q->where('username', $identifier)->orWhere('email', $identifier);
            })
            ->whereNull('dihapus_pada')
            ->where('aktif', 1)
            ->first();

        if ($pengguna !== null) {
            $karyawan = $pengguna->id_karyawan !== null
                ? DB::table('karyawan')
                    ->select(self::KARYAWAN_COLUMNS)
                    ->where('id_karyawan', $pengguna->id_karyawan)
                    ->first()
                : null;

            $pengguna->setRelation('karyawan', $karyawan !== null ? collect((array) $karyawan) : null);
        }

        return $pengguna;
    }

    public function updateLoginTimestamp(Pengguna $pengguna): void
    {
        $pengguna->login_terakhir = now();
        $pengguna->saveQuietly();
    }
}
