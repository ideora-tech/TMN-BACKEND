<?php

declare(strict_types=1);

namespace App\Modules\KontrakKaryawan;

use App\Modules\Karyawan\Contracts\KaryawanRepositoryInterface;
use App\Modules\KontrakKaryawan\Contracts\KontrakKaryawanRepositoryInterface;
use App\Support\PenyimpananBerkas;
use Illuminate\Http\UploadedFile;

class KontrakKaryawanService
{
    public function __construct(
        private readonly KontrakKaryawanRepositoryInterface $repo,
        private readonly KaryawanRepositoryInterface $karyawanRepo,
    ) {}

    public function listByKaryawan(string $idKaryawan, string $idPerusahaan): array
    {
        $this->karyawanOrFail($idKaryawan, $idPerusahaan);
        return $this->repo->findAllByKaryawan($idKaryawan);
    }

    public function create(string $idKaryawan, string $idPerusahaan, array $data, ?UploadedFile $file = null): object
    {
        $this->karyawanOrFail($idKaryawan, $idPerusahaan);

        if ($file) {
            $data['url_file'] = PenyimpananBerkas::simpan($file, 'dokumen');
        }
        unset($data['file']);

        $data['id_karyawan']   = $idKaryawan;
        $data['id_perusahaan'] = $idPerusahaan;

        return $this->repo->create($data);
    }

    public function update(string $id, string $idPerusahaan, array $data, ?UploadedFile $file = null): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if ($file) {
            $data['url_file'] = PenyimpananBerkas::simpan($file, 'dokumen');
        }
        unset($data['file']);

        return $this->repo->update($record, $data);
    }

    public function delete(string $id, string $idPerusahaan): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->repo->delete($record);
    }

    private function findOrFail(string $id, string $idPerusahaan): object
    {
        $record = $this->repo->findById($id);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Kontrak karyawan tidak ditemukan');
        }
        return $record;
    }

    private function karyawanOrFail(string $idKaryawan, string $idPerusahaan): void
    {
        $karyawan = $this->karyawanRepo->findById($idKaryawan);
        if ($karyawan === null || $karyawan->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Karyawan tidak ditemukan');
        }
    }
}
