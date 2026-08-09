<?php

declare(strict_types=1);

namespace App\Modules\AbsensiSupir;

use App\Modules\AbsensiSupir\Contracts\AbsensiSupirRepositoryInterface;
use App\Support\PenyimpananBerkas;
use Illuminate\Http\UploadedFile;

class AbsensiSupirService
{
    public function __construct(
        private readonly AbsensiSupirRepositoryInterface $repo,
    ) {}

    public function hariIni(string $idSupir): ?object
    {
        return $this->denganUrlFoto($this->repo->findBySupirTanggal($idSupir, now()->toDateString()));
    }

    public function absen(string $idSupir, string $idPerusahaan, string $status, ?string $keterangan, ?UploadedFile $foto = null, ?float $skorWajah = null, ?bool $wajahCocok = null): object
    {
        $tanggal = now()->toDateString();

        $data = [
            'status'     => $status,
            'keterangan' => $keterangan,
        ];
        if ($foto !== null) {
            $data['foto']        = PenyimpananBerkas::simpan($foto, 'absensi-selfie');
            $data['skor_wajah']  = $skorWajah;
            $data['wajah_cocok'] = $wajahCocok === null ? null : (int) $wajahCocok;
        }

        $ada = $this->repo->findBySupirTanggal($idSupir, $tanggal);
        if ($ada !== null) {
            $this->repo->update($ada->id_absensi, $data);
            return $this->denganUrlFoto($this->repo->findBySupirTanggal($idSupir, $tanggal));
        }

        return $this->denganUrlFoto($this->repo->create($data + [
            'id_perusahaan' => $idPerusahaan,
            'id_supir'      => $idSupir,
            'tanggal'       => $tanggal,
        ]));
    }

    private function denganUrlFoto(?object $row): ?object
    {
        if ($row !== null) {
            $row->url_foto = PenyimpananBerkas::url($row->foto ?? null);
        }
        return $row;
    }
}
