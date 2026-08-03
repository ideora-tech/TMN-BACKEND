<?php

declare(strict_types=1);

namespace App\Modules\JadwalShift;

use App\Modules\JadwalShift\Contracts\JadwalShiftRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Support\Facades\DB;

class JadwalShiftRepository implements JadwalShiftRepositoryInterface
{
    private const COLUMNS = [
        'jadwal_shift.id_jadwal_shift', 'jadwal_shift.id_proyek', 'jadwal_shift.id_shift',
        'jadwal_shift.id_supir', 'jadwal_shift.tanggal',
    ];

    private const JOINED = [
        'shift.nama as shift_nama', 'shift.jam_mulai', 'shift.jam_selesai',
    ];

    public function listByProyek(string $idProyek, ?string $dari, ?string $sampai): array
    {
        return DB::table('jadwal_shift')
            ->join('shift', 'shift.id_shift', '=', 'jadwal_shift.id_shift')
            ->whereNull('jadwal_shift.dihapus_pada')
            ->where('jadwal_shift.id_proyek', $idProyek)
            ->when($dari, fn ($q, $v) => $q->where('jadwal_shift.tanggal', '>=', $v))
            ->when($sampai, fn ($q, $v) => $q->where('jadwal_shift.tanggal', '<=', $v))
            ->orderBy('jadwal_shift.tanggal')
            ->select(array_merge(self::COLUMNS, self::JOINED))
            ->get()
            ->all();
    }

    public function findById(string $id): ?object
    {
        return DB::table('jadwal_shift')
            ->join('shift', 'shift.id_shift', '=', 'jadwal_shift.id_shift')
            ->whereNull('jadwal_shift.dihapus_pada')
            ->where('jadwal_shift.id_jadwal_shift', $id)
            ->select(array_merge(self::COLUMNS, self::JOINED))
            ->first();
    }

    public function findAktifBySupirTanggal(string $idSupir, string $tanggal): ?object
    {
        return DB::table('jadwal_shift')
            ->join('shift', 'shift.id_shift', '=', 'jadwal_shift.id_shift')
            ->join('proyek', 'proyek.id_proyek', '=', 'jadwal_shift.id_proyek')
            ->whereNull('jadwal_shift.dihapus_pada')
            ->where('jadwal_shift.id_supir', $idSupir)
            ->where('jadwal_shift.tanggal', $tanggal)
            ->select(array_merge(self::COLUMNS, self::JOINED, ['proyek.nama_proyek']))
            ->lockForUpdate()
            ->first();
    }

    public function listShiftSupir(string $idSupir, string $dari, string $sampai): array
    {
        return DB::table('jadwal_shift')
            ->join('shift', 'shift.id_shift', '=', 'jadwal_shift.id_shift')
            ->whereNull('jadwal_shift.dihapus_pada')
            ->where('jadwal_shift.id_supir', $idSupir)
            ->where('jadwal_shift.tanggal', '>=', $dari)
            ->where('jadwal_shift.tanggal', '<=', $sampai)
            ->orderBy('jadwal_shift.tanggal')
            ->select(array_merge(self::COLUMNS, self::JOINED))
            ->get()
            ->all();
    }

    public function supirTerdaftarDiProyek(string $idProyek): array
    {
        return DB::table('penugasan as p')
            ->join('supir as s', 's.id_supir', '=', 'p.id_supir')
            ->whereNull('p.dihapus_pada')
            ->whereNull('s.dihapus_pada')
            ->where('p.id_proyek', $idProyek)
            ->where('p.sumber', 'internal')
            ->whereIn('p.status', ['pending', 'aktif'])
            ->distinct()
            ->orderBy('s.nama')
            ->select('s.id_supir', 's.nama', 's.no_sim')
            ->get()
            ->all();
    }

    public function supirByNoSim(string $noSim, string $idPerusahaan): ?object
    {
        return DB::table('supir')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('no_sim', $noSim)
            ->first();
    }

    public function shiftByNama(string $nama, string $idPerusahaan): ?object
    {
        return DB::table('shift')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('aktif', 1)
            ->whereRaw('LOWER(nama) = ?', [mb_strtolower($nama)])
            ->first();
    }

    public function supirPunyaPenugasan(string $idProyek, string $idSupir): bool
    {
        return DB::table('penugasan')
            ->whereNull('dihapus_pada')
            ->where('id_proyek', $idProyek)
            ->where('id_supir', $idSupir)
            ->where('sumber', 'internal')
            ->whereIn('status', ['pending', 'aktif'])
            ->exists();
    }

    public function proyekMilikPerusahaan(string $idProyek, string $idPerusahaan): bool
    {
        return DB::table('proyek')
            ->whereNull('dihapus_pada')
            ->where('id_proyek', $idProyek)
            ->where('id_perusahaan', $idPerusahaan)
            ->exists();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_jadwal_shift');
        DB::table('jadwal_shift')->insert($data);
        return $this->findById($data['id_jadwal_shift']);
    }

    public function updateShift(object $record, string $idShift): object
    {
        DB::table('jadwal_shift')
            ->where('id_jadwal_shift', $record->id_jadwal_shift)
            ->update(RecordHelper::stampUpdate(['id_shift' => $idShift]));
        return $this->findById($record->id_jadwal_shift);
    }

    public function delete(object $record): void
    {
        DB::table('jadwal_shift')
            ->where('id_jadwal_shift', $record->id_jadwal_shift)
            ->update(RecordHelper::stampDelete());
    }
}
