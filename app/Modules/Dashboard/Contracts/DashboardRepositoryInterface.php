<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Contracts;

use Illuminate\Support\Collection;

interface DashboardRepositoryInterface
{
    /**
     * Angka-angka dasar dashboard (tripBerjalan, armadaTersedia, armadaBeroperasi,
     * proyekBerjalan, fakturDraft, pendapatanBulanIni, piutangBeredar) untuk 1 perusahaan.
     */
    public function stats(string $idPerusahaan): array;

    /**
     * Dokumen armada + dokumen vendor yang akan kadaluarsa dalam $days hari ke depan.
     * Setiap baris: {jenis_dokumen, pemilik, berlaku_sampai, tipe: 'armada'|'vendor'}.
     */
    public function dokumenExpiring(string $idPerusahaan, int $days = 30): Collection;

    /**
     * Trip berstatus 'berjalan' yang sudah checkin lebih dari $jamBatas jam tanpa checkout.
     * Setiap baris: {id_trip, nama_proyek, waktu_checkin}.
     */
    public function tripTerlambat(string $idPerusahaan, int $jamBatas = 24): Collection;
}
