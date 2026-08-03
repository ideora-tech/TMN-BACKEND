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
     * Alokasi otomatis satu batch jadwal: supir yang punya armada default
     * diproses lebih dulu agar mobilnya tidak keburu dipinjam supir shift.
     * Dipanggil JadwalShiftService setelah seluruh baris jadwal batch tersimpan.
     *
     * @param array<int, array{id_supir: string, tanggal: string}> $pasangan
     */
    public function alokasikanBatch(array $pasangan, string $idProyek): void
    {
        usort($pasangan, function (array $a, array $b) use ($idProyek) {
            $pa = $this->repo->penugasanAktifSupirProyek($a['id_supir'], $idProyek);
            $pb = $this->repo->penugasanAktifSupirProyek($b['id_supir'], $idProyek);
            return (int) empty($pa?->id_armada) <=> (int) empty($pb?->id_armada);
        });

        foreach ($pasangan as $p) {
            $this->alokasikan($p['id_supir'], $p['tanggal'], $idProyek);
        }
    }

    /**
     * Kepemilikan armada dibaca dari PENUGASAN (bukan master supir):
     * penugasan ber-armada = mobil sendiri; penugasan kosong = supir shift.
     * Sistem TIDAK pernah menulis balik ke penugasan — penugasan murni kendali
     * user, pinjaman hanya tercatat di tabel alokasi (history).
     */
    public function alokasikan(string $idSupir, string $tanggal, string $idProyek): void
    {
        $ada = $this->repo->findAktifBySupirTanggal($idSupir, $tanggal);
        if ($ada !== null && $ada->sumber === 'manual') {
            return;
        }

        $supir = $this->repo->supirRow($idSupir);
        if ($supir === null) {
            return;
        }

        // Hapus alokasi lama non-manual lebih dulu supaya armada yang dipegang
        // alokasi lama supir ini sendiri tidak terhitung "masih terpakai".
        $this->repo->softDeleteNonManual($idSupir, $tanggal);

        $idArmada = null;
        $idPemilikAsal = null;
        $sumber = 'otomatis';
        $keterangan = null;

        $penugasan = $this->repo->penugasanAktifSupirProyek($idSupir, $idProyek);
        $armadaSendiri = $penugasan?->id_armada;

        if (!empty($armadaSendiri)
            && $this->repo->armadaLayak($armadaSendiri) !== null
            && !$this->repo->armadaTerpakai($armadaSendiri, $tanggal)) {
            $idArmada = $armadaSendiri;
            $sumber = 'default';
        } else {
            $kandidat = $this->repo->cariArmadaNganggur((string) $supir->id_perusahaan, $tanggal, $idProyek)[0] ?? null;
            if ($kandidat !== null) {
                $idArmada = $kandidat->id_armada;
                $pemegang = $this->repo->cariPemegangArmada($kandidat->id_armada);
                $idPemilikAsal = $pemegang?->id_supir;
                $keterangan = $pemegang === null
                    ? 'Armada tanpa pemilik'
                    : ($this->repo->pemilikCuti((string) $pemegang->id_supir, $tanggal) ? 'Pemilik cuti' : 'Pemilik tidak dijadwalkan');
            } else {
                $keterangan = 'Tidak ada armada tersedia';
            }
        }

        $this->repo->create([
            'tanggal'         => $tanggal,
            'id_proyek'       => $idProyek,
            'id_supir'        => $idSupir,
            'id_armada'       => $idArmada,
            'id_pemilik_asal' => $idPemilikAsal,
            'sumber'          => $sumber,
            'keterangan'      => $keterangan,
        ]);
    }

    /**
     * Dipanggil saat armada pada penugasan diubah user — hitung ulang alokasi
     * non-manual untuk semua jadwal supir tsb dari tanggal tertentu ke depan.
     */
    public function hitungUlangUntukPenugasan(string $idSupir, string $idProyek, string $dariTanggal): void
    {
        foreach ($this->repo->jadwalMendatangSupirProyek($idSupir, $idProyek, $dariTanggal) as $tanggal) {
            $this->alokasikan($idSupir, substr((string) $tanggal, 0, 10), $idProyek);
        }
    }

    /**
     * Jadwal dihapus → alokasi (supir, tanggal) ikut terhapus APAPUN sumbernya,
     * termasuk manual — tanpa jadwal, alokasi tidak punya alasan hidup.
     * Aturan "manual tidak ditimpa" tetap berlaku di alokasikan().
     */
    public function hapusUntukJadwal(string $idSupir, string $tanggal): void
    {
        $this->repo->softDeleteSemua($idSupir, $tanggal);
    }

    public function override(string $idAlokasi, string $idArmada): object
    {
        $alokasi = $this->repo->findById($idAlokasi);
        if ($alokasi === null) {
            abort(404, 'Alokasi tidak ditemukan');
        }

        $armada = $this->repo->armadaLayak($idArmada);
        if ($armada === null) {
            abort(404, 'Armada tidak ditemukan atau sedang tidak layak jalan');
        }

        if ($this->repo->armadaTerpakai($idArmada, (string) $alokasi->tanggal, $idAlokasi)) {
            abort(422, 'Armada sudah dialokasikan ke supir lain pada tanggal ini');
        }

        $pemegang = $this->repo->cariPemegangArmada($idArmada);
        $idPemilikAsal = ($pemegang !== null && (string) $pemegang->id_supir !== (string) $alokasi->id_supir)
            ? $pemegang->id_supir
            : null;

        // Append-only untuk audit: baris lama di-soft-delete (tetap jadi jejak),
        // keputusan baru selalu berupa baris baru.
        $this->repo->softDeleteById($idAlokasi);

        return $this->repo->create([
            'tanggal'         => $alokasi->tanggal,
            'id_proyek'       => $alokasi->id_proyek,
            'id_supir'        => $alokasi->id_supir,
            'id_armada'       => $idArmada,
            'id_pemilik_asal' => $idPemilikAsal,
            'sumber'          => 'manual',
            'keterangan'      => 'Diubah manual oleh tim operasional',
        ]);
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

    public function armadaTersedia(string $idPerusahaan, string $tanggal, ?string $idSupir = null, ?string $idProyek = null): array
    {
        $hasil = [];

        if ($idSupir !== null && $idProyek !== null) {
            $penugasan = $this->repo->penugasanAktifSupirProyek($idSupir, $idProyek);
            if (!empty($penugasan?->id_armada)) {
                $armada = $this->repo->armadaLayak($penugasan->id_armada);
                if ($armada !== null && !$this->repo->armadaTerpakai($armada->id_armada, $tanggal)) {
                    $hasil[] = [
                        'id_armada'    => $armada->id_armada,
                        'nopol'        => $armada->nopol,
                        'nama_pemilik' => null,
                        'keterangan'   => 'Armada sendiri (dari penugasan)',
                    ];
                }
            }
        }

        foreach ($this->repo->cariArmadaNganggur($idPerusahaan, $tanggal) as $k) {
            $pemegang = $this->repo->cariPemegangArmada($k->id_armada);
            $hasil[] = [
                'id_armada'    => $k->id_armada,
                'nopol'        => $k->nopol,
                'nama_pemilik' => $pemegang?->nama,
                'keterangan'   => $pemegang === null
                    ? 'Tanpa pemilik'
                    : ($this->repo->pemilikCuti((string) $pemegang->id_supir, $tanggal) ? 'Pemilik cuti' : 'Pemilik tidak dijadwalkan'),
            ];
        }

        return $hasil;
    }
}
