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
        if ($this->hargaTerkunci($idProyek)) {
            abort(422, 'Harga terkunci — ubah lewat penawaran revisi');
        }

        $this->pastikanRuteJenisValid($data, $idPerusahaan);

        $idJenisKendaraan = $data['id_jenis_kendaraan'] ?? null;
        if ($this->repo->existsDuplikat($idProyek, $data['id_rute'], $idJenisKendaraan)) {
            abort(409, 'Rute dengan jenis kendaraan ini sudah terdaftar di proyek');
        }

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

        if ($this->adaPerubahanHarga($record, $data) && $this->hargaTerkunci($idProyek)) {
            abort(422, 'Harga terkunci — ubah lewat penawaran revisi');
        }

        $this->pastikanRuteJenisValid($data, $idPerusahaan);

        $idRute = $data['id_rute'] ?? $record->id_rute;
        $idJenisKendaraan = array_key_exists('id_jenis_kendaraan', $data) ? $data['id_jenis_kendaraan'] : $record->id_jenis_kendaraan;
        if ($this->repo->existsDuplikat($idProyek, $idRute, $idJenisKendaraan, $id)) {
            abort(409, 'Rute dengan jenis kendaraan ini sudah terdaftar di proyek');
        }

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

    private function hargaTerkunci(string $idProyek): bool
    {
        $tipeHarga = $this->repo->tipeHargaProyek($idProyek) ?? 'per_rit';
        return $tipeHarga === 'per_rit' && $this->repo->adaPenawaranDisetujui($idProyek);
    }

    private function adaPerubahanHarga(ProyekRuteModel $record, array $data): bool
    {
        if (array_key_exists('harga_penawaran', $data)) {
            $baru = $data['harga_penawaran'];
            $lama = $record->harga_penawaran;
            if (($baru === null) !== ($lama === null)) {
                return true;
            }
            if ($baru !== null && (float) $baru !== (float) $lama) {
                return true;
            }
        }

        if (array_key_exists('estimasi_ritase', $data) && (int) $data['estimasi_ritase'] !== (int) $record->estimasi_ritase) {
            return true;
        }

        return false;
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
