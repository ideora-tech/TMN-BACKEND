<?php

declare(strict_types=1);

namespace App\Modules\Dashboard;

use App\Modules\Dashboard\Contracts\DashboardRepositoryInterface;

class DashboardService
{
    public function __construct(private readonly DashboardRepositoryInterface $repo) {}

    public function getStats(string $idPerusahaan): array
    {
        $stats = $this->repo->stats($idPerusahaan);

        $dokumen = $this->repo->dokumenExpiring($idPerusahaan, 30)
            ->sortBy('berlaku_sampai')
            ->values();

        $tripTerlambat = $this->repo->tripTerlambat($idPerusahaan, 24);

        $servisJatuhTempo = $this->repo->servisJatuhTempo($idPerusahaan, 30)
            ->sortBy('jadwal_servis_berikutnya')
            ->values();

        $stats['alerts'] = [
            'dokumenExpiring' => [
                'total' => $dokumen->count(),
                'items' => $dokumen->take(10)->map(fn ($d) => [
                    'jenis_dokumen'  => $d->jenis_dokumen,
                    'pemilik'        => $d->pemilik,
                    'berlaku_sampai' => $d->berlaku_sampai,
                    'tipe'           => $d->tipe,
                ])->values()->all(),
            ],
            'tripTerlambat' => [
                'total' => $tripTerlambat->count(),
                'items' => $tripTerlambat->take(10)->map(fn ($t) => [
                    'id_trip'      => $t->id_trip,
                    'nama_proyek'  => $t->nama_proyek,
                    'jam_berjalan' => (int) now()->diffInHours(now()->parse($t->waktu_checkin), true),
                ])->values()->all(),
            ],
            'servisJatuhTempo' => [
                'total' => $servisJatuhTempo->count(),
                'items' => $servisJatuhTempo->take(10)->map(fn ($s) => [
                    'id_armada'                => $s->id_armada,
                    'nopol'                    => $s->nopol,
                    'jenis_perawatan'          => $s->jenis_perawatan,
                    'jadwal_servis_berikutnya' => $s->jadwal_servis_berikutnya,
                ])->values()->all(),
            ],
        ];

        return $stats;
    }
}
