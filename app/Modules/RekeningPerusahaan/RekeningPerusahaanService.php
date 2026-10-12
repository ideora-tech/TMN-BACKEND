<?php

declare(strict_types=1);

namespace App\Modules\RekeningPerusahaan;

use App\Modules\RekeningPerusahaan\Contracts\RekeningPerusahaanRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RekeningPerusahaanService
{
    public function __construct(private readonly RekeningPerusahaanRepositoryInterface $repo) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $search = null): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $search);

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

    public function findOrFail(string $id, string $idPerusahaan): object
    {
        $record = $this->repo->findById($id);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Rekening tidak ditemukan');
        }

        return $record;
    }

    public function create(array $data, string $idPerusahaan): object
    {
        return DB::transaction(function () use ($data, $idPerusahaan) {
            $this->repo->kunciPerusahaan($idPerusahaan);

            $isi = $this->rapikan($data);
            $this->pastikanTidakKembar($idPerusahaan, $isi['nama_bank'], $isi['nomor_rekening']);

            $jadiUtama = !$this->repo->adaUtama($idPerusahaan) || !empty($data['utama']);
            if ($jadiUtama) {
                $this->repo->lepasUtama($idPerusahaan);
            }

            $record = $this->repo->create(array_merge($isi, [
                'id_perusahaan' => $idPerusahaan,
                'utama'         => $jadiUtama ? 1 : 0,
            ]));
            $this->repo->salinUtamaKePerusahaan($idPerusahaan);

            return $record;
        }, 3);
    }

    public function update(string $id, array $data, string $idPerusahaan): object
    {
        $this->findOrFail($id, $idPerusahaan);

        return DB::transaction(function () use ($id, $data, $idPerusahaan) {
            $this->repo->kunciPerusahaan($idPerusahaan);
            $record = $this->findOrFail($id, $idPerusahaan);

            $isi = $this->rapikan(array_merge([
                'nama_bank'          => $record->nama_bank,
                'nomor_rekening'     => $record->nomor_rekening,
                'atas_nama_rekening' => $record->atas_nama_rekening,
            ], $data));
            $this->pastikanTidakKembar($idPerusahaan, $isi['nama_bank'], $isi['nomor_rekening'], $id);

            if (array_key_exists('utama', $data)) {
                $sudahUtama = (int) $record->utama === 1;
                $jadiUtama  = (bool) $data['utama'];
                if ($sudahUtama && !$jadiUtama) {
                    $this->tolak('utama', 'Jadikan rekening lain sebagai rekening utama untuk menggantikan rekening ini');
                }
                if (!$sudahUtama && $jadiUtama) {
                    $this->repo->lepasUtama($idPerusahaan);
                    $isi['utama'] = 1;
                }
            }

            $hasil = $this->repo->update($record, $isi);
            $this->repo->salinUtamaKePerusahaan($idPerusahaan);

            return $hasil;
        }, 3);
    }

    public function delete(string $id, string $idPerusahaan): void
    {
        $this->findOrFail($id, $idPerusahaan);

        DB::transaction(function () use ($id, $idPerusahaan) {
            $this->repo->kunciPerusahaan($idPerusahaan);
            $record = $this->findOrFail($id, $idPerusahaan);

            if ((int) $record->utama === 1 && $this->repo->jumlah($idPerusahaan) > 1) {
                abort(422, 'Jadikan rekening lain sebagai rekening utama dulu sebelum menghapus rekening ini');
            }

            $this->repo->delete($record);
            $this->repo->salinUtamaKePerusahaan($idPerusahaan);
        }, 3);
    }

    private function rapikan(array $data): array
    {
        $namaBank = trim((string) ($data['nama_bank'] ?? ''));
        $nomor    = trim((string) ($data['nomor_rekening'] ?? ''));
        $atasNama = trim((string) ($data['atas_nama_rekening'] ?? ''));

        if ($namaBank === '') {
            $this->tolak('nama_bank', 'Nama bank wajib diisi');
        }
        if ($nomor === '') {
            $this->tolak('nomor_rekening', 'Nomor rekening wajib diisi');
        }

        return [
            'nama_bank'          => $namaBank,
            'nomor_rekening'     => $nomor,
            'atas_nama_rekening' => $atasNama !== '' ? $atasNama : null,
        ];
    }

    private function pastikanTidakKembar(string $idPerusahaan, string $namaBank, string $nomor, ?string $kecualiId = null): void
    {
        if ($this->repo->kembar($idPerusahaan, $namaBank, $nomor, $kecualiId)) {
            $this->tolak('nomor_rekening', 'Rekening dengan bank dan nomor ini sudah terdaftar');
        }
    }

    private function tolak(string $field, string $pesan): never
    {
        throw ValidationException::withMessages([$field => $pesan]);
    }
}
