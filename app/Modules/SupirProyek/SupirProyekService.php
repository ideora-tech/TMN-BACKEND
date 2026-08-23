<?php

declare(strict_types=1);

namespace App\Modules\SupirProyek;

use App\Modules\JadwalShift\Contracts\JadwalShiftRepositoryInterface;
use App\Modules\SupirProyek\Contracts\SupirProyekRepositoryInterface;
use Illuminate\Support\Facades\DB;

class SupirProyekService
{
    public function __construct(
        private readonly SupirProyekRepositoryInterface $repo,
        private readonly JadwalShiftRepositoryInterface $jadwalShiftRepo,
    ) {}

    public function list(string $idProyek, string $idPerusahaan): array
    {
        if (!$this->repo->proyekMilikPerusahaan($idProyek, $idPerusahaan)) {
            abort(404, 'Proyek tidak ditemukan');
        }

        return $this->repo->listByProyek($idProyek, $idPerusahaan);
    }

    /**
     * @param string[] $supirIds
     * @return array{sukses: int, gagal: array<int, array{id_supir: string, alasan: string}>}
     */
    public function tambahBatch(string $idProyek, array $supirIds, string $idPerusahaan): array
    {
        if (!$this->repo->proyekMilikPerusahaan($idProyek, $idPerusahaan)) {
            abort(404, 'Proyek tidak ditemukan');
        }

        $sukses = 0;
        $gagal  = [];

        foreach (array_unique($supirIds) as $idSupir) {
            if ($this->repo->terdaftar($idProyek, $idSupir)) {
                $gagal[] = ['id_supir' => $idSupir, 'alasan' => 'Supir sudah terdaftar di proyek ini'];
                continue;
            }

            if (!$this->repo->supirMilikPerusahaan($idSupir, $idPerusahaan)) {
                $gagal[] = ['id_supir' => $idSupir, 'alasan' => 'Supir tidak ditemukan'];
                continue;
            }

            $this->repo->create([
                'id_perusahaan' => $idPerusahaan,
                'id_proyek'     => $idProyek,
                'id_supir'      => $idSupir,
            ]);
            $sukses++;
        }

        return ['sukses' => $sukses, 'gagal' => $gagal];
    }

    public function hapus(string $id, string $idPerusahaan): void
    {
        $record = $this->repo->findByIdMilikPerusahaan($id, $idPerusahaan);
        if ($record === null) {
            abort(404, 'Supir proyek tidak ditemukan');
        }

        $idProyek = (string) $record->id_proyek;
        $idSupir  = (string) $record->id_supir;

        DB::transaction(function () use ($record, $idProyek, $idSupir) {
            $this->repo->delete($record);
            $this->jadwalShiftRepo->hapusOrphanUntukSupirProyek($idProyek, $idSupir, now()->toDateString());
        });
    }
}
