<?php

declare(strict_types=1);

namespace App\Modules\DokumenArmada;

use App\Modules\Armada\Contracts\ArmadaRepositoryInterface;
use App\Modules\DokumenArmada\Contracts\DokumenArmadaRepositoryInterface;
use App\Support\PenyimpananBerkas;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class DokumenArmadaService
{
    public const JENIS_BOLEH_GANDA = ['Lainnya'];
    private const MAKS_RIWAYAT = 50;

    public function __construct(
        private readonly DokumenArmadaRepositoryInterface $repo,
        private readonly ArmadaRepositoryInterface $armadaRepo,
    ) {}

    public function listByArmada(string $idArmada, string $idPerusahaan, int $page = 1, int $limit = 100): array
    {
        $this->pastikanArmada($idArmada, $idPerusahaan);
        return $this->toPagedArray($this->repo->paginateByArmada($idArmada, $page, $limit));
    }

    public function listByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idArmada, ?string $jenisDokumen, ?string $search = null): array
    {
        return $this->toPagedArray($this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $idArmada, $jenisDokumen, $search));
    }

    private function toPagedArray(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => $paginator->items(),
            'meta' => [
                'page'       => $paginator->currentPage(),
                'limit'      => $paginator->perPage(),
                'total'      => $paginator->total(),
                'totalPages' => $paginator->lastPage(),
            ],
        ];
    }

    public function detail(string $id, string $idPerusahaan): object
    {
        $record = $this->repo->findById($id);
        if ($record === null || $record->armada_id_perusahaan !== $idPerusahaan) {
            abort(404, 'Dokumen armada tidak ditemukan');
        }

        $record->riwayat = $this->rantaiRiwayat($record);
        $pengganti = (int) $record->aktif === 0 ? $this->repo->findPengganti($record->id_dokumen_armada) : null;
        $record->id_dokumen_pengganti = $pengganti?->id_dokumen_armada;

        return $record;
    }

    private function rantaiRiwayat(object $record): array
    {
        $riwayat = [];
        $dikunjungi = [$record->id_dokumen_armada => true];
        $idSebelumnya = $record->id_dokumen_sebelumnya;

        while ($idSebelumnya !== null && !isset($dikunjungi[$idSebelumnya]) && count($riwayat) < self::MAKS_RIWAYAT) {
            $dikunjungi[$idSebelumnya] = true;
            $lama = $this->repo->findById($idSebelumnya);
            if ($lama === null) {
                break;
            }
            $riwayat[] = $lama;
            $idSebelumnya = $lama->id_dokumen_sebelumnya;
        }

        return $riwayat;
    }

    public function getExpiring(string $idPerusahaan, int $days): array
    {
        return $this->repo->findExpiring($idPerusahaan, $days);
    }

    public function create(string $idArmada, array $data, ?UploadedFile $file, string $idPerusahaan): object
    {
        $this->pastikanArmada($idArmada, $idPerusahaan);
        $this->pastikanJenisBelumAda($idArmada, (string) $data['jenis_dokumen']);

        if ($file) {
            $data['url_file'] = PenyimpananBerkas::simpan($file, 'dokumen');
        }
        unset($data['file']);

        return $this->repo->create(array_merge($data, ['id_armada' => $idArmada, 'aktif' => 1]));
    }

    public function createBatch(string $idArmada, array $items, string $idPerusahaan): array
    {
        $this->pastikanArmada($idArmada, $idPerusahaan);

        $jenisDipakai = [];
        foreach ($items as $item) {
            $jenis = (string) $item['jenis_dokumen'];
            if (in_array($jenis, self::JENIS_BOLEH_GANDA, true)) {
                continue;
            }
            if (isset($jenisDipakai[$jenis])) {
                abort(422, "Dokumen {$jenis} diinput lebih dari sekali — satu unit hanya boleh punya satu dokumen {$jenis}");
            }
            $jenisDipakai[$jenis] = true;
            $this->pastikanJenisBelumAda($idArmada, $jenis);
        }

        return DB::transaction(function () use ($idArmada, $items) {
            $hasil = [];
            foreach ($items as $item) {
                $hasil[] = $this->repo->create([
                    'id_armada'      => $idArmada,
                    'jenis_dokumen'  => $item['jenis_dokumen'],
                    'nomor'          => $item['nomor'] ?? null,
                    'berlaku_sampai' => $item['berlaku_sampai'] ?? null,
                    'url_file'       => PenyimpananBerkas::simpan($item['file'], 'dokumen'),
                    'aktif'          => 1,
                ]);
            }
            return $hasil;
        });
    }

    public function update(string $idArmada, string $id, array $data, ?UploadedFile $file, string $idPerusahaan): object
    {
        $record = $this->findMilik($idArmada, $id, $idPerusahaan);
        if ((int) $record->aktif === 0) {
            abort(422, 'Dokumen riwayat tidak dapat diubah');
        }

        if (isset($data['jenis_dokumen']) && $data['jenis_dokumen'] !== $record->jenis_dokumen) {
            $this->pastikanJenisBelumAda($idArmada, (string) $data['jenis_dokumen'], $record->id_dokumen_armada);
        }

        if ($file) {
            $data['url_file'] = PenyimpananBerkas::simpan($file, 'dokumen');
        }
        unset($data['file']);

        return $this->repo->update($record, $data);
    }

    public function perpanjang(string $idArmada, string $id, array $data, UploadedFile $file, string $idPerusahaan): object
    {
        $lama = $this->findMilik($idArmada, $id, $idPerusahaan);
        if ((int) $lama->aktif === 0) {
            abort(422, 'Dokumen ini sudah diperpanjang sebelumnya — perpanjang dokumen yang berlaku saat ini');
        }

        if ($lama->berlaku_sampai !== null && strtotime((string) $data['berlaku_sampai']) <= strtotime((string) $lama->berlaku_sampai)) {
            abort(422, 'Tanggal berlaku baru harus setelah ' . date('d/m/Y', strtotime((string) $lama->berlaku_sampai)));
        }

        return DB::transaction(function () use ($lama, $data, $file) {
            $baru = $this->repo->create([
                'id_armada'             => $lama->id_armada,
                'jenis_dokumen'         => $lama->jenis_dokumen,
                'nomor'                 => array_key_exists('nomor', $data) ? $data['nomor'] : $lama->nomor,
                'berlaku_sampai'        => $data['berlaku_sampai'],
                'url_file'              => PenyimpananBerkas::simpan($file, 'dokumen'),
                'aktif'                 => 1,
                'id_dokumen_sebelumnya' => $lama->id_dokumen_armada,
            ]);
            $this->repo->update($lama, ['aktif' => 0]);
            return $baru;
        });
    }

    public function delete(string $idArmada, string $id, string $idPerusahaan): void
    {
        $record = $this->findMilik($idArmada, $id, $idPerusahaan);
        if ((int) $record->aktif === 0) {
            abort(422, 'Riwayat dokumen tidak dapat dihapus');
        }

        DB::transaction(function () use ($record) {
            $this->repo->delete($record);
            if ($record->id_dokumen_sebelumnya === null) {
                return;
            }
            $sebelumnya = $this->repo->findById($record->id_dokumen_sebelumnya);
            if ($sebelumnya !== null && (int) $sebelumnya->aktif === 0 && !$this->repo->adaAktif($record->id_armada, $sebelumnya->jenis_dokumen)) {
                $this->repo->update($sebelumnya, ['aktif' => 1]);
            }
        });
    }

    private function findMilik(string $idArmada, string $id, string $idPerusahaan): object
    {
        $record = $this->repo->findById($id);
        if ($record === null || $record->id_armada !== $idArmada || $record->armada_id_perusahaan !== $idPerusahaan) {
            abort(404, 'Dokumen armada tidak ditemukan');
        }
        return $record;
    }

    private function pastikanArmada(string $idArmada, string $idPerusahaan): void
    {
        $armada = $this->armadaRepo->findById($idArmada);
        if ($armada === null || $armada->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Armada tidak ditemukan');
        }
    }

    private function pastikanJenisBelumAda(string $idArmada, string $jenis, ?string $kecualiId = null): void
    {
        if (in_array($jenis, self::JENIS_BOLEH_GANDA, true)) {
            return;
        }
        if ($this->repo->adaAktif($idArmada, $jenis, $kecualiId)) {
            abort(422, "Dokumen {$jenis} untuk unit ini sudah ada — gunakan Perpanjang untuk memperbarui dokumennya");
        }
    }
}
