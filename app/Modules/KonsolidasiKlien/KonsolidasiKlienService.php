<?php

declare(strict_types=1);

namespace App\Modules\KonsolidasiKlien;

use App\Modules\KonsolidasiKlien\Contracts\KonsolidasiKlienRepositoryInterface;
use App\Modules\ProyekRute\Contracts\ProyekRuteRepositoryInterface;
use App\Support\ParameterTagihan;
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
        $jenisMap  = $this->repo->namaJenisKendaraanMap(array_values(array_unique(array_filter(array_map(
            fn ($r) => $r->id_jenis_kendaraan ?? $r->id_jenis_kendaraan_vendor ?? null,
            $rows
        )))));

        $suratJalanMap = $this->repo->suratJalanPerTrip($idTrips);

        $trips = array_map(
            fn ($row) => $this->mapBaris($row, $dropMap, $biayaMap, $biayaDetailMap, $jenisMap, $suratJalanMap[$row->id_trip] ?? []),
            $rows
        );

        $tertagih = array_filter($trips, fn ($t) => $t['total_tagihan'] !== null);
        $borongan = array_filter($trips, fn ($t) => $t['borongan'] === true);

        return [
            'klien'     => ['id_klien' => $klien->id_klien, 'nama_klien' => $klien->nama_klien],
            'ringkasan' => [
                'total_rit'      => count($trips),
                'total_jarak_km' => array_sum(array_map(fn ($t) => $t['jarak_tempuh_km'] ?? 0, $trips)),
                'estimasi_nilai' => array_sum(array_map(fn ($t) => $t['total_tagihan'], $tertagih)),
                'tanpa_tarif'    => count($trips) - count($tertagih) - count($borongan),
            ],
            'trips' => $trips,
        ];
    }

    private function mapBaris(object $row, array $dropMap, array $biayaMap, array $biayaDetailMap, array $jenisMap, array $suratJalan = []): array
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

        $rincian = $borongan ? null : ParameterTagihan::rincian($tarif['harga'] ?? null, $row);
        $biayaTambahan = $biayaMap[$row->id_trip] ?? 0.0;
        $bisaDitagih = $rincian !== null && $rincian['harga_dasar'] !== null;

        return [
            'id_trip'           => $row->id_trip,
            'status'            => $row->status ?? 'selesai',
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
            'no_surat_jalan'    => $suratJalan !== [] ? implode(', ', array_column($suratJalan, 'no_surat_jalan')) : $row->no_surat_jalan,
            'surat_jalan'       => $suratJalan,
            'tarif'             => $tarif,
            'borongan'          => $borongan,
            'tipe_harga'        => $row->tipe_harga ?? 'per_rit',
            'sudah_difakturkan' => (int) $row->sudah_difakturkan === 1,
            'titik_drop'        => $dropMap[$row->id_trip] ?? [],
            'biaya_tambahan'    => $biayaTambahan,
            'biaya_tagihan'     => $biayaDetailMap[$row->id_trip] ?? [],
            'parameter'         => [
                'cancellation'    => $rincian['cancellation'] ?? false,
                'komponen'        => $rincian['komponen'] ?? [],
                'total'           => $rincian['total_parameter'] ?? 0.0,
                'keterangan'      => $rincian['keterangan'] ?? null,
            ],
            'harga_dasar'       => $rincian['harga_dasar'] ?? null,
            'bisa_ditagih'      => $bisaDitagih,
            'total_tagihan'     => $bisaDitagih ? $rincian['harga_dasar'] + $rincian['total_parameter'] + $biayaTambahan : null,
            'jenis_kendaraan'   => $idJenisKendaraan !== null ? ($jenisMap[$idJenisKendaraan] ?? null) : null,
        ];
    }
}
