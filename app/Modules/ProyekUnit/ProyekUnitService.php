<?php

declare(strict_types=1);

namespace App\Modules\ProyekUnit;

use App\Modules\ArmadaVendor\Contracts\ArmadaVendorRepositoryInterface;
use App\Modules\ProyekUnit\Contracts\ProyekUnitRepositoryInterface;
use App\Modules\SupirProyek\SupirProyekService;
use Illuminate\Support\Facades\DB;

class ProyekUnitService
{
    public const SUMBER_VALID = ['internal', 'vendor'];

    public function __construct(
        private readonly ProyekUnitRepositoryInterface $repo,
        private readonly ArmadaVendorRepositoryInterface $armadaVendorRepo,
        private readonly SupirProyekService $supirProyekService,
    ) {}

    /** @return object[] */
    public function list(string $idProyek, string $idPerusahaan): array
    {
        $this->pastikanProyekMilikPerusahaan($idProyek, $idPerusahaan);

        return $this->repo->listByProyek($idProyek, $idPerusahaan);
    }

    /** @return array<int, array<string, mixed>> */
    public function opsi(string $idProyek, string $idPerusahaan): array
    {
        $this->pastikanProyekMilikPerusahaan($idProyek, $idPerusahaan);
        $sudahAda = $this->repo->kunciUnitProyek($idProyek);

        return array_values(array_filter(
            $this->unitTersedia($idPerusahaan),
            fn (array $unit) => !isset($sudahAda[$this->kunciUnit($unit)]),
        ));
    }

    /** @return array<int, array<string, mixed>> */
    public function opsiProyekBaru(string $idPerusahaan): array
    {
        return $this->unitTersedia($idPerusahaan);
    }

    /**
     * @param array<int, array<string, mixed>> $units
     * @return array{unit: object[], supir_ditambahkan: int}
     */
    public function tambah(string $idProyek, array $units, string $idPerusahaan): array
    {
        $this->pastikanProyekMilikPerusahaan($idProyek, $idPerusahaan);

        $tersedia = [];
        foreach ($this->unitTersedia($idPerusahaan) as $unit) {
            $tersedia[$this->kunciUnit($unit)] = $unit;
        }

        [$idBaru, $supirDitambahkan] = DB::transaction(function () use ($idProyek, $units, $idPerusahaan, $tersedia) {
            $this->repo->kunciProyek($idProyek);
            $sudahAda = $this->repo->kunciUnitProyek($idProyek);
            $diminta = [];

            foreach ($units as $unit) {
                $kunci = $this->kunciUnit($unit);
                if (isset($sudahAda[$kunci]) || isset($diminta[$kunci])) {
                    abort(409, trim('Unit ' . ($tersedia[$kunci]['nopol'] ?? '')) . ' sudah terdaftar di proyek ini');
                }
                if (!isset($tersedia[$kunci])) {
                    abort(422, 'Unit tidak ditemukan atau tidak tersedia');
                }
                $diminta[$kunci] = true;
            }

            $idBaru = [];
            foreach ($units as $unit) {
                $vendor = $unit['sumber'] === 'vendor';
                $idBaru[] = $this->repo->create([
                    'id_perusahaan'    => $idPerusahaan,
                    'id_proyek'        => $idProyek,
                    'sumber'           => $unit['sumber'],
                    'id_armada'        => $vendor ? null : $unit['id_armada'],
                    'id_armada_vendor' => $vendor ? $unit['id_armada_vendor'] : null,
                ])->id_proyek_unit;
            }

            $idArmada = array_values(array_filter(array_map(
                static fn (array $unit) => $unit['sumber'] === 'vendor' ? null : (string) $unit['id_armada'],
                $units,
            )));
            $pemegang = $this->repo->idSupirPemegang($idArmada, $idPerusahaan);
            $supirDitambahkan = $pemegang === []
                ? 0
                : $this->supirProyekService->tambahBatch($idProyek, $pemegang, $idPerusahaan)['sukses'];

            return [$idBaru, $supirDitambahkan];
        });

        return [
            'unit' => array_values(array_filter(
                $this->repo->listByProyek($idProyek, $idPerusahaan),
                fn (object $baris) => in_array($baris->id_proyek_unit, $idBaru, true),
            )),
            'supir_ditambahkan' => $supirDitambahkan,
        ];
    }

    public function hapus(string $idProyek, string $id, string $idPerusahaan): void
    {
        $this->pastikanProyekMilikPerusahaan($idProyek, $idPerusahaan);

        $unit = $this->repo->findMilikProyek($idProyek, $id);
        if ($unit === null) {
            abort(404, 'Unit proyek tidak ditemukan');
        }

        $unit->softDelete();
    }

    /** @param string[] $ids */
    public function hapusMassal(string $idProyek, array $ids, string $idPerusahaan): int
    {
        $this->pastikanProyekMilikPerusahaan($idProyek, $idPerusahaan);

        return DB::transaction(function () use ($idProyek, $ids) {
            $units = $this->repo->findBanyakMilikProyek($idProyek, $ids);
            if (count($units) !== count($ids)) {
                abort(404, 'Sebagian unit tidak ditemukan di proyek ini — muat ulang halaman lalu coba lagi');
            }

            foreach ($units as $unit) {
                $unit->softDelete();
            }

            return count($units);
        });
    }

    private function pastikanProyekMilikPerusahaan(string $idProyek, string $idPerusahaan): void
    {
        if (!$this->repo->proyekMilikPerusahaan($idProyek, $idPerusahaan)) {
            abort(404, 'Proyek tidak ditemukan');
        }
    }

    /** @param array<string, mixed> $unit */
    private function kunciUnit(array $unit): string
    {
        $vendor = $unit['sumber'] === 'vendor';

        return ProyekUnitModel::kunci(
            (string) $unit['sumber'],
            $vendor ? ($unit['id_armada_vendor'] ?? null) : ($unit['id_armada'] ?? null),
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function unitTersedia(string $idPerusahaan): array
    {
        $internal = array_map(fn (object $armada) => [
            'sumber'           => 'internal',
            'id_armada'        => $armada->id_armada,
            'id_armada_vendor' => null,
            'nopol'            => $armada->nopol,
            'merk'             => $armada->merk,
            'nama_jenis'       => $armada->nama_jenis,
            'nama_vendor'      => null,
            'nama_supir'       => $armada->nama_supir,
        ], $this->repo->armadaTersedia($idPerusahaan));

        $opsiVendor = array_values(array_filter(
            $this->armadaVendorRepo->listOpsiBoard($idPerusahaan),
            fn (array $unit) => $unit['id_kontrak_vendor'] !== null && !$unit['kontrak_habis'],
        ));
        $namaSupir = $this->repo->namaSupirVendor(array_values(array_filter(array_column($opsiVendor, 'id_supir_vendor_default'))));
        $namaJenis = $this->repo->namaJenisArmadaVendor(array_column($opsiVendor, 'id_armada_vendor'));

        $vendor = array_map(fn (array $unit) => [
            'sumber'           => 'vendor',
            'id_armada'        => null,
            'id_armada_vendor' => $unit['id_armada_vendor'],
            'nopol'            => $unit['nopol'],
            'merk'             => $unit['merk'],
            'nama_jenis'       => $namaJenis[$unit['id_armada_vendor']] ?? $unit['jenis'],
            'nama_vendor'      => $unit['nama_vendor'],
            'nama_supir'       => $namaSupir[$unit['id_supir_vendor_default'] ?? ''] ?? null,
        ], $opsiVendor);

        return array_merge($internal, $vendor);
    }
}
