<?php

declare(strict_types=1);

namespace App\Modules\LampiranMaster;

use App\Modules\LampiranMaster\Contracts\LampiranMasterRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class LampiranMasterRepository implements LampiranMasterRepositoryInterface
{
    private const PEMILIK = [
        self::JENIS_VENDOR   => ['tabel' => 'vendor', 'kunci' => 'id_vendor'],
        self::JENIS_SUPPLIER => ['tabel' => 'supplier', 'kunci' => 'id_supplier'],
    ];

    private const COLUMNS = ['id_lampiran', 'jenis_pemilik', 'id_pemilik', 'url_file', 'nama_asli', 'tipe_mime', 'ukuran', 'dibuat_pada'];

    public function pemilikAda(string $jenis, string $idPemilik, string $idPerusahaan): bool
    {
        return $this->pemilik($jenis, $idPemilik)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->exists();
    }

    public function kunciPemilik(string $jenis, string $idPemilik): void
    {
        $this->pemilik($jenis, $idPemilik)->lockForUpdate()->value(self::PEMILIK[$jenis]['kunci']);
    }

    public function listByPemilik(string $jenis, string $idPemilik): array
    {
        return $this->lampiran($jenis, $idPemilik)
            ->orderBy('urutan')
            ->orderBy('dibuat_pada')
            ->orderBy('id_lampiran')
            ->get(self::COLUMNS)
            ->all();
    }

    public function hitung(string $jenis, string $idPemilik): int
    {
        return $this->lampiran($jenis, $idPemilik)->count();
    }

    public function urutanTerakhir(string $jenis, string $idPemilik): int
    {
        return (int) $this->lampiran($jenis, $idPemilik)->max('urutan');
    }

    public function insert(array $data): void
    {
        DB::table('lampiran_master')->insert(RecordHelper::stampCreate($data, 'id_lampiran'));
    }

    public function find(string $jenis, string $idPemilik, string $idLampiran): ?object
    {
        return $this->lampiran($jenis, $idPemilik)
            ->where('id_lampiran', $idLampiran)
            ->first(self::COLUMNS);
    }

    public function softDelete(string $idLampiran): void
    {
        DB::table('lampiran_master')
            ->where('id_lampiran', $idLampiran)
            ->update(RecordHelper::stampDelete());
    }

    private function pemilik(string $jenis, string $idPemilik): Builder
    {
        return DB::table(self::PEMILIK[$jenis]['tabel'])->where(self::PEMILIK[$jenis]['kunci'], $idPemilik);
    }

    private function lampiran(string $jenis, string $idPemilik): Builder
    {
        return DB::table('lampiran_master')
            ->whereNull('dihapus_pada')
            ->where('jenis_pemilik', $jenis)
            ->where('id_pemilik', $idPemilik);
    }
}
