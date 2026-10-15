<?php

declare(strict_types=1);

namespace App\Modules\KomponenUangJalan;

use App\Modules\KomponenUangJalan\Contracts\KomponenUangJalanRepositoryInterface;
use Illuminate\Validation\ValidationException;

class KomponenUangJalanService
{
    public const MAKS_NOMINAL = 100000000;

    public const BAWAAN = [
        ['nama' => 'Tol', 'urutan' => 1],
        ['nama' => 'BBM', 'urutan' => 2],
        ['nama' => 'Biaya Lain', 'urutan' => 3],
    ];

    public function __construct(private readonly KomponenUangJalanRepositoryInterface $repo) {}

    public function list(string $idPerusahaan, int $page, int $limit, ?string $search, ?bool $aktif): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $search, $aktif);

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

    public function listAktif(string $idPerusahaan): array
    {
        return $this->repo->listAktifByPerusahaan($idPerusahaan);
    }

    public function pastikanBawaan(string $idPerusahaan): void
    {
        if ($this->repo->pernahAda($idPerusahaan)) {
            return;
        }

        foreach (self::BAWAAN as $b) {
            $this->repo->create(['id_perusahaan' => $idPerusahaan, 'nama' => $b['nama'], 'urutan' => $b['urutan'], 'aktif' => 1]);
        }
    }

    public function findOrFail(string $id, string $idPerusahaan): object
    {
        $record = $this->repo->findById($id);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Komponen uang jalan tidak ditemukan');
        }

        return $record;
    }

    public function create(array $data, string $idPerusahaan): object
    {
        $nama = trim((string) $data['nama']);
        $this->pastikanNamaUnik($idPerusahaan, $nama, null);

        return $this->repo->create([
            'id_perusahaan' => $idPerusahaan,
            'nama'          => $nama,
            'urutan'        => (int) ($data['urutan'] ?? 0),
            'aktif'         => array_key_exists('aktif', $data) ? ((bool) $data['aktif'] ? 1 : 0) : 1,
        ]);
    }

    public function update(string $id, array $data, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $ubah   = [];

        if (array_key_exists('nama', $data)) {
            $ubah['nama'] = trim((string) $data['nama']);
            $this->pastikanNamaUnik($idPerusahaan, $ubah['nama'], $id);
        }
        if (array_key_exists('urutan', $data)) {
            $ubah['urutan'] = (int) ($data['urutan'] ?? 0);
        }
        if (array_key_exists('aktif', $data)) {
            $ubah['aktif'] = (bool) $data['aktif'] ? 1 : 0;
        }

        return $ubah === [] ? $record : $this->repo->update($record, $ubah);
    }

    public function delete(string $id, string $idPerusahaan): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if ($this->repo->sudahDipakai($id)) {
            abort(422, 'Komponen sudah dipakai di tarif rute atau pengajuan uang jalan — nonaktifkan saja');
        }

        $this->repo->delete($record);
    }

    public function rincianValid(mixed $baris, string $idPerusahaan, array $idBolehNonaktif, string $kunci): array
    {
        $baris = is_array($baris) ? array_values($baris) : [];
        $ids   = array_values(array_unique(array_map(static fn ($b) => (string) ($b['id_komponen_uang_jalan'] ?? ''), $baris)));
        $peta  = $this->repo->findByIds($ids, $idPerusahaan);
        $hasil = [];
        $dilihat = [];

        foreach ($baris as $i => $b) {
            $id      = (string) ($b['id_komponen_uang_jalan'] ?? '');
            $nominal = round((float) ($b['nominal'] ?? 0), 2);
            $komponen = $peta[$id] ?? null;

            if ($komponen === null) {
                throw ValidationException::withMessages(["{$kunci}.{$i}.id_komponen_uang_jalan" => 'Komponen uang jalan tidak ditemukan']);
            }
            if (isset($dilihat[$id])) {
                throw ValidationException::withMessages(["{$kunci}.{$i}.id_komponen_uang_jalan" => "Komponen {$komponen->nama} diisi lebih dari sekali"]);
            }
            $dilihat[$id] = true;
            if ((int) $komponen->aktif !== 1 && !in_array($id, $idBolehNonaktif, true)) {
                throw ValidationException::withMessages(["{$kunci}.{$i}.id_komponen_uang_jalan" => "Komponen {$komponen->nama} sudah tidak aktif"]);
            }
            if ($nominal <= 0) {
                continue;
            }

            $hasil[] = [
                'id_komponen_uang_jalan' => $id,
                'nama_komponen'          => (string) $komponen->nama,
                'urutan'                 => (int) $komponen->urutan,
                'nominal'                => $nominal,
            ];
        }

        usort($hasil, static fn ($a, $b) => [$a['urutan'], $a['nama_komponen']] <=> [$b['urutan'], $b['nama_komponen']]);

        return $hasil;
    }

    private function pastikanNamaUnik(string $idPerusahaan, string $nama, ?string $kecualiId): void
    {
        if ($this->repo->namaTerpakai($idPerusahaan, $nama, $kecualiId)) {
            throw ValidationException::withMessages(['nama' => 'Nama komponen sudah ada']);
        }
    }
}
