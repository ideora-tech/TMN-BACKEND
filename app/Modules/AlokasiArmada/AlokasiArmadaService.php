<?php

declare(strict_types=1);

namespace App\Modules\AlokasiArmada;

use App\Modules\AlokasiArmada\Contracts\AlokasiArmadaRepositoryInterface;
use App\Modules\JadwalShift\Contracts\JadwalShiftRepositoryInterface;

class AlokasiArmadaService
{
    public function __construct(
        private readonly AlokasiArmadaRepositoryInterface $repo,
        private readonly JadwalShiftRepositoryInterface $jadwalShiftRepo,
    ) {}

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
        $override = $this->jadwalShiftRepo->findOverrideAktif($idSupir, $idProyek, $tanggal);
        if ($override !== null && $override->id_armada_override !== null) {
            $ada = $this->repo->findAktifBySupirTanggal($idSupir, $tanggal);
            if ($ada !== null && (string) $ada->id_armada === (string) $override->id_armada_override && $ada->sumber === 'override_manual') {
                return;
            }
            if ($ada !== null) {
                $this->repo->softDeleteById($ada->id_alokasi);
            }
            $this->repo->create([
                'tanggal'         => $tanggal,
                'id_proyek'       => $idProyek,
                'id_supir'        => $idSupir,
                'id_armada'       => $override->id_armada_override,
                'id_pemilik_asal' => null,
                'sumber'          => 'override_manual',
                'keterangan'      => null,
            ]);
            return;
        }

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
     * Endpoint manual — untuk staleness yang bukan dari papan jadwal (mis.
     * pengajuan cuti disetujui belakangan). Perubahan papan jadwal sendiri
     * (tambah/hapus/import di JadwalShiftService) sudah memicu hitungUlangRentang()
     * otomatis, jadi endpoint ini jadi pelengkap/escape hatch, bukan satu-satunya
     * jalan. Aman dipanggil berkali-kali (idempoten).
     */
    public function hitungUlangUntukProyek(string $idProyek, string $idPerusahaan, string $dari, string $sampai): int
    {
        if (!$this->repo->proyekMilikPerusahaan($idProyek, $idPerusahaan)) {
            abort(404, 'Proyek tidak ditemukan');
        }

        return $this->hitungUlangRentang($idProyek, $dari, $sampai);
    }

    /**
     * Inti hitung ulang, tanpa cek kepemilikan proyek — dipakai
     * hitungUlangUntukProyek() (sudah tervalidasi lewat pemanggil endpoint
     * manual di atas) dan dipanggil otomatis oleh JadwalShiftService setiap
     * papan jadwal proyek berubah (tambah/hapus/import), supaya supir lain
     * yang terdampak (pemiliknya baru dijadwalkan/dibebaskan) ikut ter-update
     * tanpa perlu tombol manual.
     */
    public function hitungUlangRentang(string $idProyek, string $dari, string $sampai): int
    {
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
