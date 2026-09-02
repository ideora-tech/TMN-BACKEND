<?php

declare(strict_types=1);

namespace App\Modules\KontrakVendor;

use App\Modules\ArmadaVendor\Contracts\ArmadaVendorRepositoryInterface;
use App\Modules\ArmadaVendor\Imports\ArmadaVendorImport;
use App\Modules\KontrakVendor\Contracts\KontrakVendorRepositoryInterface;
use App\Modules\SupirVendor\Contracts\SupirVendorRepositoryInterface;
use App\Modules\SupirVendor\Imports\SupirVendorImport;
use App\Support\ExcelCellHelper;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class KontrakVendorService
{
    private const TAHUN_MIN = 1950;
    private const TAHUN_MAX = 2100;

    public function __construct(
        private readonly KontrakVendorRepositoryInterface $repo,
        private readonly ArmadaVendorRepositoryInterface $armadaVendorRepo,
        private readonly SupirVendorRepositoryInterface $supirVendorRepo,
    ) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $idVendor = null, ?string $search = null): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $idVendor, $search);

        return [
            'data' => $result->items(),
            'meta' => [
                'page'       => $result->currentPage(),
                'limit'      => $result->perPage(),
                'total'      => $result->total(),
                'totalPages' => $result->lastPage(),
            ],
        ];
    }

    public function listByProyek(string $idPerusahaan, string $idProyek, int $page = 1, int $limit = 10): array
    {
        $result = $this->repo->paginateByProyek($idPerusahaan, $idProyek, $page, $limit);

        return [
            'data' => $result->items(),
            'meta' => [
                'page'       => $result->currentPage(),
                'limit'      => $result->perPage(),
                'total'      => $result->total(),
                'totalPages' => $result->lastPage(),
            ],
        ];
    }

    public function findOrFail(string $id, string $idPerusahaan): KontrakVendorModel
    {
        $record = $this->repo->findAktifMilikPerusahaan($id, $idPerusahaan);
        if ($record === null) {
            abort(404, 'Kontrak vendor tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): KontrakVendorModel
    {
        if (!$this->repo->vendorMilikPerusahaan($data['id_vendor'], $data['id_perusahaan'])) {
            abort(404, 'Vendor tidak ditemukan');
        }

        $unitList  = $data['unit'] ?? [];
        $supirList = $data['supir'] ?? [];
        $salinDari = $data['salin_dari_kontrak'] ?? null;
        unset($data['unit'], $data['supir'], $data['salin_dari_kontrak']);

        if ($supirList !== [] && ($data['mekanisme'] ?? '') === 'unit_only') {
            abort(422, 'Supir vendor hanya untuk kontrak bermekanisme unit_driver atau full');
        }

        $kontrakSumber = null;
        if (!empty($salinDari)) {
            $kontrakSumber = $this->repo->findAktifMilikPerusahaan((string) $salinDari, (string) $data['id_perusahaan']);
            if ($kontrakSumber === null) {
                abort(404, 'Kontrak sumber salin tidak ditemukan');
            }
            if ((string) $kontrakSumber->id_vendor !== (string) $data['id_vendor']) {
                abort(422, 'Kontrak sumber salin bukan milik vendor yang sama');
            }
        }

        $this->validasiUnitBaru($unitList, (string) $data['id_perusahaan']);
        $this->validasiSupirBaru($supirList, (string) $data['id_perusahaan']);

        // Kolom nilai_kontrak NOT NULL DEFAULT 0 — input kosong dari klien
        // (null) dinormalisasi supaya tidak meledak di constraint DB.
        $data['nilai_kontrak'] = (float) ($data['nilai_kontrak'] ?? 0);

        // Kontrak baru selalu lahir draft — aktif hanya lewat approval.
        $data['status'] = 'draft';

        return DB::transaction(function () use ($data, $unitList, $supirList, $kontrakSumber) {
            $kontrak = $this->repo->create($data);

            $unitDiambilAlih = [];
            $idSupirPerIndex = [];
            foreach ($supirList as $indexSupir => $supir) {
                $dibuat = $this->supirVendorRepo->create(array_merge($supir, [
                    'id_vendor'         => $kontrak->id_vendor,
                    'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
                ]));
                $idSupirPerIndex[$indexSupir] = (string) $dibuat->id_supir_vendor;
            }

            foreach ($unitList as $unit) {
                $supirIndex = $unit['supir_index'] ?? null;
                unset($unit['supir_index']);

                if ($supirIndex !== null && !isset($idSupirPerIndex[(int) $supirIndex])) {
                    abort(422, "Pasangan supir untuk unit {$unit['nopol']} tidak ditemukan di daftar supir");
                }

                $dataUnit = array_merge($unit, [
                    'id_vendor'               => $kontrak->id_vendor,
                    'id_kontrak_vendor'       => $kontrak->id_kontrak_vendor,
                    'id_supir_vendor_default' => $supirIndex !== null ? $idSupirPerIndex[(int) $supirIndex] : null,
                ]);

                $adopsi = $this->armadaVendorRepo->findAktifTanpaKontrakByNopol((string) $unit['nopol'], (string) $kontrak->id_perusahaan);
                if ($adopsi !== null) {
                    if ((string) $adopsi->id_vendor !== (string) $kontrak->id_vendor) {
                        $pemilikLama = $this->armadaVendorRepo->infoNopolTerdaftar((string) $unit['nopol'], (string) $kontrak->id_perusahaan);
                        $unitDiambilAlih[] = "{$unit['nopol']} (sebelumnya vendor \"" . ($pemilikLama->nama_vendor ?? '-') . '")';
                    }
                    $this->armadaVendorRepo->update($adopsi, $dataUnit);
                } else {
                    $this->armadaVendorRepo->create($dataUnit);
                }
            }

            if ($unitDiambilAlih !== []) {
                $kontrak->unit_diambil_alih = $unitDiambilAlih;
            }

            if ($kontrakSumber !== null) {
                $this->repo->relinkUnitDanSupir((string) $kontrakSumber->id_kontrak_vendor, (string) $kontrak->id_kontrak_vendor);
            }

            return $kontrak;
        });
    }

    private function validasiUnitBaru(array $unitList, string $idPerusahaan): void
    {
        $frekuensi = [];
        foreach ($unitList as $unit) {
            $kunci = mb_strtoupper(trim((string) $unit['nopol']));
            $frekuensi[$kunci] = ($frekuensi[$kunci] ?? 0) + 1;
        }

        foreach ($unitList as $unit) {
            $nopol = (string) $unit['nopol'];
            if (($frekuensi[mb_strtoupper(trim($nopol))] ?? 0) > 1) {
                abort(422, "Nopol {$nopol} duplikat di dalam daftar unit");
            }
            // Nopol yang sudah terdaftar tetap boleh selama unitnya sedang TIDAK
            // terikat kontrak — vendor manapun (unit bisa berpindah vendor di
            // lapangan); nanti diambil alih (di-relink + pindah vendor) alih-alih
            // dibuat baru. Yang ditolak hanya unit yang masih terikat kontrak aktif.
            if ($this->armadaVendorRepo->nopolTerdaftar($nopol, $idPerusahaan)
                && $this->armadaVendorRepo->findAktifTanpaKontrakByNopol($nopol, $idPerusahaan) === null) {
                $pemilik = $this->armadaVendorRepo->infoNopolTerdaftar($nopol, $idPerusahaan);
                abort(422, "Nopol {$nopol} masih terikat kontrak aktif"
                    . ($pemilik !== null ? " milik vendor \"{$pemilik->nama_vendor}\"" : '')
                    . ' — akhiri/lepaskan kontrak lamanya dulu');
            }
            if (!empty($unit['id_jenis_kendaraan'])
                && !$this->armadaVendorRepo->jenisKendaraanMilikPerusahaan((string) $unit['id_jenis_kendaraan'], $idPerusahaan)) {
                abort(404, 'Jenis kendaraan tidak ditemukan');
            }
        }
    }

    private function validasiSupirBaru(array $supirList, string $idPerusahaan): void
    {
        $frekuensi = [];
        foreach ($supirList as $supir) {
            if (!empty($supir['no_sim'])) {
                $kunci = mb_strtoupper(trim((string) $supir['no_sim']));
                $frekuensi[$kunci] = ($frekuensi[$kunci] ?? 0) + 1;
            }
        }

        foreach ($supirList as $supir) {
            if (empty($supir['no_sim'])) {
                continue;
            }
            $noSim = (string) $supir['no_sim'];
            if (($frekuensi[mb_strtoupper(trim($noSim))] ?? 0) > 1) {
                abort(422, "No SIM {$noSim} duplikat di dalam daftar supir");
            }
            if ($this->supirVendorRepo->noSimTerdaftar($noSim, $idPerusahaan)) {
                abort(422, "No SIM {$noSim} sudah terdaftar");
            }
        }
    }

    /**
     * Timpa daftar unit kontrak dari excel: nopol yang cocok diperbarui,
     * baris baru ditambahkan, unit yang tidak ada lagi di excel di-soft-delete.
     * Riwayat penugasan unit lama tetap utuh (soft delete, id tidak berubah).
     */
    public function timpaUnit(string $id, UploadedFile $file, string $idPerusahaan): array
    {
        $kontrak = $this->findOrFail($id, $idPerusahaan);
        $parse   = $this->parseUnit($file, $idPerusahaan);

        $gagal = array_map(
            fn (array $g) => ['label' => 'Baris ' . $g['baris'], 'alasan' => $g['alasan']],
            $parse['baris_gagal'],
        );

        return DB::transaction(function () use ($kontrak, $parse, $idPerusahaan, $gagal) {
            $lama = $this->armadaVendorRepo->listAktifByKontrak((string) $kontrak->id_kontrak_vendor)
                ->keyBy(fn ($u) => mb_strtoupper(trim((string) $u->nopol)));

            $ditambah = 0;
            $diperbarui = 0;
            $kunciBaru = [];

            foreach ($parse['baris_valid'] as $unit) {
                $nopol = (string) $unit['nopol'];
                $kunci = mb_strtoupper(trim($nopol));
                if (isset($kunciBaru[$kunci])) {
                    $gagal[] = ['label' => $nopol, 'alasan' => 'Nopol duplikat di dalam file'];
                    continue;
                }
                $kunciBaru[$kunci] = true;

                $ada = $lama->get($kunci);
                if ($ada !== null) {
                    $this->armadaVendorRepo->update($ada, [
                        'merk'               => $unit['merk'] ?? null,
                        'jenis'              => $unit['jenis'] ?? null,
                        'id_jenis_kendaraan' => $unit['id_jenis_kendaraan'] ?? null,
                        'kapasitas'          => $unit['kapasitas'] ?? null,
                        'tahun'              => $unit['tahun'] ?? null,
                        'masa_berlaku_stnk'  => $unit['masa_berlaku_stnk'] ?? null,
                        'masa_berlaku_kir'   => $unit['masa_berlaku_kir'] ?? null,
                    ]);
                    $diperbarui++;
                    continue;
                }

                $adopsi = $this->armadaVendorRepo->findAktifTanpaKontrakByNopol($nopol, $idPerusahaan);
                if ($adopsi !== null) {
                    $this->armadaVendorRepo->update($adopsi, array_merge($unit, [
                        'id_vendor'         => $kontrak->id_vendor,
                        'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
                    ]));
                    $ditambah++;
                    continue;
                }

                if ($this->armadaVendorRepo->nopolTerdaftar($nopol, $idPerusahaan)) {
                    $gagal[] = ['label' => $nopol, 'alasan' => 'Nopol sudah terdaftar di kontrak/vendor lain'];
                    continue;
                }

                $this->armadaVendorRepo->create(array_merge($unit, [
                    'id_vendor'         => $kontrak->id_vendor,
                    'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
                ]));
                $ditambah++;
            }

            $dihapus = 0;
            foreach ($lama as $kunci => $model) {
                if (!isset($kunciBaru[$kunci])) {
                    if ($this->repo->adaPenugasanNonFinalUntukArmadaVendor((string) $model->id_armada_vendor)) {
                        $gagal[] = ['label' => (string) $model->nopol, 'alasan' => 'Tidak dihapus — masih dipakai penugasan aktif, selesaikan/batalkan dulu penugasannya'];
                        continue;
                    }
                    $this->armadaVendorRepo->delete($model);
                    $dihapus++;
                }
            }

            if ($ditambah > 0 || $dihapus > 0) {
                $this->tarikApprovalKarenaUnit((string) $kontrak->id_kontrak_vendor, $idPerusahaan);
            }

            return [
                'ditambah'   => $ditambah,
                'diperbarui' => $diperbarui,
                'dihapus'    => $dihapus,
                'gagal'      => $gagal,
            ];
        });
    }

    /**
     * Timpa pasangan unit+driver kontrak paket dari satu file excel gabungan.
     * Unit disinkronkan per nopol; driver bawaan tiap unit ikut disinkronkan
     * (update data / buat baru / dilepas jadi cadangan bila kolom driver kosong).
     * Driver cadangan (tidak dirujuk baris manapun) tidak disentuh.
     */
    public function timpaPasangan(string $id, UploadedFile $file, string $idPerusahaan): array
    {
        $kontrak = $this->findOrFail($id, $idPerusahaan);

        if ($kontrak->mekanisme === 'unit_only') {
            abort(422, 'Template pasangan hanya untuk kontrak bermekanisme unit_driver atau full');
        }

        $parse = $this->parsePasangan($file, $idPerusahaan);

        $gagal = array_map(
            fn (array $g) => ['label' => 'Baris ' . $g['baris'], 'alasan' => $g['alasan']],
            $parse['baris_gagal'],
        );

        return DB::transaction(function () use ($kontrak, $parse, $idPerusahaan, $gagal) {
            $lama = $this->armadaVendorRepo->listAktifByKontrak((string) $kontrak->id_kontrak_vendor)
                ->keyBy(fn ($u) => mb_strtoupper(trim((string) $u->nopol)));

            $unitDitambah = 0;
            $unitDiperbarui = 0;
            $driverDitambah = 0;
            $driverDiperbarui = 0;
            $driverDilepas = 0;
            $kunciBaru = [];

            foreach ($parse['baris_valid'] as $baris) {
                $nopol = (string) $baris['nopol'];
                $kunci = mb_strtoupper(trim($nopol));
                if (isset($kunciBaru[$kunci])) {
                    $gagal[] = ['label' => $nopol, 'alasan' => 'Nopol duplikat di dalam file'];
                    continue;
                }
                $kunciBaru[$kunci] = true;

                $driverNama    = $baris['driver_nama'] ?? null;
                $driverTelepon = $baris['driver_telepon'] ?? null;
                $driverNoSim   = $baris['driver_no_sim'] ?? null;
                $dataUnit = [
                    'merk'               => $baris['merk'] ?? null,
                    'jenis'              => $baris['jenis'] ?? null,
                    'id_jenis_kendaraan' => $baris['id_jenis_kendaraan'] ?? null,
                    'kapasitas'          => $baris['kapasitas'] ?? null,
                    'tahun'              => $baris['tahun'] ?? null,
                    'masa_berlaku_stnk'  => $baris['masa_berlaku_stnk'] ?? null,
                    'masa_berlaku_kir'   => $baris['masa_berlaku_kir'] ?? null,
                ];

                $ada = $lama->get($kunci);

                if ($ada === null) {
                    $adopsi = $this->armadaVendorRepo->findAktifTanpaKontrakByNopol($nopol, $idPerusahaan);
                    if ($adopsi !== null) {
                        $dataUnit['id_vendor']         = $kontrak->id_vendor;
                        $dataUnit['id_kontrak_vendor'] = $kontrak->id_kontrak_vendor;
                        $ada = $adopsi;
                    }
                }

                if ($ada === null && $this->armadaVendorRepo->nopolTerdaftar($nopol, $idPerusahaan)) {
                    $gagal[] = ['label' => $nopol, 'alasan' => 'Nopol sudah terdaftar di kontrak/vendor lain'];
                    continue;
                }

                $idDriverDefault = $ada?->id_supir_vendor_default;

                if ($driverNama === null) {
                    if ($idDriverDefault !== null) {
                        $driverDilepas++;
                    }
                    $idDriverDefault = null;
                } elseif ($idDriverDefault !== null) {
                    $driverLama = $this->supirVendorRepo->findByIdMilikPerusahaan((string) $idDriverDefault, $idPerusahaan);
                    if ($driverLama !== null) {
                        $this->supirVendorRepo->update($driverLama, [
                            'nama'    => $driverNama,
                            'telepon' => $driverTelepon,
                            'no_sim'  => $driverNoSim,
                        ]);
                        $driverDiperbarui++;
                    }
                } else {
                    if ($driverNoSim !== null && $this->supirVendorRepo->noSimTerdaftar($driverNoSim, $idPerusahaan)) {
                        $gagal[] = ['label' => $nopol, 'alasan' => "No SIM {$driverNoSim} sudah terdaftar — unit disimpan tanpa driver"];
                        $idDriverDefault = null;
                    } else {
                        $driverBaru = $this->supirVendorRepo->create([
                            'id_vendor'         => $kontrak->id_vendor,
                            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
                            'nama'              => $driverNama,
                            'telepon'           => $driverTelepon,
                            'no_sim'            => $driverNoSim,
                        ]);
                        $idDriverDefault = (string) $driverBaru->id_supir_vendor;
                        $driverDitambah++;
                    }
                }

                if ($ada !== null) {
                    $this->armadaVendorRepo->update($ada, array_merge($dataUnit, [
                        'id_supir_vendor_default' => $idDriverDefault,
                    ]));
                    $unitDiperbarui++;
                } else {
                    $this->armadaVendorRepo->create(array_merge($dataUnit, [
                        'nopol'                   => $nopol,
                        'id_vendor'               => $kontrak->id_vendor,
                        'id_kontrak_vendor'       => $kontrak->id_kontrak_vendor,
                        'id_supir_vendor_default' => $idDriverDefault,
                    ]));
                    $unitDitambah++;
                }
            }

            $unitDihapus = 0;
            foreach ($lama as $kunci => $model) {
                if (!isset($kunciBaru[$kunci])) {
                    if ($this->repo->adaPenugasanNonFinalUntukArmadaVendor((string) $model->id_armada_vendor)) {
                        $gagal[] = ['label' => (string) $model->nopol, 'alasan' => 'Tidak dihapus — masih dipakai penugasan aktif, selesaikan/batalkan dulu penugasannya'];
                        continue;
                    }
                    $this->armadaVendorRepo->delete($model);
                    $unitDihapus++;
                }
            }

            if ($unitDitambah > 0 || $unitDihapus > 0) {
                $this->tarikApprovalKarenaUnit((string) $kontrak->id_kontrak_vendor, $idPerusahaan);
            }

            return [
                'ditambah'          => $unitDitambah,
                'diperbarui'        => $unitDiperbarui,
                'dihapus'           => $unitDihapus,
                'driver_ditambah'   => $driverDitambah,
                'driver_diperbarui' => $driverDiperbarui,
                'driver_dilepas'    => $driverDilepas,
                'gagal'             => $gagal,
            ];
        });
    }

    public function timpaSupir(string $id, UploadedFile $file, string $idPerusahaan): array
    {
        $kontrak = $this->findOrFail($id, $idPerusahaan);

        if ($kontrak->mekanisme === 'unit_only') {
            abort(422, 'Supir vendor hanya untuk kontrak bermekanisme unit_driver atau full');
        }

        $parse = $this->parseSupir($file);

        $gagal = array_map(
            fn (array $g) => ['label' => 'Baris ' . $g['baris'], 'alasan' => $g['alasan']],
            $parse['baris_gagal'],
        );

        $kunciSupir = static function (array|object $s): string {
            $noSim = is_array($s) ? ($s['no_sim'] ?? null) : $s->no_sim;
            $nama  = is_array($s) ? (string) ($s['nama'] ?? '') : (string) $s->nama;
            return $noSim !== null && trim((string) $noSim) !== ''
                ? 'SIM:' . mb_strtoupper(trim((string) $noSim))
                : 'NAMA:' . mb_strtoupper(trim($nama));
        };

        return DB::transaction(function () use ($kontrak, $parse, $idPerusahaan, $gagal, $kunciSupir) {
            $lama = $this->supirVendorRepo->listAktifByKontrak((string) $kontrak->id_kontrak_vendor)
                ->keyBy(fn ($s) => $kunciSupir($s));

            $ditambah = 0;
            $diperbarui = 0;
            $kunciBaru = [];

            foreach ($parse['baris_valid'] as $supir) {
                $kunci = $kunciSupir($supir);
                if (isset($kunciBaru[$kunci])) {
                    $gagal[] = ['label' => (string) $supir['nama'], 'alasan' => 'Duplikat di dalam file'];
                    continue;
                }
                $kunciBaru[$kunci] = true;

                $ada = $lama->get($kunci);
                if ($ada !== null) {
                    $this->supirVendorRepo->update($ada, [
                        'nama'             => $supir['nama'],
                        'telepon'          => $supir['telepon'] ?? null,
                        'no_sim'           => $supir['no_sim'] ?? null,
                        'masa_berlaku_sim' => $supir['masa_berlaku_sim'] ?? null,
                    ]);
                    $diperbarui++;
                    continue;
                }

                if (!empty($supir['no_sim']) && $this->supirVendorRepo->noSimTerdaftar((string) $supir['no_sim'], $idPerusahaan)) {
                    $gagal[] = ['label' => (string) $supir['nama'], 'alasan' => 'No SIM sudah terdaftar di kontrak/vendor lain'];
                    continue;
                }

                $this->supirVendorRepo->create(array_merge($supir, [
                    'id_vendor'         => $kontrak->id_vendor,
                    'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
                ]));
                $ditambah++;
            }

            $dihapus = 0;
            foreach ($lama as $kunci => $model) {
                if (!isset($kunciBaru[$kunci])) {
                    if ($this->repo->adaPenugasanNonFinalUntukSupirVendor((string) $model->id_supir_vendor)) {
                        $gagal[] = ['label' => (string) $model->nama, 'alasan' => 'Tidak dihapus — masih dipakai penugasan aktif, selesaikan/batalkan dulu penugasannya'];
                        continue;
                    }
                    $this->armadaVendorRepo->lepasSupirDefault((string) $model->id_supir_vendor);
                    $this->supirVendorRepo->delete($model);
                    $dihapus++;
                }
            }

            return [
                'ditambah'   => $ditambah,
                'diperbarui' => $diperbarui,
                'dihapus'    => $dihapus,
                'gagal'      => $gagal,
            ];
        });
    }

    /**
     * Parse template gabungan kontrak paket: satu baris = unit + driver bawaannya.
     * Kolom driver opsional per baris (unit boleh tanpa driver tetap).
     */
    public function parsePasangan(UploadedFile $file, string $idPerusahaan): array
    {
        $rows = Excel::toArray(new ArmadaVendorImport(), $file)[0] ?? [];

        $frekuensiNopol = [];
        $frekuensiSim   = [];
        foreach ($rows as $row) {
            $nopol = ExcelCellHelper::cellToString($row['nopol'] ?? null);
            if ($nopol !== null) {
                $frekuensiNopol[mb_strtoupper($nopol)] = ($frekuensiNopol[mb_strtoupper($nopol)] ?? 0) + 1;
            }
            $sim = ExcelCellHelper::cellToString($row['no_sim_driver'] ?? null);
            if ($sim !== null) {
                $frekuensiSim[mb_strtoupper($sim)] = ($frekuensiSim[mb_strtoupper($sim)] ?? 0) + 1;
            }
        }

        $barisValid = [];
        $barisGagal = [];

        foreach ($rows as $index => $row) {
            $baris = $index + 2;

            $nopol              = ExcelCellHelper::cellToString($row['nopol'] ?? null);
            $merk               = ExcelCellHelper::cellToString($row['merk'] ?? null);
            $jenis              = ExcelCellHelper::cellToString($row['jenis'] ?? null);
            $namaJenisKendaraan = ExcelCellHelper::cellToString($row['jenis_kendaraan'] ?? null);
            $kapasitas          = ExcelCellHelper::cellToString($row['kapasitas'] ?? null);
            $tahunRaw           = ExcelCellHelper::cellToString($row['tahun'] ?? null);
            $stnkRaw            = ExcelCellHelper::cellToString($row['masa_berlaku_stnk'] ?? null);
            $kirRaw             = ExcelCellHelper::cellToString($row['masa_berlaku_kir'] ?? null);
            $namaDriver         = ExcelCellHelper::cellToString($row['nama_driver'] ?? null);
            $teleponDriver      = ExcelCellHelper::cellToString($row['telepon_driver'] ?? null);
            $noSimDriver        = ExcelCellHelper::cellToString($row['no_sim_driver'] ?? null);

            $semuaSel = [$nopol, $merk, $jenis, $namaJenisKendaraan, $kapasitas, $tahunRaw, $stnkRaw, $kirRaw, $namaDriver, $teleponDriver, $noSimDriver];
            if (array_filter($semuaSel, static fn ($v) => $v !== null) === []) {
                continue;
            }

            if ($nopol === null) {
                $barisGagal[] = ['baris' => $baris, 'alasan' => 'Nopol wajib diisi'];
                continue;
            }
            if (($frekuensiNopol[mb_strtoupper($nopol)] ?? 0) > 1) {
                $barisGagal[] = ['baris' => $baris, 'alasan' => 'Nopol duplikat di dalam file'];
                continue;
            }
            if ($namaDriver === null && ($teleponDriver !== null || $noSimDriver !== null)) {
                $barisGagal[] = ['baris' => $baris, 'alasan' => 'Nama driver wajib diisi bila kolom driver lainnya terisi'];
                continue;
            }
            if ($noSimDriver !== null && ($frekuensiSim[mb_strtoupper($noSimDriver)] ?? 0) > 1) {
                $barisGagal[] = ['baris' => $baris, 'alasan' => 'No SIM driver duplikat di dalam file'];
                continue;
            }

            $idJenisKendaraan = null;
            if ($namaJenisKendaraan !== null) {
                $idJenisKendaraan = $this->armadaVendorRepo->findIdJenisKendaraanByNama($namaJenisKendaraan, $idPerusahaan);
                if ($idJenisKendaraan === null) {
                    $barisGagal[] = ['baris' => $baris, 'alasan' => "Jenis kendaraan '{$namaJenisKendaraan}' tidak ditemukan di master"];
                    continue;
                }
            }

            $tahun = null;
            if ($tahunRaw !== null) {
                if (!is_numeric($tahunRaw) || (int) $tahunRaw < self::TAHUN_MIN || (int) $tahunRaw > self::TAHUN_MAX) {
                    $barisGagal[] = ['baris' => $baris, 'alasan' => 'Tahun tidak valid'];
                    continue;
                }
                $tahun = (int) $tahunRaw;
            }

            if ($stnkRaw === null) {
                $barisGagal[] = ['baris' => $baris, 'alasan' => 'Masa berlaku STNK wajib diisi'];
                continue;
            }
            $masaStnk = ExcelCellHelper::parseTanggal($stnkRaw);
            if ($masaStnk === null) {
                $barisGagal[] = ['baris' => $baris, 'alasan' => 'Masa berlaku STNK tidak valid (format YYYY-MM-DD)'];
                continue;
            }

            if ($kirRaw === null) {
                $barisGagal[] = ['baris' => $baris, 'alasan' => 'Masa berlaku KIR wajib diisi'];
                continue;
            }
            $masaKir = ExcelCellHelper::parseTanggal($kirRaw);
            if ($masaKir === null) {
                $barisGagal[] = ['baris' => $baris, 'alasan' => 'Masa berlaku KIR tidak valid (format YYYY-MM-DD)'];
                continue;
            }

            $barisValid[] = [
                'nopol'              => $nopol,
                'merk'               => $merk,
                'jenis'              => $jenis,
                'id_jenis_kendaraan' => $idJenisKendaraan,
                'kapasitas'          => $kapasitas,
                'tahun'              => $tahun,
                'masa_berlaku_stnk'  => $masaStnk,
                'masa_berlaku_kir'   => $masaKir,
                'driver_nama'        => $namaDriver,
                'driver_telepon'     => $teleponDriver,
                'driver_no_sim'      => $noSimDriver,
            ];
        }

        return ['baris_valid' => $barisValid, 'baris_gagal' => $barisGagal];
    }

    public function parseUnit(UploadedFile $file, string $idPerusahaan): array
    {
        $rows = Excel::toArray(new ArmadaVendorImport(), $file)[0] ?? [];

        $frekuensiNopol = [];
        foreach ($rows as $row) {
            $nopol = ExcelCellHelper::cellToString($row['nopol'] ?? null);
            if ($nopol !== null) {
                $kunci = mb_strtoupper($nopol);
                $frekuensiNopol[$kunci] = ($frekuensiNopol[$kunci] ?? 0) + 1;
            }
        }

        $barisValid = [];
        $barisGagal = [];

        foreach ($rows as $index => $row) {
            $baris = $index + 2;

            $kodeVendor         = ExcelCellHelper::cellToString($row['kode_vendor'] ?? null);
            $nopol              = ExcelCellHelper::cellToString($row['nopol'] ?? null);
            $merk               = ExcelCellHelper::cellToString($row['merk'] ?? null);
            $jenis              = ExcelCellHelper::cellToString($row['jenis'] ?? null);
            $namaJenisKendaraan = ExcelCellHelper::cellToString($row['jenis_kendaraan'] ?? null);
            $kapasitas          = ExcelCellHelper::cellToString($row['kapasitas'] ?? null);
            $tahunRaw           = ExcelCellHelper::cellToString($row['tahun'] ?? null);
            $stnkRaw            = ExcelCellHelper::cellToString($row['masa_berlaku_stnk'] ?? null);
            $kirRaw             = ExcelCellHelper::cellToString($row['masa_berlaku_kir'] ?? null);

            $semuaSel = [$kodeVendor, $nopol, $merk, $jenis, $namaJenisKendaraan, $kapasitas, $tahunRaw, $stnkRaw, $kirRaw];
            if (array_filter($semuaSel, static fn ($v) => $v !== null) === []) {
                continue;
            }

            if ($nopol === null) {
                $barisGagal[] = ['baris' => $baris, 'alasan' => 'Nopol wajib diisi'];
                continue;
            }

            if (($frekuensiNopol[mb_strtoupper($nopol)] ?? 0) > 1) {
                $barisGagal[] = ['baris' => $baris, 'alasan' => 'Nopol duplikat di dalam file'];
                continue;
            }

            $idJenisKendaraan = null;
            if ($namaJenisKendaraan !== null) {
                $idJenisKendaraan = $this->armadaVendorRepo->findIdJenisKendaraanByNama($namaJenisKendaraan, $idPerusahaan);
                if ($idJenisKendaraan === null) {
                    $barisGagal[] = ['baris' => $baris, 'alasan' => "Jenis kendaraan '{$namaJenisKendaraan}' tidak ditemukan di master"];
                    continue;
                }
            }

            $tahun = null;
            if ($tahunRaw !== null) {
                if (!is_numeric($tahunRaw) || (int) $tahunRaw < self::TAHUN_MIN || (int) $tahunRaw > self::TAHUN_MAX) {
                    $barisGagal[] = ['baris' => $baris, 'alasan' => 'Tahun tidak valid'];
                    continue;
                }
                $tahun = (int) $tahunRaw;
            }

            if ($stnkRaw === null) {
                $barisGagal[] = ['baris' => $baris, 'alasan' => 'Masa berlaku STNK wajib diisi'];
                continue;
            }
            $masaStnk = ExcelCellHelper::parseTanggal($stnkRaw);
            if ($masaStnk === null) {
                $barisGagal[] = ['baris' => $baris, 'alasan' => 'Masa berlaku STNK tidak valid (format YYYY-MM-DD)'];
                continue;
            }

            if ($kirRaw === null) {
                $barisGagal[] = ['baris' => $baris, 'alasan' => 'Masa berlaku KIR wajib diisi'];
                continue;
            }
            $masaKir = ExcelCellHelper::parseTanggal($kirRaw);
            if ($masaKir === null) {
                $barisGagal[] = ['baris' => $baris, 'alasan' => 'Masa berlaku KIR tidak valid (format YYYY-MM-DD)'];
                continue;
            }

            $barisValid[] = [
                'nopol'              => $nopol,
                'merk'               => $merk,
                'jenis'              => $jenis,
                'id_jenis_kendaraan' => $idJenisKendaraan,
                'kapasitas'          => $kapasitas,
                'tahun'              => $tahun,
                'masa_berlaku_stnk'  => $masaStnk,
                'masa_berlaku_kir'   => $masaKir,
            ];
        }

        return ['baris_valid' => $barisValid, 'baris_gagal' => $barisGagal];
    }

    public function parseSupir(UploadedFile $file): array
    {
        $rows = Excel::toArray(new SupirVendorImport(), $file)[0] ?? [];

        $frekuensiSim = [];
        foreach ($rows as $row) {
            $noSim = ExcelCellHelper::cellToString($row['no_sim'] ?? null);
            if ($noSim !== null) {
                $kunci = mb_strtoupper($noSim);
                $frekuensiSim[$kunci] = ($frekuensiSim[$kunci] ?? 0) + 1;
            }
        }

        $barisValid = [];
        $barisGagal = [];

        foreach ($rows as $index => $row) {
            $baris = $index + 2;

            $kodeVendor = ExcelCellHelper::cellToString($row['kode_vendor'] ?? null);
            $nama       = ExcelCellHelper::cellToString($row['nama'] ?? null);
            $telepon    = ExcelCellHelper::cellToString($row['telepon'] ?? null);
            $noSim      = ExcelCellHelper::cellToString($row['no_sim'] ?? null);
            $simRaw     = ExcelCellHelper::cellToString($row['masa_berlaku_sim'] ?? null);

            $semuaSel = [$kodeVendor, $nama, $telepon, $noSim, $simRaw];
            if (array_filter($semuaSel, static fn ($v) => $v !== null) === []) {
                continue;
            }

            if ($nama === null) {
                $barisGagal[] = ['baris' => $baris, 'alasan' => 'Nama wajib diisi'];
                continue;
            }

            if ($noSim !== null && ($frekuensiSim[mb_strtoupper($noSim)] ?? 0) > 1) {
                $barisGagal[] = ['baris' => $baris, 'alasan' => 'No SIM duplikat di dalam file'];
                continue;
            }

            $masaSim = null;
            if ($simRaw !== null) {
                $masaSim = ExcelCellHelper::parseTanggal($simRaw);
                if ($masaSim === null) {
                    $barisGagal[] = ['baris' => $baris, 'alasan' => 'Masa berlaku SIM tidak valid (format YYYY-MM-DD)'];
                    continue;
                }
            }

            $barisValid[] = [
                'nama'             => $nama,
                'telepon'          => $telepon,
                'no_sim'           => $noSim,
                'masa_berlaku_sim' => $masaSim,
            ];
        }

        return ['baris_valid' => $barisValid, 'baris_gagal' => $barisGagal];
    }

    private function tarikApprovalKarenaUnit(string $idKontrak, string $idPerusahaan): void
    {
        $statusSebelum = $this->repo->turunkanKeDraftJikaPerluApprovalUlang($idKontrak);
        if ($statusSebelum === 'menunggu_approval') {
            app(\App\Modules\Approval\ApprovalService::class)
                ->batalkanUntukReferensi(['kontrak_vendor'], $idKontrak, $idPerusahaan);
        }
    }

    public function dataCetak(string $id, string $idPerusahaan): array
    {
        $kontrak = $this->findOrFail($id, $idPerusahaan);
        $paket = $kontrak->mekanisme !== 'unit_only';
        $units = $this->armadaVendorRepo->listAktifByKontrak($id);
        $supir = $this->supirVendorRepo->listAktifByKontrak($id);
        $supirMap = $supir->keyBy('id_supir_vendor');

        $unitRows = $units->values()->map(static function ($u) use ($supirMap) {
            $driver = $u->id_supir_vendor_default ? ($supirMap[$u->id_supir_vendor_default] ?? null) : null;
            return (object) [
                'nopol'             => $u->nopol,
                'merk'              => $u->merk,
                'jenis'             => $u->jenis,
                'kapasitas'         => $u->kapasitas,
                'tahun'             => $u->tahun,
                'masa_berlaku_stnk' => $u->masa_berlaku_stnk,
                'masa_berlaku_kir'  => $u->masa_berlaku_kir,
                'driver_nama'       => $driver->nama ?? null,
                'driver_telepon'    => $driver->telepon ?? null,
                'driver_no_sim'     => $driver->no_sim ?? null,
            ];
        });

        $idTerpakai = $units->pluck('id_supir_vendor_default')->filter()->all();
        $cadangan = $supir->reject(static fn ($s) => in_array($s->id_supir_vendor, $idTerpakai, true))->values();

        return [
            'kontrak'    => $kontrak,
            'namaVendor' => $this->repo->getNamaVendor($kontrak->id_vendor),
            'paket'      => $paket,
            'units'      => $unitRows,
            'cadangan'   => $cadangan,
            'perusahaan' => $this->repo->getPerusahaan($idPerusahaan),
        ];
    }

    public function ajukanApproval(string $id, string $idPengguna, string $idPerusahaan): KontrakVendorModel
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if ($record->status !== 'draft') {
            abort(422, 'Hanya kontrak berstatus draft yang bisa diajukan approval');
        }

        app(\App\Modules\Approval\ApprovalService::class)->ajukan(
            'kontrak_vendor',
            $id,
            $idPengguna,
            $record->nilai_kontrak !== null ? (float) $record->nilai_kontrak : null,
            $idPerusahaan,
        );

        return $this->repo->update($record, [
            'status'                  => 'menunggu_approval',
            'alasan_ditolak_internal' => null,
        ]);
    }

    public function terapkanKeputusanApproval(string $idKontrak, string $idPerusahaan, string $keputusan, ?string $alasanDitolak): void
    {
        $record = $this->repo->findAktifMilikPerusahaan($idKontrak, $idPerusahaan);
        if ($record === null) {
            \Illuminate\Support\Facades\Log::warning("KontrakVendorApprovalListener: kontrak {$idKontrak} tidak ditemukan atau beda perusahaan");
            return;
        }
        if ($record->status !== 'menunggu_approval') {
            return;
        }

        if ($keputusan === 'ditolak') {
            $this->repo->update($record, [
                'status'                  => 'draft',
                'alasan_ditolak_internal' => $alasanDitolak,
            ]);
            return;
        }

        $this->repo->update($record, ['status' => 'aktif']);
    }

    public function update(string $id, array $data, string $idPerusahaan): KontrakVendorModel
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if (isset($data['id_vendor']) && $data['id_vendor'] !== $record->id_vendor) {
            if (!$this->repo->vendorMilikPerusahaan($data['id_vendor'], $idPerusahaan)) {
                abort(404, 'Vendor tidak ditemukan');
            }
        }

        if (array_key_exists('nilai_kontrak', $data) && $data['nilai_kontrak'] === null) {
            $data['nilai_kontrak'] = 0;
        }

        // Perubahan data saat menunggu approval menarik pengajuan — kontrak kembali ke draft.
        if ($record->status === 'menunggu_approval') {
            $cek = clone $record;
            $cek->fill($data);
            if ($cek->isDirty()) {
                $data['status'] = 'draft';
                $data['alasan_ditolak_internal'] = null;
                app(\App\Modules\Approval\ApprovalService::class)
                    ->batalkanUntukReferensi(['kontrak_vendor'], (string) $record->id_kontrak_vendor, $idPerusahaan);
            }
        }

        // Perubahan komitmen finansial pada kontrak aktif wajib approval ulang.
        if ($record->status === 'aktif') {
            $adaPerubahanKomitmen =
                (array_key_exists('nilai_kontrak', $data) && (float) $data['nilai_kontrak'] !== (float) $record->nilai_kontrak)
                || (array_key_exists('rate', $data) && ($data['rate'] !== null ? (float) $data['rate'] : null) !== ($record->rate !== null ? (float) $record->rate : null))
                || (array_key_exists('mekanisme', $data) && $data['mekanisme'] !== $record->mekanisme);

            if ($adaPerubahanKomitmen) {
                $data['status'] = 'draft';
                $data['alasan_ditolak_internal'] = null;
            }
        }


        return $this->repo->update($record, $data);
    }

    public function delete(string $id, string $idPerusahaan): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if ($this->repo->adaPenugasanUntukKontrak((string) $record->id_kontrak_vendor)) {
            abort(422, 'Kontrak sudah memiliki riwayat penugasan — ubah statusnya menjadi nonaktif saja');
        }

        DB::transaction(function () use ($record) {
            $this->repo->lepasTautanUnitDanSupir((string) $record->id_kontrak_vendor);
            $this->repo->delete($record);
        });
    }
}
