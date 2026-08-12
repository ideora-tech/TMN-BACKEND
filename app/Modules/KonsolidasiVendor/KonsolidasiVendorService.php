<?php

declare(strict_types=1);

namespace App\Modules\KonsolidasiVendor;

use App\Modules\KonsolidasiVendor\Contracts\KonsolidasiVendorRepositoryInterface;

class KonsolidasiVendorService
{
    public function __construct(private readonly KonsolidasiVendorRepositoryInterface $repo) {}

    public function rekap(string $idVendor, string $idPerusahaan, ?string $dari, ?string $sampai): array
    {
        $vendor = $this->repo->vendorInfo($idVendor, $idPerusahaan);
        if ($vendor === null) {
            abort(404, 'Vendor tidak ditemukan');
        }

        $trips = array_map(fn ($row) => [
            'id_trip'           => $row->id_trip,
            'tanggal'           => $row->tanggal,
            'nopol'             => $row->nopol,
            'supir_nama'        => $row->nama_supir_internal ?? $row->nama_supir_vendor,
            'rute'              => $row->nama_rute ?? $row->rute_teks,
            'jarak_tempuh_km'   => $row->jarak_tempuh_km !== null ? (float) $row->jarak_tempuh_km : null,
            'id_kontrak_vendor' => $row->id_kontrak_vendor,
            'mekanisme'         => $row->mekanisme,
        ], $this->repo->tripVendor($idPerusahaan, $idVendor, $dari, $sampai));

        $ritPerKontrak = [];
        foreach ($trips as $trip) {
            $ritPerKontrak[$trip['id_kontrak_vendor']] = ($ritPerKontrak[$trip['id_kontrak_vendor']] ?? 0) + 1;
        }

        $kontrak = [];
        foreach ($this->repo->kontrakVendor($idVendor) as $k) {
            $jumlahRit = $ritPerKontrak[$k->id_kontrak_vendor] ?? 0;
            if ($jumlahRit === 0) {
                continue;
            }
            $rate = $k->rate !== null ? (float) $k->rate : null;
            $kontrak[] = [
                'id_kontrak_vendor' => $k->id_kontrak_vendor,
                'mekanisme'         => $k->mekanisme,
                'rate'              => $rate,
                'satuan'            => $k->satuan,
                'nilai_kontrak'     => (float) $k->nilai_kontrak,
                'jumlah_rit'        => $jumlahRit,
                'nilai_seharusnya'  => ($k->satuan === 'per trip' && $rate !== null) ? $jumlahRit * $rate : null,
            ];
        }

        return [
            'vendor'    => ['id_vendor' => $vendor->id_vendor, 'nama_vendor' => $vendor->nama_vendor],
            'ringkasan' => [
                'total_rit'      => count($trips),
                'total_jarak_km' => array_sum(array_map(fn ($t) => $t['jarak_tempuh_km'] ?? 0, $trips)),
                'kontrak'        => $kontrak,
            ],
            'trips' => $trips,
        ];
    }
}
