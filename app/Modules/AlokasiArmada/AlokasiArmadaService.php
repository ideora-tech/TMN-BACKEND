<?php

declare(strict_types=1);

namespace App\Modules\AlokasiArmada;

use App\Modules\AlokasiArmada\Contracts\AlokasiArmadaRepositoryInterface;

class AlokasiArmadaService
{
    public function __construct(private readonly AlokasiArmadaRepositoryInterface $repo) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $dari = null, ?string $sampai = null, ?string $search = null, ?string $idArmada = null, ?string $idProyek = null): array
    {
        $result = $this->repo->paginate($idPerusahaan, $page, $limit, $dari, $sampai, $search, $idArmada, $idProyek);

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

    /**
     * Dipanggil JadwalShiftService setelah seluruh baris jadwal batch tersimpan.
     *
     * @param array<int, array{id_supir: string, tanggal: string}> $pasangan
     */
    public function alokasikanBatch(array $pasangan, string $idProyek): void
    {
        foreach ($pasangan as $p) {
            $this->alokasikan($p['id_supir'], $p['tanggal'], $idProyek);
        }
    }

    /**
     * Log harian polos: armada dicerminkan langsung dari PENUGASAN aktif
     * supir tsb (id_armada apa adanya, boleh null). Tidak ada pencarian atau
     * pinjam armada nganggur — kalau supir butuh armada, ubah di penugasan.
     */
    public function alokasikan(string $idSupir, string $tanggal, string $idProyek): void
    {
        $penugasan = $this->repo->penugasanAktifSupirProyek($idSupir, $idProyek);
        $idArmada = $penugasan?->id_armada;

        $ada = $this->repo->findAktifBySupirTanggal($idSupir, $tanggal);
        if ($ada !== null && (string) $ada->id_armada === (string) $idArmada) {
            return;
        }

        if ($ada !== null) {
            $this->repo->softDeleteById($ada->id_alokasi);
        }

        $this->repo->create([
            'tanggal'         => $tanggal,
            'id_proyek'       => $idProyek,
            'id_supir'        => $idSupir,
            'id_armada'       => $idArmada,
            'id_pemilik_asal' => null,
            'sumber'          => 'penugasan',
            'keterangan'      => null,
        ]);
    }

    /**
     * Dipanggil saat armada pada penugasan diubah user — hitung ulang alokasi
     * untuk semua jadwal supir tsb dari tanggal tertentu ke depan.
     */
    public function hitungUlangUntukPenugasan(string $idSupir, string $idProyek, string $dariTanggal): void
    {
        foreach ($this->repo->jadwalMendatangSupirProyek($idSupir, $idProyek, $dariTanggal) as $tanggal) {
            $this->alokasikan($idSupir, substr((string) $tanggal, 0, 10), $idProyek);
        }
    }

    /**
     * Jadwal dihapus → alokasi (supir, tanggal) ikut terhapus — tanpa jadwal,
     * alokasi tidak punya alasan hidup.
     */
    public function hapusUntukJadwal(string $idSupir, string $tanggal): void
    {
        $this->repo->softDeleteSemua($idSupir, $tanggal);
    }

    public function dataLaporanArmada(string $idArmada, string $idPerusahaan, ?string $dari = null, ?string $sampai = null): array
    {
        $armada = $this->repo->findArmadaMilikPerusahaan($idArmada, $idPerusahaan);
        if ($armada === null) {
            abort(404, 'Armada tidak ditemukan');
        }

        return [
            'armada' => $armada,
            'items'  => $this->repo->riwayatPerArmada($idArmada, $dari, $sampai),
        ];
    }

    public function dataPerusahaan(string $idPerusahaan): ?object
    {
        return $this->repo->getPerusahaan($idPerusahaan);
    }
}
