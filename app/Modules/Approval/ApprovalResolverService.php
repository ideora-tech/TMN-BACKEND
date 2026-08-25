<?php

declare(strict_types=1);

namespace App\Modules\Approval;

use App\Modules\Approval\Contracts\ApprovalRepositoryInterface;

class ApprovalResolverService
{
    public function __construct(private readonly ApprovalRepositoryInterface $repo) {}

    /** @return string[] daftar id_pengguna approver, tidak pernah null tapi bisa kosong sebelum divalidasi caller */
    public function resolve(ApprovalEventTypeModel $eventType, string $idPenggunaPengaju): array
    {
        if ($eventType->mode_resolusi === 'pinned') {
            return $this->repo->resolvePinned($eventType->id_event_type, $eventType->id_perusahaan);
        }

        return $this->resolveRelatif($idPenggunaPengaju, $eventType->id_perusahaan);
    }

    private function resolveRelatif(string $idPenggunaPengaju, string $idPerusahaan): array
    {
        $jabatanPengaju = $this->repo->cariJabatanPengguna($idPenggunaPengaju, $idPerusahaan);
        if ($jabatanPengaju === null) {
            return [];
        }

        $idJabatan = $jabatanPengaju->id_jabatan;

        while ($idJabatan !== null) {
            $jabatan = $this->repo->cariJabatanInduk($idJabatan);
            if ($jabatan === null || $jabatan->id_jabatan_induk === null) {
                $idJabatanAtasan = $jabatan->id_jabatan_induk ?? null;
                if ($idJabatanAtasan === null) {
                    return [];
                }
            }
            $idJabatanAtasan = $jabatan->id_jabatan_induk;
            if ($idJabatanAtasan === null) {
                return [];
            }

            $users = $this->repo->cariUserAktifPemegangJabatan($idJabatanAtasan, $idPerusahaan);
            if ($users !== []) {
                return $users;
            }

            $idJabatan = $idJabatanAtasan;
        }

        return [];
    }
}
