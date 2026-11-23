<?php

declare(strict_types=1);

namespace App\Modules\Trip;

use App\Modules\Notifikasi\NotifikasiService;
use App\Modules\Penugasan\Contracts\PenugasanRepositoryInterface;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Penugasan\PenugasanService;
use App\Modules\ProyekRute\Contracts\ProyekRuteRepositoryInterface;
use App\Modules\Trip\Contracts\TripRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Ritase tambahan atas permintaan klien di lapangan. Supir menyalin penugasan
 * yang trip-nya sudah selesai menjadi penugasan rit berikutnya (tanggal hari
 * ini) lalu trip-nya langsung dimulai — tanpa menunggu operasional membuat
 * penugasan baru. Pola "1 penugasan = 1 trip" tetap utuh, jadi fee supir,
 * uang jalan, parameter tagihan & laporan berjalan lewat jalur yang sama
 * dengan penugasan buatan operasional. Operasional diberi notifikasi.
 */
class RitaseSupirService
{
    public function __construct(
        private readonly TripService $tripService,
        private readonly PenugasanService $penugasanService,
        private readonly PenugasanRepositoryInterface $penugasanRepo,
        private readonly TripRepositoryInterface $tripRepo,
        private readonly ProyekRuteRepositoryInterface $proyekRuteRepo,
    ) {}

    public function buatDanMulai(array $data, string $idSupir, string $idPerusahaan, string $tipe = 'internal'): TripModel
    {
        $vendor = $tipe === 'vendor';
        $asal = $this->penugasanService->findMilikOrFail((string) $data['id_penugasan'], $idPerusahaan);

        $milik = $vendor
            ? (string) $asal->id_supir_vendor === $idSupir
            : (string) $asal->id_supir === $idSupir;
        if (!$milik) {
            abort(403, 'Penugasan ini bukan milik Anda');
        }

        $hariIni = now()->toDateString();
        if ($asal->status === 'batal') {
            abort(422, 'Penugasan sudah dibatalkan — ritase tambahan tidak dapat dibuat');
        }
        if (substr((string) $asal->tanggal_tugas, 0, 10) !== $hariIni) {
            abort(422, 'Ritase tambahan hanya bisa dibuat dari penugasan hari ini');
        }
        if (!$this->tripRepo->adaTripSelesaiUntukPenugasan((string) $asal->id_penugasan)) {
            abort(422, 'Selesaikan trip pada penugasan ini terlebih dahulu sebelum menambah ritase');
        }

        $asal->load(['proyek', 'armada', 'armadaVendor']);
        if ($asal->proyek === null || in_array($asal->proyek->status, ['selesai', 'batal'], true)) {
            abort(422, 'Proyek sudah tidak aktif — ritase tambahan tidak dapat dibuat');
        }

        $antrean = $this->penugasanRepo->findPenugasanAntreanSupir(
            $vendor ? 'id_supir_vendor' : 'id_supir',
            $idSupir,
            $hariIni,
            (string) $asal->id_proyek,
        );
        if ($antrean !== null) {
            abort(422, 'Operasional sudah menyiapkan penugasan ritase berikutnya untuk proyek ini — mulai trip dari penugasan tersebut di tab Jadwal');
        }

        $idRuteAsal = $asal->id_rute !== null ? (string) $asal->id_rute : null;
        $idRute = !empty($data['id_rute']) ? (string) $data['id_rute'] : $idRuteAsal;
        if ($asal->sumber === 'vendor' && $idRute === null) {
            abort(422, 'Rute wajib dipilih untuk penugasan vendor');
        }
        if ($idRute !== null && !$this->proyekRuteRepo->ruteTerdaftarUntukProyek((string) $asal->id_proyek, $idRute)) {
            abort(422, 'Rute tidak terdaftar untuk proyek ini');
        }

        $estimasiBiaya = $this->estimasiUangJalan($asal, $idRute, $idRuteAsal);
        $catatan = isset($data['catatan']) ? trim((string) $data['catatan']) : '';
        $dimintaOleh = trim((string) $data['diminta_oleh']);
        $keterangan = Str::limit(
            'Ritase tambahan oleh supir atas permintaan ' . $dimintaOleh . ($catatan !== '' ? ' — ' . $catatan : ''),
            497
        );

        [$penugasanBaru, $trip] = DB::transaction(function () use ($asal, $idRute, $hariIni, $estimasiBiaya, $keterangan, $catatan, $idSupir, $idPerusahaan, $tipe) {
            $penugasanBaru = $this->penugasanRepo->create([
                'id_proyek'         => $asal->id_proyek,
                'id_rute'           => $idRute,
                'id_armada'         => $asal->id_armada,
                'id_supir'          => $asal->id_supir,
                'tanggal_tugas'     => $hariIni,
                'status'            => 'aktif',
                'estimasi_biaya'    => $estimasiBiaya,
                'fee_supir'         => $asal->fee_supir,
                'keterangan'        => $keterangan,
                'sumber'            => $asal->sumber ?? 'internal',
                'id_kontrak_vendor' => $asal->id_kontrak_vendor,
                'id_armada_vendor'  => $asal->id_armada_vendor,
                'id_supir_vendor'   => $asal->id_supir_vendor,
            ]);

            // Guard absensi, cuti, trip aktif lain & status armada dijalankan
            // di jalur mulai trip supir biasa; abort di sana me-rollback
            // penugasan baru di atas.
            $trip = $this->tripService->mulaiDariPenugasanUntukSupir([
                'id_penugasan' => (string) $penugasanBaru->id_penugasan,
                'id_rute'      => $idRute,
                'catatan'      => $catatan !== '' ? $catatan : null,
            ], $idSupir, $idPerusahaan, $tipe);

            return [$penugasanBaru, $trip];
        });

        $this->beritahuOperasional($penugasanBaru, $asal, $idSupir, $vendor, $dimintaOleh, $idPerusahaan);

        // Mobile pindah ke detail penugasan baru memakai id ini.
        $trip->setAttribute('id_penugasan', (string) $penugasanBaru->id_penugasan);

        return $trip;
    }

    /**
     * Rute sama → uang jalan ikut penugasan asal (bisa saja sudah dikoreksi
     * manual operasional). Rute beda → ambil dari rate card proyek sesuai
     * jenis kendaraan unit, sama seperti penugasan harian.
     */
    private function estimasiUangJalan(PenugasanModel $asal, ?string $idRute, ?string $idRuteAsal): ?float
    {
        if ($idRute === $idRuteAsal) {
            return $asal->estimasi_biaya !== null ? (float) $asal->estimasi_biaya : null;
        }
        if ($idRute === null) {
            return null;
        }

        $idJenisKendaraan = $asal->armada?->id_jenis_kendaraan ?? $asal->armadaVendor?->id_jenis_kendaraan;

        return $this->proyekRuteRepo->tarifUangJalanRute(
            (string) $asal->id_proyek,
            $idRute,
            $idJenisKendaraan !== null ? (string) $idJenisKendaraan : null,
        );
    }

    private function beritahuOperasional(PenugasanModel $penugasanBaru, PenugasanModel $asal, string $idSupir, bool $vendor, string $dimintaOleh, string $idPerusahaan): void
    {
        try {
            $namaProyek = $asal->proyek->nama_proyek ?? 'proyek';
            $namaSupir = $this->tripRepo->namaSupir($idSupir, $vendor) ?? 'Supir';

            app(NotifikasiService::class)->kirimKePemilikIzinMenu(
                ['/penugasan'],
                $idPerusahaan,
                "Ritase tambahan oleh supir: {$namaProyek}",
                "{$namaSupir} menambah ritase pada proyek {$namaProyek} atas permintaan {$dimintaOleh}. Trip sudah dimulai.",
                'ritase_tambahan_supir',
                'penugasan',
                (string) $penugasanBaru->id_penugasan,
                '/penugasan/' . $penugasanBaru->id_penugasan,
                null,
                'tambah',
            );
        } catch (\Throwable $e) {
            Log::warning('Notifikasi ritase tambahan supir gagal: ' . $e->getMessage());
        }
    }
}
