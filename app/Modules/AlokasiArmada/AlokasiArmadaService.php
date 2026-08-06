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
     * Armada harian supir: pakai armada PENUGASAN-nya sendiri bila ada (sumber
     * 'penugasan'). Bila penugasannya tanpa armada (supir shift), sistem
     * meminjamkan armada menganggur — milik supir lain di proyek yang sama yang
     * off (cuti disetujui / tidak dijadwalkan tanggal itu) atau armada tanpa
     * pemegang — tercatat sumber 'otomatis' + pemilik asal + keterangan.
     * Tidak ada kandidat → baris tetap dibuat dengan id_armada NULL.
     * Sistem tidak pernah menulis ke penugasan.id_armada.
     */
    public function alokasikan(string $idSupir, string $tanggal, string $idProyek): void
    {
        $ada = $this->repo->findAktifBySupirTanggal($idSupir, $tanggal);

        $penugasan = $this->repo->penugasanAktifSupirProyek($idSupir, $idProyek);
        $idArmadaSendiri = $penugasan?->id_armada;

        if ($idArmadaSendiri !== null) {
            if ($ada !== null && (string) $ada->id_armada === (string) $idArmadaSendiri) {
                return;
            }

            if ($ada !== null) {
                $this->repo->softDeleteById($ada->id_alokasi);
            }

            $this->repo->create([
                'tanggal'         => $tanggal,
                'id_proyek'       => $idProyek,
                'id_supir'        => $idSupir,
                'id_armada'       => $idArmadaSendiri,
                'id_pemilik_asal' => null,
                'sumber'          => 'penugasan',
                'keterangan'      => null,
            ]);
            return;
        }

        // Supir shift: lepas alokasi lamanya dulu supaya armada lamanya tidak
        // ikut terhitung "sudah dialokasikan" saat pencarian kandidat.
        if ($ada !== null) {
            $this->repo->softDeleteById($ada->id_alokasi);
        }

        $kandidat = $this->repo->kandidatArmadaNganggur($idSupir, $tanggal, $idProyek)[0] ?? null;

        $this->repo->create([
            'tanggal'         => $tanggal,
            'id_proyek'       => $idProyek,
            'id_supir'        => $idSupir,
            'id_armada'       => $kandidat?->id_armada,
            'id_pemilik_asal' => $kandidat?->id_pemilik_asal,
            'sumber'          => $kandidat !== null ? 'otomatis' : 'penugasan',
            'keterangan'      => $kandidat?->keterangan ?? 'Tidak ada armada tersedia',
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

    /**
     * Pemicu manual: alokasi dihitung SEKALI saja saat jadwal (supir, tanggal)
     * pertama kali dibuat — kalau supir LAIN di proyek yang sama baru belakangan
     * jadi libur/cuti, alokasi yang sudah kepalang dibuat tidak otomatis
     * ter-update (sistem cuma mikir ulang saat jadwal si supir itu SENDIRI
     * berubah). Endpoint ini re-evaluasi ulang semua pasangan (supir, tanggal)
     * di proyek+rentang tanggal terpilih, supaya kandidat yang baru tersedia
     * ikut terpakai. Aman dipanggil berkali-kali (idempoten).
     */
    public function hitungUlangUntukProyek(string $idProyek, string $idPerusahaan, string $dari, string $sampai): int
    {
        if (!$this->repo->proyekMilikPerusahaan($idProyek, $idPerusahaan)) {
            abort(404, 'Proyek tidak ditemukan');
        }

        $pasangan = $this->repo->pasanganSupirTanggalUntukProyek($idProyek, $dari, $sampai);
        foreach ($pasangan as $p) {
            $this->alokasikan($p['id_supir'], $p['tanggal'], $idProyek);
        }

        return count($pasangan);
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
