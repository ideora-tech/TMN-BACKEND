<?php

declare(strict_types=1);

namespace App\Modules\DokumenKaryawan;

use App\Modules\DokumenKaryawan\Contracts\DokumenKaryawanRepositoryInterface;
use App\Modules\Karyawan\Contracts\KaryawanRepositoryInterface;
use App\Support\PenyimpananBerkas;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

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
        $this->pastikanNomorKtp($data, null);

        if ($file) {
            $data['url_file'] = PenyimpananBerkas::simpan($file, 'dokumen');
        }
        unset($data['file']);

        $record = $this->repo->create(array_merge($data, ['id_karyawan' => $idKaryawan]));
        $this->sinkronSimSupir($idKaryawan);
        return $this->selaraskanKtp($record, $data);
    }

    public function listUntukSupir(object $supir): array
    {
        return $this->repo->findAllBySupir((string) $supir->id_supir, $this->idKaryawanSupir($supir));
    }

    public function createUntukSupir(object $supir, array $data, ?UploadedFile $file = null): object
    {
        $this->pastikanNomorKtp($data, null);

        if ($file) {
            $data['url_file'] = PenyimpananBerkas::simpan($file, 'dokumen');
        }
        unset($data['file']);

        $record = $this->repo->create(array_merge($data, [
            'id_karyawan' => $this->idKaryawanSupir($supir),
            'id_supir'    => (string) $supir->id_supir,
        ]));
        $this->sinkronSimPemilik($record);

        return $this->selaraskanKtp($record, $data);
    }

    public function updateUntukSupir(object $supir, string $id, array $data, ?UploadedFile $file = null): object
    {
        $record = $this->milikSupirOrFail($supir, $id);
        $this->pastikanNomorKtp($data, $record);

        if ($file) {
            $data['url_file'] = PenyimpananBerkas::simpan($file, 'dokumen');
        }
        unset($data['file']);

        $updated = $this->repo->update($record, $data);
        $this->sinkronSimPemilik($record);

        return $this->selaraskanKtp($updated, $data);
    }

    public function deleteUntukSupir(object $supir, string $id): void
    {
        $record = $this->milikSupirOrFail($supir, $id);
        $this->repo->delete($record);
        $this->sinkronSimPemilik($record);
    }

    private function pastikanNomorKtp(array $data, ?object $record): void
    {
        $jenis = (string) ($data['jenis_dokumen'] ?? $record->jenis_dokumen ?? '');
        if (!$this->jenisKtp($jenis) || !array_key_exists('nomor', $data)) {
            return;
        }

        if (mb_strlen(trim((string) ($data['nomor'] ?? ''))) > 20) {
            throw ValidationException::withMessages(['nomor' => 'Nomor KTP maksimal 20 karakter']);
        }
    }

    private function jenisKtp(string $jenis): bool
    {
        return mb_strtoupper(trim($jenis)) === 'KTP';
    }

    private function selaraskanKtp(object $record, array $data): object
    {
        if ($record->id_karyawan === null || !$this->jenisKtp((string) $record->jenis_dokumen)) {
            return $record;
        }

        $nomor = array_key_exists('nomor', $data) ? trim((string) ($data['nomor'] ?? '')) : '';
        if ($nomor !== '') {
            $this->repo->ubahNikKtpKaryawan((string) $record->id_karyawan, $nomor);
        }
        $this->repo->selaraskanKtpKaryawan((string) $record->id_karyawan);

        return $this->repo->findById((string) $record->id_dokumen_karyawan) ?? $record;
    }

    private function idKaryawanSupir(object $supir): ?string
    {
        return !empty($supir->id_karyawan) ? (string) $supir->id_karyawan : null;
    }

    private function milikSupirOrFail(object $supir, string $id): object
    {
        $record = $this->repo->findById($id);
        $idKaryawan = $this->idKaryawanSupir($supir);
        $milik = $record !== null && (
            ($record->id_karyawan === null && (string) $record->id_supir === (string) $supir->id_supir)
            || ($idKaryawan !== null && (string) $record->id_karyawan === $idKaryawan)
        );
        if (!$milik) {
            abort(404, 'Dokumen supir tidak ditemukan');
        }

        return $record;
    }

    private function sinkronSimPemilik(object $record): void
    {
        if ($record->id_karyawan !== null) {
            $this->sinkronSimSupir((string) $record->id_karyawan);

            return;
        }

        if ($record->id_supir !== null) {
            $maks = $this->repo->maxBerlakuSimTanpaKaryawan((string) $record->id_supir);
            if ($maks !== null) {
                $this->repo->sinkronKadaluarsaSimSatuSupir((string) $record->id_supir, $maks);
            }
        }
    }

    public function update(string $id, string $idPerusahaan, array $data, ?UploadedFile $file = null, ?string $idKaryawan = null): object
    {
        $record = $this->findOrFail($id, $idPerusahaan, $idKaryawan);
        $this->pastikanNomorKtp($data, $record);

        if ($file) {
            $data['url_file'] = PenyimpananBerkas::simpan($file, 'dokumen');
        }
        unset($data['file']);

        $updated = $this->repo->update($record, $data);
        $this->sinkronSimSupir((string) $record->id_karyawan);
        return $this->selaraskanKtp($updated, $data);
    }

    public function delete(string $id, string $idPerusahaan, ?string $idKaryawan = null): void
    {
        $record = $this->findOrFail($id, $idPerusahaan, $idKaryawan);
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

    private function findOrFail(string $id, string $idPerusahaan, ?string $idKaryawan = null): object
    {
        $record = $this->repo->findById($id);
        if ($record === null || $record->id_karyawan === null || ($idKaryawan !== null && (string) $record->id_karyawan !== $idKaryawan)) {
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
