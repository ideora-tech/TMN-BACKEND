<?php

declare(strict_types=1);

namespace App\Modules\AbsensiSupir;

use App\Modules\Absensi\AbsensiService;
use App\Modules\AbsensiSupir\Contracts\AbsensiSupirRepositoryInterface;
use App\Support\PenyimpananBerkas;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class AbsensiSupirService
{
    public function __construct(
        private readonly AbsensiSupirRepositoryInterface $repo,
        private readonly AbsensiService $absensiService,
    ) {}

    public function hariIni(string $idSupir): ?object
    {
        return $this->denganUrlFoto($this->repo->findBySupirTanggal($idSupir, now()->toDateString()));
    }

    public function absen(string $idSupir, string $idPerusahaan, string $status, ?string $keterangan, ?UploadedFile $foto = null, ?float $skorWajah = null, ?bool $wajahCocok = null, ?string $idKaryawan = null): object
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

        return DB::transaction(function () use ($idSupir, $idPerusahaan, $status, $keterangan, $idKaryawan, $tanggal, $data) {
            $ada = $this->repo->findBySupirTanggal($idSupir, $tanggal);
            if ($ada !== null) {
                $this->repo->update($ada->id_absensi, $data);
            } else {
                $this->repo->create($data + [
                    'id_perusahaan' => $idPerusahaan,
                    'id_supir'      => $idSupir,
                    'tanggal'       => $tanggal,
                ]);
            }

            if ($idKaryawan !== null) {
                $this->absensiService->catatDariAbsenSupir($idPerusahaan, $idKaryawan, $status, $keterangan);
            }

            return $this->denganUrlFoto($this->repo->findBySupirTanggal($idSupir, $tanggal));
        });
    }

    private function denganUrlFoto(?object $row): ?object
    {
        if ($row !== null) {
            $row->url_foto = PenyimpananBerkas::url($row->foto ?? null);
        }
        return $row;
    }
}
