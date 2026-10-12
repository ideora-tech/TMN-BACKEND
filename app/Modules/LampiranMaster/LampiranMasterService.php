<?php

declare(strict_types=1);

namespace App\Modules\LampiranMaster;

use App\Modules\LampiranMaster\Contracts\LampiranMasterRepositoryInterface;
use App\Support\PenyimpananBerkas;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class LampiranMasterService
{
    public const JENIS_VENDOR   = LampiranMasterRepositoryInterface::JENIS_VENDOR;
    public const JENIS_SUPPLIER = LampiranMasterRepositoryInterface::JENIS_SUPPLIER;

    public const MAKS_LAMPIRAN = 20;

    private const LABEL = [
        self::JENIS_VENDOR   => 'Vendor',
        self::JENIS_SUPPLIER => 'Supplier',
    ];

    public function __construct(private readonly LampiranMasterRepositoryInterface $repo) {}

    public function daftar(string $jenis, string $idPemilik, string $idPerusahaan): array
    {
        $this->pastikanPemilik($jenis, $idPemilik, $idPerusahaan);

        return $this->repo->listByPemilik($jenis, $idPemilik);
    }

    /** @param UploadedFile[] $files */
    public function tambah(string $jenis, string $idPemilik, array $files, string $idPerusahaan): array
    {
        $this->pastikanPemilik($jenis, $idPemilik, $idPerusahaan);
        $this->pastikanKuota($jenis, $idPemilik, count($files));

        $tersimpan = [];
        try {
            foreach ($files as $file) {
                $tersimpan[] = [
                    'url_file'  => PenyimpananBerkas::simpan($file, 'lampiran-master/' . $jenis),
                    'nama_asli' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'tipe_mime' => $file->getMimeType(),
                    'ukuran'    => (int) $file->getSize(),
                ];
            }

            DB::transaction(function () use ($jenis, $idPemilik, $idPerusahaan, $tersimpan) {
                $this->repo->kunciPemilik($jenis, $idPemilik);
                $this->pastikanKuota($jenis, $idPemilik, count($tersimpan));

                $urutan = $this->repo->urutanTerakhir($jenis, $idPemilik);

                foreach ($tersimpan as $berkas) {
                    $this->repo->insert(array_merge($berkas, [
                        'id_perusahaan' => $idPerusahaan,
                        'jenis_pemilik' => $jenis,
                        'id_pemilik'    => $idPemilik,
                        'urutan'        => ++$urutan,
                    ]));
                }
            }, 3);
        } catch (Throwable $e) {
            Storage::disk('public')->delete(array_column($tersimpan, 'url_file'));

            throw $e;
        }

        return $this->repo->listByPemilik($jenis, $idPemilik);
    }

    public function hapus(string $jenis, string $idPemilik, string $idLampiran, string $idPerusahaan): void
    {
        $this->pastikanPemilik($jenis, $idPemilik, $idPerusahaan);

        if ($this->repo->find($jenis, $idPemilik, $idLampiran) === null) {
            abort(404, 'Lampiran tidak ditemukan');
        }

        $this->repo->softDelete($idLampiran);
    }

    private function pastikanPemilik(string $jenis, string $idPemilik, string $idPerusahaan): void
    {
        if (!$this->repo->pemilikAda($jenis, $idPemilik, $idPerusahaan)) {
            abort(404, self::LABEL[$jenis] . ' tidak ditemukan');
        }
    }

    private function pastikanKuota(string $jenis, string $idPemilik, int $tambahan): void
    {
        if ($this->repo->hitung($jenis, $idPemilik) + $tambahan > self::MAKS_LAMPIRAN) {
            abort(422, 'Maksimal ' . self::MAKS_LAMPIRAN . ' lampiran per ' . strtolower(self::LABEL[$jenis]));
        }
    }
}
