<?php

declare(strict_types=1);

namespace App\Modules\AbsensiSupir;

use App\Modules\AbsensiSupir\Contracts\AbsensiSupirRepositoryInterface;

class AbsensiSupirService
{
    public function __construct(
        private readonly AbsensiSupirRepositoryInterface $repo,
    ) {}

    public function hariIni(string $idSupir): ?object
    {
        return $this->repo->findBySupirTanggal($idSupir, now()->toDateString());
    }

    public function absen(string $idSupir, string $idPerusahaan, string $status, ?string $keterangan): object
    {
        $tanggal = now()->toDateString();

        $ada = $this->repo->findBySupirTanggal($idSupir, $tanggal);
        if ($ada !== null) {
            $this->repo->update($ada->id_absensi, [
                'status'     => $status,
                'keterangan' => $keterangan,
            ]);
            return $this->repo->findBySupirTanggal($idSupir, $tanggal);
        }

        return $this->repo->create([
            'id_perusahaan' => $idPerusahaan,
            'id_supir'      => $idSupir,
            'tanggal'       => $tanggal,
            'status'        => $status,
            'keterangan'    => $keterangan,
        ]);
    }
}
