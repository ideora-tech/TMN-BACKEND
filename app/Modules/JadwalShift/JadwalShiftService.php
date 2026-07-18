<?php

declare(strict_types=1);

namespace App\Modules\JadwalShift;

use App\Modules\JadwalShift\Contracts\JadwalShiftRepositoryInterface;
use Illuminate\Support\Facades\DB;

class JadwalShiftService
{
    public function __construct(private readonly JadwalShiftRepositoryInterface $repo) {}

    public function list(string $idProyek, string $idPerusahaan, ?string $dari, ?string $sampai): array
    {
        if (!$this->repo->proyekMilikPerusahaan($idProyek, $idPerusahaan)) {
            abort(404, 'Proyek tidak ditemukan');
        }
        return $this->repo->listByProyek($idProyek, $dari, $sampai);
    }

    public function findOrFail(string $id): object
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Jadwal shift tidak ditemukan');
        }
        return $record;
    }

    /**
     * Batch assign: satu shift + rentang tanggal (tanggal..tanggal_sampai, opsional) + banyak supir.
     * Aturan per (supir, tanggal) — gagal per-item, bukan gagal total:
     * - wajib punya penugasan internal pending/aktif di proyek;
     * - maks 1 shift per tanggal GLOBAL lintas proyek (soft-delete aware) —
     *   tanggal yang bentrok dilewati dan dilaporkan, tanggal lain tetap terisi.
     */
    public function createBatch(array $data, string $idPerusahaan): array
    {
        if (!$this->repo->proyekMilikPerusahaan($data['id_proyek'], $idPerusahaan)) {
            abort(404, 'Proyek tidak ditemukan');
        }

        $mulai   = \Carbon\Carbon::parse($data['tanggal']);
        $selesai = \Carbon\Carbon::parse($data['tanggal_sampai'] ?? $data['tanggal']);

        if ($mulai->diffInDays($selesai) > 62) {
            abort(422, 'Rentang tanggal maksimal 62 hari');
        }

        $periode = [];
        for ($t = $mulai->copy(); $t->lte($selesai); $t->addDay()) {
            $periode[] = $t->toDateString();
        }

        return DB::transaction(function () use ($data, $periode) {
            $sukses = 0;
            $gagal  = [];

            foreach (array_unique($data['supir']) as $idSupir) {
                if (!$this->repo->supirPunyaPenugasan($data['id_proyek'], $idSupir)) {
                    foreach ($periode as $tanggal) {
                        $gagal[] = ['id_supir' => $idSupir, 'tanggal' => $tanggal, 'alasan' => 'Supir tidak ter-assign ke proyek ini'];
                    }
                    continue;
                }

                foreach ($periode as $tanggal) {
                    $ada = $this->repo->findAktifBySupirTanggal($idSupir, $tanggal);
                    if ($ada !== null) {
                        $gagal[] = [
                            'id_supir' => $idSupir,
                            'tanggal'  => $tanggal,
                            'alasan'   => "Supir sudah dijadwalkan shift {$ada->shift_nama} (proyek {$ada->nama_proyek})",
                        ];
                        continue;
                    }

                    $this->repo->create([
                        'id_proyek' => $data['id_proyek'],
                        'id_shift'  => $data['id_shift'],
                        'id_supir'  => $idSupir,
                        'tanggal'   => $tanggal,
                    ]);
                    $sukses++;
                }
            }

            return ['sukses' => $sukses, 'gagal' => $gagal];
        });
    }

    public function updateShift(string $id, string $idShift): object
    {
        $record = $this->findOrFail($id);
        return $this->repo->updateShift($record, $idShift);
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);
        $this->repo->delete($record);
    }
}
