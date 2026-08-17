<?php

declare(strict_types=1);

namespace App\Modules\DokumenKaryawan;

use App\Modules\DokumenKaryawan\Contracts\DokumenKaryawanRepositoryInterface;
use App\Modules\Karyawan\Contracts\KaryawanRepositoryInterface;
use App\Support\PenyimpananBerkas;
use Illuminate\Http\UploadedFile;

class DokumenKaryawanService
{
    public function __construct(
        private readonly DokumenKaryawanRepositoryInterface $repo,
        private readonly KaryawanRepositoryInterface $karyawanRepo,
    ) {}

    public function listByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idKaryawan = null, ?string $jenisDokumen = null, ?string $search = null): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $idKaryawan, $jenisDokumen, $search);

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

        $record = $this->repo->create(array_merge($data, ['id_karyawan' => $idKaryawan]));
        $this->sinkronSimSupir($idKaryawan);
        return $record;
    }

    public function update(string $id, string $idPerusahaan, array $data, ?UploadedFile $file = null): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if ($file) {
            $data['url_file'] = PenyimpananBerkas::simpan($file, 'dokumen');
        }
        unset($data['file']);

        $updated = $this->repo->update($record, $data);
        $this->sinkronSimSupir((string) $record->id_karyawan);
        return $updated;
    }

    public function delete(string $id, string $idPerusahaan): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->repo->delete($record);
        $this->sinkronSimSupir((string) $record->id_karyawan);
    }

    private function sinkronSimSupir(string $idKaryawan): void
    {
        $maks = $this->repo->maxBerlakuSimAktif($idKaryawan);
        if ($maks !== null) {
            $this->repo->sinkronKadaluarsaSimSupir($idKaryawan, $maks);
        }
    }

    private function findOrFail(string $id, string $idPerusahaan): object
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Dokumen karyawan tidak ditemukan');
        }

        $karyawan = $this->karyawanRepo->findById($record->id_karyawan);
        if ($karyawan === null || $karyawan->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Dokumen karyawan tidak ditemukan');
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
