<?php

declare(strict_types=1);

namespace App\Modules\Pengguna;

use App\Models\Pengguna;
use App\Modules\Pengguna\Contracts\PenggunaRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PenggunaService
{
    private const PERAN_SUPIR        = 'SUPIR';
    private const PERAN_SUPIR_VENDOR = 'SUPIR_VENDOR';

    public function __construct(private readonly PenggunaRepositoryInterface $repo) {}

    public function opsiSupir(string $idPerusahaan): array
    {
        return [
            'supir'        => $this->repo->opsiSupir($idPerusahaan),
            'supir_vendor' => $this->repo->opsiSupirVendor($idPerusahaan),
        ];
    }

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $search = null, ?string $aktif = null): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $search, $aktif);

        return [
            'data' => $result->items(),
            'meta' => [
                'page'       => $result->currentPage(),
                'limit'      => $result->perPage(),
                'total'      => $result->total(),
                'totalPages' => $result->lastPage(),
            ],
        ];
    }

    public function findOrFail(string $id, ?string $idPerusahaan = null): Pengguna
    {
        $record = $this->repo->findById($id);
        if ($record === null || ($idPerusahaan !== null && (string) $record->id_perusahaan !== $idPerusahaan)) {
            abort(404, 'Pengguna tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): Pengguna
    {
        if ($this->repo->findByUsername($data['username'])) {
            abort(409, 'Username sudah digunakan');
        }
        if ($this->repo->findByEmail($data['email'])) {
            abort(409, 'Email sudah digunakan');
        }
        $data['kata_sandi'] = Hash::make($data['password']);
        unset($data['password']);

        // Akun peran SUPIR: karyawan mengikuti profil supir (supir.id_karyawan), bukan tautan langsung
        if (in_array($data['kode_peran'] ?? null, ['SUPIR', 'SUPIR_VENDOR'], true)) {
            $data['id_karyawan'] = null;
        }

        $tautan = $this->ambilTautan($data);

        return DB::transaction(function () use ($data, $tautan) {
            $record = $this->repo->create($data);
            $this->terapkanTautan($record, $tautan);
            return $record;
        });
    }

    public function update(string $id, array $data, ?string $idPerusahaan = null): Pengguna
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        // Check username uniqueness if being changed
        if (isset($data['username']) && $data['username'] !== $record->username) {
            $existing = $this->repo->findByUsername($data['username']);
            if ($existing !== null && $existing->id_pengguna !== $record->id_pengguna) {
                abort(409, 'Username sudah digunakan');
            }
        }

        // Check email uniqueness if being changed
        if (isset($data['email']) && $data['email'] !== $record->email) {
            $existing = $this->repo->findByEmail($data['email']);
            if ($existing !== null && $existing->id_pengguna !== $record->id_pengguna) {
                abort(409, 'Email sudah digunakan');
            }
        }

        // Hash password if provided
        if (isset($data['password'])) {
            $data['kata_sandi'] = Hash::make($data['password']);
            unset($data['password']);
        }

        // Akun peran SUPIR: karyawan mengikuti profil supir (supir.id_karyawan), bukan tautan langsung
        $kodePeran = array_key_exists('kode_peran', $data) ? $data['kode_peran'] : $record->kode_peran;
        if (in_array($kodePeran, ['SUPIR', 'SUPIR_VENDOR'], true)) {
            $data['id_karyawan'] = null;
        }

        $tautan = $this->ambilTautan($data);

        return DB::transaction(function () use ($record, $data, $tautan) {
            $hasil = $this->repo->update($record, $data);
            $this->terapkanTautan($hasil, $tautan);
            return $hasil;
        });
    }

    private function ambilTautan(array &$data): array
    {
        $tautan = array_intersect_key($data, ['id_supir' => true, 'id_supir_vendor' => true]);
        unset($data['id_supir'], $data['id_supir_vendor']);

        return array_map(
            static fn ($nilai) => $nilai !== null && trim((string) $nilai) !== '' ? trim((string) $nilai) : null,
            $tautan,
        );
    }

    private function terapkanTautan(Pengguna $record, array $tautan): void
    {
        $idPengguna   = (string) $record->id_pengguna;
        $idPerusahaan = (string) $record->id_perusahaan;
        $peran        = $record->kode_peran !== null ? (string) $record->kode_peran : null;

        if ($peran !== self::PERAN_SUPIR && ($tautan['id_supir'] ?? null) !== null) {
            abort(422, 'Supir hanya bisa ditautkan ke akun berperan Supir');
        }
        if ($peran !== self::PERAN_SUPIR_VENDOR && ($tautan['id_supir_vendor'] ?? null) !== null) {
            abort(422, 'Supir vendor hanya bisa ditautkan ke akun berperan Supir Vendor');
        }

        if ($peran === self::PERAN_SUPIR) {
            if (array_key_exists('id_supir', $tautan)) {
                if ($tautan['id_supir'] !== null) {
                    $supir = $this->repo->supirUntukTautan($tautan['id_supir'], $idPerusahaan);
                    if ($supir === null) {
                        abort(404, 'Supir tidak ditemukan');
                    }
                    $this->tolakBilaSudahBerakun('Supir', $supir, $idPengguna);
                }
                $this->repo->gantiTautanSupir($idPengguna, $tautan['id_supir']);
            }
        } else {
            $this->repo->gantiTautanSupir($idPengguna, null);
        }

        if ($peran === self::PERAN_SUPIR_VENDOR) {
            if (array_key_exists('id_supir_vendor', $tautan)) {
                if ($tautan['id_supir_vendor'] !== null) {
                    $supirVendor = $this->repo->supirVendorUntukTautan($tautan['id_supir_vendor'], $idPerusahaan);
                    if ($supirVendor === null) {
                        abort(404, 'Supir vendor tidak ditemukan');
                    }
                    $this->tolakBilaSudahBerakun('Supir vendor', $supirVendor, $idPengguna);
                }
                $this->repo->gantiTautanSupirVendor($idPengguna, $tautan['id_supir_vendor']);
            }
        } else {
            $this->repo->gantiTautanSupirVendor($idPengguna, null);
        }
    }

    private function tolakBilaSudahBerakun(string $sebutan, object $supir, string $idPengguna): void
    {
        if ($supir->id_pengguna !== null && (string) $supir->id_pengguna !== $idPengguna) {
            abort(422, "{$sebutan} {$supir->nama} sudah memakai akun {$supir->username_pengguna} — lepas dulu tautannya dari akun itu");
        }
    }

    public function delete(string $id, ?string $idPerusahaan = null): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if ($this->repo->terdaftarSebagaiApprover($id)) {
            abort(422, 'Pengguna masih terdaftar sebagai approver di konfigurasi approval — ganti dulu approver-nya');
        }

        if ($this->repo->jadiApproverLewatJabatan($id)) {
            abort(422, 'Pengguna ini pejabat approver (lewat jabatan karyawannya) — nonaktifkan saja akunnya');
        }

        $this->repo->delete($record);
    }

    public function changePassword(string $id, string $oldPassword, string $newPassword): void
    {
        $pengguna = $this->findOrFail($id);
        if (!Hash::check($oldPassword, $pengguna->kata_sandi)) {
            abort(422, 'Password lama tidak sesuai');
        }
        $this->repo->update($pengguna, ['kata_sandi' => Hash::make($newPassword)]);
    }
}
