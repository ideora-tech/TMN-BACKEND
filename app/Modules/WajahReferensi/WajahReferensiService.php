<?php

declare(strict_types=1);

namespace App\Modules\WajahReferensi;

use App\Modules\WajahReferensi\Contracts\WajahReferensiRepositoryInterface;
use App\Support\PenyimpananBerkas;
use Illuminate\Http\UploadedFile;

class WajahReferensiService
{
    public const PANJANG_EMBEDDING = 192;

    public function __construct(
        private readonly WajahReferensiRepositoryInterface $repo,
    ) {}

    public function saya(string $idPengguna): array
    {
        return $this->format($this->repo->findByPengguna($idPengguna));
    }

    public function daftar(string $idPengguna, string $idPerusahaan, UploadedFile $foto, string $embeddingJson, string $modelVersi): array
    {
        if ($this->repo->findByPengguna($idPengguna) !== null) {
            abort(409, 'Wajah sudah terdaftar. Hubungi admin untuk reset.');
        }

        $embedding = json_decode($embeddingJson, true);
        if (!is_array($embedding)
            || count($embedding) !== self::PANJANG_EMBEDDING
            || $embedding !== array_filter($embedding, 'is_numeric')) {
            abort(422, 'Embedding wajah tidak valid');
        }

        $row = $this->repo->create([
            'id_perusahaan' => $idPerusahaan,
            'id_pengguna'   => $idPengguna,
            'path_foto'     => PenyimpananBerkas::simpan($foto, 'wajah-referensi'),
            'embedding'     => json_encode($embedding),
            'model_versi'   => $modelVersi,
        ]);

        return $this->format($row);
    }

    public function reset(string $idPengguna): void
    {
        if ($this->repo->findByPengguna($idPengguna) === null) {
            abort(404, 'Wajah referensi tidak ditemukan');
        }
        $this->repo->delete($idPengguna);
    }

    private function format(?object $row): array
    {
        if ($row === null) {
            return ['terdaftar' => false, 'url_foto' => null, 'embedding' => null, 'model_versi' => null];
        }

        return [
            'terdaftar'   => true,
            'url_foto'    => PenyimpananBerkas::url($row->path_foto),
            'embedding'   => json_decode($row->embedding),
            'model_versi' => $row->model_versi,
        ];
    }
}
