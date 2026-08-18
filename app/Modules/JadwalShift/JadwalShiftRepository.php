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
        'jadwal_shift.id_supir_pengganti', 'jadwal_shift.id_armada_override',
    ];

    private const JOINED = [
        'shift.nama as shift_nama', 'shift.jam_mulai', 'shift.jam_selesai',
    ];

    public function listByProyek(string $idProyek, ?string $dari, ?string $sampai): array
    {
        return DB::table('jadwal_shift')
            ->join('shift', 'shift.id_shift', '=', 'jadwal_shift.id_shift')
            ->leftJoin('alokasi_armada', function ($join) {
                $join->on('alokasi_armada.id_supir', '=', 'jadwal_shift.id_supir')
                     ->on('alokasi_armada.tanggal', '=', 'jadwal_shift.tanggal')
                     ->whereNull('alokasi_armada.dihapus_pada');
            })
            ->leftJoin('armada', 'armada.id_armada', '=', 'alokasi_armada.id_armada')
            ->leftJoin('supir as supir_pengganti', 'supir_pengganti.id_supir', '=', 'jadwal_shift.id_supir_pengganti')
            ->leftJoin('armada as armada_override', 'armada_override.id_armada', '=', 'jadwal_shift.id_armada_override')
            ->whereNull('jadwal_shift.dihapus_pada')
            ->where('jadwal_shift.id_proyek', $idProyek)
            ->when($dari, fn ($q, $v) => $q->where('jadwal_shift.tanggal', '>=', $v))
            ->when($sampai, fn ($q, $v) => $q->where('jadwal_shift.tanggal', '<=', $v))
            ->orderBy('jadwal_shift.tanggal')
            ->select(array_merge(self::COLUMNS, self::JOINED, [
                'armada.nopol as nopol_alokasi',
                'alokasi_armada.sumber as sumber_alokasi',
                'supir_pengganti.nama as nama_supir_pengganti',
                'armada_override.nopol as nopol_override',
            ]))
            ->get()
            ->all();
    }

    public function findById(string $id): ?object
    {
        return DB::table('jadwal_shift')
            ->join('shift', 'shift.id_shift', '=', 'jadwal_shift.id_shift')
            ->leftJoin('supir as supir_pengganti', 'supir_pengganti.id_supir', '=', 'jadwal_shift.id_supir_pengganti')
            ->leftJoin('armada as armada_override', 'armada_override.id_armada', '=', 'jadwal_shift.id_armada_override')
            ->whereNull('jadwal_shift.dihapus_pada')
            ->where('jadwal_shift.id_jadwal_shift', $id)
            ->select(array_merge(self::COLUMNS, self::JOINED, [
                'supir_pengganti.nama as nama_supir_pengganti',
                'armada_override.nopol as nopol_override',
            ]))
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

    public function namaProyek(string $idProyek): ?string
    {
        $nama = DB::table('proyek')
            ->whereNull('dihapus_pada')
            ->where('id_proyek', $idProyek)
            ->value('nama_proyek');

        return $nama !== null ? (string) $nama : null;
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_jadwal_shift');
        DB::table('jadwal_shift')->insert($data);
        return $this->findById($data['id_jadwal_shift']);
    }

    public function findOverrideAktif(string $idSupir, string $idProyek, string $tanggal): ?object
    {
        return DB::table('jadwal_shift')
            ->whereNull('dihapus_pada')
            ->where('id_supir', $idSupir)
            ->where('id_proyek', $idProyek)
            ->where('tanggal', $tanggal)
            ->select(['id_jadwal_shift', 'id_supir_pengganti', 'id_armada_override'])
            ->first();
    }

    public function listTitikDropOverride(string $idJadwalShift): array
    {
        return $this->listTitikDropOverrideUntukBanyak([$idJadwalShift])[$idJadwalShift] ?? [];
    }

    public function listTitikDropOverrideUntukBanyak(array $idJadwalShiftList): array
    {
        if ($idJadwalShiftList === []) {
            return [];
        }

        return DB::table('titik_drop_jadwal_shift')
            ->whereIn('id_jadwal_shift', $idJadwalShiftList)
            ->whereNull('dihapus_pada')
            ->orderBy('urutan')
            ->get(['id_jadwal_shift', 'lokasi'])
            ->groupBy('id_jadwal_shift')
            ->map(fn ($g) => $g->pluck('lokasi')->all())
            ->all();
    }

    public function syncTitikDropOverride(string $idJadwalShift, array $lokasiList): void
    {
        DB::table('titik_drop_jadwal_shift')
            ->where('id_jadwal_shift', $idJadwalShift)->whereNull('dihapus_pada')
            ->update(RecordHelper::stampDelete());
        foreach (array_values($lokasiList) as $i => $lokasi) {
            DB::table('titik_drop_jadwal_shift')->insert(RecordHelper::stampCreate([
                'id_jadwal_shift' => $idJadwalShift,
                'urutan'          => $i + 1,
                'lokasi'          => trim((string) $lokasi),
            ], 'id_titik_drop'));
        }
    }

    public function updateShift(object $record, array $data): object
    {
        $update = [];
        if (array_key_exists('id_shift', $data)) {
            $update['id_shift'] = $data['id_shift'];
        }
        if (array_key_exists('id_supir_pengganti', $data)) {
            $update['id_supir_pengganti'] = $data['id_supir_pengganti'];
        }
        if (array_key_exists('id_armada_override', $data)) {
            $update['id_armada_override'] = $data['id_armada_override'];
        }
        DB::table('jadwal_shift')
            ->where('id_jadwal_shift', $record->id_jadwal_shift)
            ->update(RecordHelper::stampUpdate($update));
        return $this->findById($record->id_jadwal_shift);
    }

    public function delete(object $record): void
    {
        DB::table('jadwal_shift')
            ->where('id_jadwal_shift', $record->id_jadwal_shift)
            ->update(RecordHelper::stampDelete());
    }

    /**
     * Pindahkan kepemilikan jadwal_shift dari supir lama ke supir baru di satu
     * proyek, mulai tanggal tertentu (inklusif) — dipakai saat Edit Penugasan
     * mengganti supir, supaya jadwal yang sudah dibuat ikut ke supir baru,
     * bukan jadi nyangkut tak terlihat (papan mengambil baris dari penugasan
     * aktif, bukan dari jadwal_shift langsung). Tanggal yang bentrok dengan
     * jadwal supir baru di proyek lain dilewati — aturan 1 shift/hari
     * GLOBAL tetap berlaku.
     *
     * @return array{dipindah: string[], dilewati: string[]}
     */
    public function pindahkanKepemilikan(string $idProyek, string $supirLama, string $supirBaru, string $dariTanggal): array
    {
        $rows = DB::table('jadwal_shift')
            ->whereNull('dihapus_pada')
            ->where('id_proyek', $idProyek)
            ->where('id_supir', $supirLama)
            ->where('tanggal', '>=', $dariTanggal)
            ->get(['id_jadwal_shift', 'tanggal']);

        $dipindah = [];
        $dilewati = [];

        foreach ($rows as $row) {
            $bentrok = DB::table('jadwal_shift')
                ->whereNull('dihapus_pada')
                ->where('id_supir', $supirBaru)
                ->where('tanggal', $row->tanggal)
                ->exists();

            if ($bentrok) {
                $dilewati[] = (string) $row->tanggal;
                continue;
            }

            DB::table('jadwal_shift')
                ->where('id_jadwal_shift', $row->id_jadwal_shift)
                ->update(RecordHelper::stampUpdate(['id_supir' => $supirBaru]));
            $dipindah[] = (string) $row->tanggal;
        }

        return ['dipindah' => $dipindah, 'dilewati' => $dilewati];
    }

    /**
     * Penugasan baru dibuat untuk supir yang mulai tanggal tertentu (biasanya
     * hari ini) masih punya jadwal_shift nyangkut dari penugasan lain di
     * proyek yang sama yang sudah selesai/batal — jadwal itu dihapus supaya
     * papan bersih, assign shift baru tidak kejegal aturan 1-shift/hari
     * global, dan trip baru tidak kewarisan supir/armada pengganti dari
     * penugasan yang sudah tidak berlaku. Pemanggil wajib pastikan dulu tidak
     * ada penugasan aktif lain (sumber apa pun) yang masih butuh jadwal ini.
     *
     * @return string[] tanggal yang dihapus (unik)
     */
    public function hapusOrphanUntukSupirProyek(string $idProyek, string $idSupir, string $dariTanggal): array
    {
        $rows = DB::table('jadwal_shift')
            ->whereNull('dihapus_pada')
            ->where('id_proyek', $idProyek)
            ->where('id_supir', $idSupir)
            ->where('tanggal', '>=', $dariTanggal)
            ->get(['id_jadwal_shift', 'tanggal']);

        if ($rows->isEmpty()) {
            return [];
        }

        DB::table('jadwal_shift')
            ->whereIn('id_jadwal_shift', $rows->pluck('id_jadwal_shift'))
            ->update(RecordHelper::stampDelete());

        return $rows->pluck('tanggal')->map(fn ($t) => (string) $t)->unique()->values()->all();
    }
}
