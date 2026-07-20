<?php

declare(strict_types=1);

namespace App\Modules\ProyekRute;

use App\Modules\ProyekRute\Contracts\ProyekRuteRepositoryInterface;
use Illuminate\Support\Collection;

class ProyekRuteService
{
    public function __construct(private readonly ProyekRuteRepositoryInterface $repo) {}

    public function listByProyek(string $idProyek): Collection
    {
        return $this->repo->listByProyek($idProyek);
    }

    public function create(string $idProyek, array $data, string $idPerusahaan): ProyekRuteModel
    {
        $this->pastikanRuteJenisValid($data, $idPerusahaan);

        $record = $this->repo->create(array_merge($data, [
            'id_perusahaan' => $idPerusahaan,
            'id_proyek'     => $idProyek,
        ]));

        return $this->repo->findDetailById($record->id_proyek_rute);
    }

    public function update(string $idProyek, string $id, array $data, string $idPerusahaan): ProyekRuteModel
    {
        $record = $this->repo->findById($id);
        if ($record === null || $record->id_proyek !== $idProyek) {
            abort(404, 'Rute proyek tidak ditemukan');
        }

        $this->pastikanRuteJenisValid($data, $idPerusahaan);

        $this->repo->update($record, $data);

        return $this->repo->findDetailById($id);
    }

    public function delete(string $idProyek, string $id): void
    {
        $record = $this->repo->findById($id);
        if ($record === null || $record->id_proyek !== $idProyek) {
            abort(404, 'Rute proyek tidak ditemukan');
        }
        $this->repo->delete($record);
    }

    private function pastikanRuteJenisValid(array $data, string $idPerusahaan): void
    {
        if (isset($data['id_rute']) && $this->repo->ruteMilik($data['id_rute'], $idPerusahaan) === null) {
            abort(404, 'Rute tidak ditemukan');
        }
        if (isset($data['id_jenis_kendaraan']) && $this->repo->jenisKendaraanMilik($data['id_jenis_kendaraan'], $idPerusahaan) === null) {
            abort(404, 'Jenis kendaraan tidak ditemukan');
        }
    }
}
