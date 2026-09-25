<?php

declare(strict_types=1);

namespace App\Modules\KonsolidasiKlien;

use App\Modules\KonsolidasiKlien\Contracts\KonsolidasiKlienRepositoryInterface;
use App\Modules\ProyekRute\Contracts\ProyekRuteRepositoryInterface;
use App\Support\TipeHarga;

class KonsolidasiKlienService
{
    public function __construct(
        private readonly KonsolidasiKlienRepositoryInterface $repo,
        private readonly ProyekRuteRepositoryInterface $proyekRuteRepo,
    ) {}

    public function siapTagih(string $idPerusahaan): array
    {
        return array_map(fn ($r) => [
            'id_klien'         => $r->id_klien,
            'nama_klien'       => $r->nama_klien,
            'id_proyek'        => $r->id_proyek,
            'kode_proyek'      => $r->kode_proyek,
            'nama_proyek'      => $r->nama_proyek,
            'borongan'         => TipeHarga::nilaiTetap($r->tipe_harga ?? null),
            'jumlah_trip'      => (int) $r->jumlah_trip,
            'tanggal_pertama'  => $r->tanggal_pertama,
            'tanggal_terakhir' => $r->tanggal_terakhir,
        ], $this->repo->siapTagih($idPerusahaan));
    }

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
        $biayaDetailMap = $this->repo->biayaTagihanDetailPerTrip($idTrips);
        $uangJalanTambahanMap = $this->repo->uangJalanTambahanPerTrip($idTrips);
        $uangJalanTambahanDetailMap = $this->repo->uangJalanTambahanDetailPerTrip($idTrips);
        $jenisMap  = $this->repo->namaJenisKendaraanMap(array_values(array_unique(array_filter(array_map(
            fn ($r) => $r->id_jenis_kendaraan ?? $r->id_jenis_kendaraan_vendor ?? null,
            $rows
        )))));

        $trips = array_map(
            fn ($row) => $this->mapBaris($row, $dropMap, $biayaMap, $biayaDetailMap, $jenisMap, $uangJalanTambahanMap, $uangJalanTambahanDetailMap),
            $rows
        );

        $bertarif = array_filter($trips, fn ($t) => $t['tarif'] !== null);
        $borongan = array_filter($trips, fn ($t) => $t['borongan'] === true);

        return [
            'klien'     => ['id_klien' => $klien->id_klien, 'nama_klien' => $klien->nama_klien],
            'ringkasan' => [
                'total_rit'      => count($trips),
                'total_jarak_km' => array_sum(array_map(fn ($t) => $t['jarak_tempuh_km'] ?? 0, $trips)),
                'estimasi_nilai' => array_sum(array_map(fn ($t) => $t['tarif']['harga'] + $t['biaya_tambahan'], $bertarif)),
                'tanpa_tarif'    => count($trips) - count($bertarif) - count($borongan),
            ],
            'trips' => $trips,
        ];
    }

    private function mapBaris(object $row, array $dropMap, array $biayaMap, array $biayaDetailMap, array $jenisMap, array $uangJalanTambahanMap, array $uangJalanTambahanDetailMap): array
    {
        $idJenisKendaraan = $row->id_jenis_kendaraan ?? $row->id_jenis_kendaraan_vendor ?? null;
        $borongan = TipeHarga::nilaiTetap($row->tipe_harga ?? null);

        $tarif = null;
        if (!$borongan && $row->id_rute !== null) {
            $baris = $this->proyekRuteRepo->findHarga(
                (string) $row->id_proyek,
                (string) $row->id_rute,
                $idJenisKendaraan !== null ? (string) $idJenisKendaraan : null,
            );
            if ($baris !== null && $baris->harga_penawaran !== null) {
                $tarif = ['harga' => (float) $baris->harga_penawaran, 'perkiraan' => (bool) ($baris->tarif_perkiraan ?? false)];
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
            'borongan'          => $borongan,
            'tipe_harga'        => $row->tipe_harga ?? 'per_rit',
            'sudah_difakturkan' => (int) $row->sudah_difakturkan === 1,
            'titik_drop'        => $dropMap[$row->id_trip] ?? [],
            'biaya_tambahan'    => ($biayaMap[$row->id_trip] ?? 0.0) + ($uangJalanTambahanMap[$row->id_trip] ?? 0.0),
            'biaya_tagihan'     => array_merge($biayaDetailMap[$row->id_trip] ?? [], $uangJalanTambahanDetailMap[$row->id_trip] ?? []),
            'jenis_kendaraan'   => $idJenisKendaraan !== null ? ($jenisMap[$idJenisKendaraan] ?? null) : null,
        ];
    }
}
