<?php

declare(strict_types=1);

namespace App\Modules\IzinPeran;

use App\Modules\IzinPeran\Contracts\IzinPeranRepositoryInterface;

class IzinPeranService
{
    public function __construct(private readonly IzinPeranRepositoryInterface $repo) {}

    public function listByPeran(string $idPerusahaan, string $kodePeran): array
    {
        return $this->repo->findByPeran($idPerusahaan, $kodePeran);
    }

    public function findOrFail(string $id): IzinPeranModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Izin peran tidak ditemukan');
        }
        return $record;
    }

    /**
     * Bulk set permissions for a role.
     *
     * @param array<int, array{id_menu: string, aksi: string, diizinkan: int}> $permissions
     */
    public function bulkSet(string $idPerusahaan, string $kodePeran, array $permissions, ?string $kodePeranPelaku = null): void
    {
        if ($kodePeranPelaku !== null && $kodePeranPelaku !== 'SUPERADMIN' && strtoupper(trim($kodePeran)) === 'SUPERADMIN') {
            abort(403, 'Izin Superadmin tidak bisa diatur');
        }

        foreach ($permissions as $perm) {
            $this->repo->upsert(
                $idPerusahaan,
                $kodePeran,
                $perm['id_menu'],
                $perm['aksi'],
                (int) $perm['diizinkan']
            );
        }
    }

    public function update(string $id, array $data, ?string $batasPerusahaan = null): IzinPeranModel
    {
        $record = $this->findOrFail($id);
        if ($batasPerusahaan !== null) {
            if ((string) $record->id_perusahaan !== $batasPerusahaan) {
                abort(404, 'Izin peran tidak ditemukan');
            }
            if (strtoupper((string) $record->kode_peran) === 'SUPERADMIN') {
                abort(403, 'Izin Superadmin tidak bisa diatur');
            }
        }
        return $this->repo->update($record, $data);
    }
}
