<?php

declare(strict_types=1);

namespace App\Modules\KonsolidasiKlien;

use App\Modules\KonsolidasiKlien\Contracts\KonsolidasiKlienRepositoryInterface;
use App\Modules\TarifRute\TarifRuteService;

class KonsolidasiKlienService
{
    public function __construct(
        private readonly KonsolidasiKlienRepositoryInterface $repo,
        private readonly TarifRuteService $tarifRuteService,
    ) {}

    public function rekap(string $idKlien, string $idPerusahaan, ?string $dari, ?string $sampai, ?string $sumber = null, ?string $idProyek = null): array
    {
        $klien = $this->repo->klienInfo($idKlien, $idPerusahaan);
        if ($klien === null) {
            abort(404, 'Klien tidak ditemukan');
        }

        $rows      = $this->repo->tripKlien($idPerusahaan, $idKlien, $dari, $sampai, $sumber, $idProyek);
        $idTrips   = array_map(fn ($r) => (string) $r->id_trip, $rows);
        $dropMap   = $this->repo->titikDropPerTrip($idTrips);
        $biayaMap  = $this->repo->biayaTagihanPerTrip($idTrips);

        $trips = array_map(
            fn ($row) => $this->mapBaris($row, $idKlien, $idPerusahaan, $dropMap, $biayaMap),
            $rows
        );

        $bertarif = array_filter($trips, fn ($t) => $t['tarif'] !== null);

        return [
            'klien'     => ['id_klien' => $klien->id_klien, 'nama_klien' => $klien->nama_klien],
            'ringkasan' => [
                'total_rit'      => count($trips),
                'total_jarak_km' => array_sum(array_map(fn ($t) => $t['jarak_tempuh_km'] ?? 0, $trips)),
                'estimasi_nilai' => array_sum(array_map(fn ($t) => $t['tarif']['harga'] + $t['biaya_tambahan'], $bertarif)),
                'tanpa_tarif'    => count($trips) - count($bertarif),
            ],
            'trips' => $trips,
        ];
    }

    private function mapBaris(object $row, string $idKlien, string $idPerusahaan, array $dropMap, array $biayaMap): array
    {
        $idJenisKendaraan = $row->id_jenis_kendaraan ?? $row->id_jenis_kendaraan_vendor ?? null;

        $tarif = null;
        if ($row->id_rute !== null && $idJenisKendaraan !== null) {
            $t = $this->tarifRuteService->resolusi(
                $idPerusahaan,
                (string) $row->id_rute,
                (string) $idJenisKendaraan,
                $idKlien,
                (string) $row->tanggal,
            );
            if ($t !== null) {
                $tarif = ['id_tarif_rute' => $t->id_tarif_rute, 'harga' => (float) $t->harga];
            }
        }

        return [
            'id_trip'           => $row->id_trip,
            'id_proyek'         => $row->id_proyek,
            'id_rute'           => $row->id_rute,
            'tanggal'           => $row->tanggal,
            'kode_proyek'       => $row->kode_proyek,
            'nama_proyek'       => $row->nama_proyek,
            'rute'              => $row->nama_rute ?? $row->rute_teks,
            'asal'              => $row->asal,
            'tujuan'            => $row->tujuan,
            'nopol'             => $row->nopol_internal ?? $row->nopol_vendor,
            'supir_nama'        => $row->nama_supir_internal ?? $row->nama_supir_vendor,
            'sumber'            => $row->sumber ?? 'internal',
            'jarak_tempuh_km'   => $row->jarak_tempuh_km !== null ? (float) $row->jarak_tempuh_km : null,
            'tarif'             => $tarif,
            'sudah_difakturkan' => (int) $row->sudah_difakturkan === 1,
            'titik_drop'        => $dropMap[$row->id_trip] ?? [],
            'biaya_tambahan'    => $biayaMap[$row->id_trip] ?? 0.0,
        ];
    }
}
