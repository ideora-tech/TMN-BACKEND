<?php

declare(strict_types=1);

namespace App\Modules\PengaturanKode;

use App\Modules\PengaturanKode\Contracts\PengaturanKodeRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Support\Facades\DB;

class PengaturanKodeRepository implements PengaturanKodeRepositoryInterface
{
    private const COLUMNS = [
        'id_pengaturan_kode', 'id_perusahaan', 'entitas', 'prefix', 'panjang_digit', 'reset',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    public function allByPerusahaan(string $idPerusahaan): array
    {
        return DB::table('pengaturan_kode')
            ->select(self::COLUMNS)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->get()
            ->keyBy('entitas')
            ->all();
    }

    public function findByEntitas(string $idPerusahaan, string $entitas): ?object
    {
        return DB::table('pengaturan_kode')
            ->select(self::COLUMNS)
            ->where('id_perusahaan', $idPerusahaan)
            ->where('entitas', $entitas)
            ->whereNull('dihapus_pada')
            ->first();
    }

    public function upsert(string $idPerusahaan, string $entitas, array $data): object
    {
        $data = RecordHelper::stampCreate(array_merge($data, [
            'id_perusahaan' => $idPerusahaan,
            'entitas'       => $entitas,
        ]), 'id_pengaturan_kode');

        $data['diubah_pada'] = now();
        $data['diubah_oleh'] = auth()->id();

        DB::table('pengaturan_kode')->upsert(
            [$data],
            ['id_perusahaan', 'entitas'],
            ['prefix', 'panjang_digit', 'reset', 'diubah_pada', 'diubah_oleh']
        );

        return $this->findByEntitas($idPerusahaan, $entitas);
    }
}
