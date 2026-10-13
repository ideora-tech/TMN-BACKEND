<?php
namespace App\Modules\Rute;
use App\Modules\Rute\Contracts\RuteRepositoryInterface;
use App\Modules\Lokasi\Contracts\LokasiRepositoryInterface;
use App\Modules\JenisBbm\Contracts\JenisBbmRepositoryInterface;
use App\Modules\ParameterBok\Contracts\ParameterBokRepositoryInterface;
use App\Support\KodeOtomatis;
use Illuminate\Support\Str;
use App\Support\Impor\BukuTemplateImpor;
use App\Support\Impor\LembarDataImpor;
use App\Support\Impor\PembacaImpor;
use Illuminate\Http\UploadedFile;
use App\Modules\Rute\Requests\StoreRuteRequest;

class RuteService {
    public const KOLOM_EXCEL = [
        ['kunci' => 'nama_rute',             'wajib' => true,  'teks' => true,  'keterangan' => 'Nama rute, maksimal 200 karakter. Nama yang sudah terdaftar ditolak.', 'contoh' => 'Cikarang - Tanjung Priok'],
        ['kunci' => 'lokasi_asal',           'wajib' => false, 'teks' => true,  'keterangan' => 'Nama lokasi asal yang sudah ada di master Lokasi (lihat sheet Referensi).', 'contoh' => 'Gudang Cikarang'],
        ['kunci' => 'kota_asal',             'wajib' => false, 'teks' => true,  'keterangan' => 'Isi hanya bila nama lokasi asal dipakai di lebih dari satu kota.', 'contoh' => 'Bekasi'],
        ['kunci' => 'lokasi_tujuan',         'wajib' => false, 'teks' => true,  'keterangan' => 'Nama lokasi tujuan yang sudah ada di master Lokasi (lihat sheet Referensi).', 'contoh' => 'Pelabuhan Tanjung Priok'],
        ['kunci' => 'kota_tujuan',           'wajib' => false, 'teks' => true,  'keterangan' => 'Isi hanya bila nama lokasi tujuan dipakai di lebih dari satu kota.', 'contoh' => 'Jakarta Utara'],
        ['kunci' => 'estimasi_jarak_km',     'wajib' => false, 'teks' => false, 'keterangan' => 'Angka, boleh desimal.', 'contoh' => '45.5'],
        ['kunci' => 'estimasi_durasi_menit', 'wajib' => false, 'teks' => false, 'keterangan' => 'Angka bulat dalam menit.', 'contoh' => '120'],
        ['kunci' => 'keterangan',            'wajib' => false, 'teks' => true,  'keterangan' => 'Catatan bebas.', 'contoh' => 'Lewat tol Cikampek'],
        ['kunci' => 'aktif',                 'wajib' => false, 'teks' => true,  'keterangan' => 'ya atau tidak. Kosong berarti ya.', 'contoh' => 'ya'],
    ];

    public function __construct(
        private readonly RuteRepositoryInterface $repo,
        private readonly LokasiRepositoryInterface $lokasiRepo,
        private readonly ParameterBokRepositoryInterface $parameterBokRepo,
        private readonly JenisBbmRepositoryInterface $jenisBbmRepo,
    ) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $search = null): array {
        $paginator = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $search);
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

    public function findOrFail(string $id, ?string $idPerusahaan = null): object {
        $rute = $this->repo->findById($id);
        if (!$rute || ($idPerusahaan !== null && $rute->id_perusahaan !== $idPerusahaan)) {
            abort(404, 'Rute tidak ditemukan');
        }
        return $rute;
    }

    public function create(array $data): object {
        $data['kode_rute'] = KodeOtomatis::berikutnya($data['id_perusahaan'], 'rute');
        if ($this->repo->findByKode($data['id_perusahaan'], $data['kode_rute'])) {
            abort(409, 'Kode rute sudah digunakan');
        }
        $data = $this->resolveLokasi($data, $data['id_perusahaan']);
        $data['id_rute'] = Str::uuid()->toString();
        return $this->repo->create($data);
    }

    public function update(string $id, array $data, ?string $idPerusahaan = null): object {
        $rute = $this->findOrFail($id, $idPerusahaan);
        if (isset($data['kode_rute']) && $data['kode_rute'] !== $rute->kode_rute) {
            if ($this->repo->findByKode($rute->id_perusahaan, $data['kode_rute'], $id)) {
                abort(409, 'Kode rute sudah digunakan');
            }
        }
        $data = $this->resolveLokasi($data, $rute->id_perusahaan);
        return $this->repo->update($rute, $data);
    }

    public function delete(string $id, string $idPerusahaan): void {
        $rute = $this->findOrFail($id, $idPerusahaan);

        if ($this->repo->dipakaiRelasiAktif($id)) {
            abort(422, 'Rute masih dipakai penawaran/proyek/penugasan — nonaktifkan saja rute ini');
        }

        $this->repo->delete($rute);
    }

    public function estimasiBok(
        string $idPerusahaan,
        string $idRute,
        string $idJenisKendaraan,
        ?float $estimasiTol = null,
    ): ?array {
        $rute = $this->repo->findById($idRute);
        if ($rute === null || $rute->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Rute tidak ditemukan');
        }
        if ($this->parameterBokRepo->jenisKendaraanMilik($idJenisKendaraan, $idPerusahaan) === null) {
            abort(404, 'Jenis kendaraan tidak ditemukan');
        }

        $param = $this->parameterBokRepo->findByJenisKendaraan($idPerusahaan, $idJenisKendaraan);
        if ($param === null || $rute->estimasi_jarak_km === null) {
            return null;
        }

        $konsumsi  = (float) $param->konsumsi_km_per_liter;
        $utilisasi = (float) $param->utilisasi_km_per_bulan;
        if ($konsumsi <= 0 || $utilisasi <= 0) {
            return null;
        }

        $hargaBbm = $this->jenisBbmRepo->hargaEfektif($param->id_jenis_bbm);
        if ($hargaBbm === null) {
            return null;
        }

        $biayaTetapPerKm = (float) $param->biaya_tetap_bulanan / $utilisasi;
        $biayaBbmPerKm   = $hargaBbm / $konsumsi;
        $bokPerKm        = $biayaTetapPerKm + $biayaBbmPerKm
            + (float) $param->biaya_ban_per_km + (float) $param->biaya_servis_per_km;

        $jarak      = (float) $rute->estimasi_jarak_km;
        $hargaPokok = $bokPerKm * $jarak + ($estimasiTol ?? 0.0);
        $saranHarga = $hargaPokok * (1 + (float) $param->margin_persen / 100);

        return [
            'bok_per_km'            => round($bokPerKm, 2),
            'harga_pokok'           => round($hargaPokok, 2),
            'saran_harga'           => round($saranHarga, 2),
            'margin_persen_default' => (float) $param->margin_persen,
            'komponen'              => [
                'biaya_tetap_per_km'     => round($biayaTetapPerKm, 2),
                'biaya_bbm_per_km'       => round($biayaBbmPerKm, 2),
                'biaya_ban_per_km'       => (float) $param->biaya_ban_per_km,
                'biaya_servis_per_km'    => (float) $param->biaya_servis_per_km,
                'harga_bbm_per_liter'    => $hargaBbm,
                'konsumsi_km_per_liter'  => $konsumsi,
                'utilisasi_km_per_bulan' => $utilisasi,
                'jarak_km'               => $jarak,
                'estimasi_tol'           => $estimasiTol,
            ],
        ];
    }

    /**
     * Bila id_lokasi_asal/id_lokasi_tujuan dikirim, ambil lokasi milik
     * perusahaan terkait lalu isi teks asal/tujuan dari nama_lokasi.
     * 404 kalau lokasi tidak ditemukan atau beda perusahaan.
     */
    private function resolveLokasi(array $data, string $idPerusahaan): array {
        if (!empty($data['id_lokasi_asal'])) {
            $lokasi = $this->lokasiRepo->findById($data['id_lokasi_asal']);
            if ($lokasi === null || $lokasi->id_perusahaan !== $idPerusahaan) {
                abort(404, 'Lokasi tidak ditemukan');
            }
            $data['asal'] = $lokasi->nama_lokasi;
        }
        if (!empty($data['id_lokasi_tujuan'])) {
            $lokasi = $this->lokasiRepo->findById($data['id_lokasi_tujuan']);
            if ($lokasi === null || $lokasi->id_perusahaan !== $idPerusahaan) {
                abort(404, 'Lokasi tidak ditemukan');
            }
            $data['tujuan'] = $lokasi->nama_lokasi;
        }
        return $data;
    }

    public function templateImpor(string $idPerusahaan): BukuTemplateImpor {
        $lokasi = [];
        foreach ($this->lokasiRepo->semuaMilikPerusahaan($idPerusahaan, PembacaImpor::MAKS_EXPORT) as $satu) {
            if ((int) $satu->aktif === 1) {
                $lokasi[] = [(string) $satu->nama_lokasi, (string) ($satu->kota ?? '')];
            }
        }

        return new BukuTemplateImpor('Rute', self::KOLOM_EXCEL, [
            'Import hanya menambah rute baru. Kode rute dibuat otomatis.',
            'Rute dengan nama yang sudah terdaftar ditolak dan tidak mengubah data lama.',
            'Lokasi asal dan tujuan harus sudah ada di master Lokasi. Daftarnya ada di sheet "Referensi".',
        ], [
            ['judul' => ['Nama Lokasi', 'Kota'], 'baris' => $lokasi],
        ]);
    }

    public function exportExcel(string $idPerusahaan): LembarDataImpor {
        $data = PembacaImpor::batasiExport(
            $this->repo->semuaUntukExport($idPerusahaan, PembacaImpor::MAKS_EXPORT + 1),
            PembacaImpor::MAKS_EXPORT,
            'rute',
        );

        $baris = array_map(static fn ($r) => [
            (string) $r->nama_rute,
            $r->nama_lokasi_asal ?? $r->asal,
            $r->nama_lokasi_asal !== null ? $r->kota_asal : null,
            $r->nama_lokasi_tujuan ?? $r->tujuan,
            $r->nama_lokasi_tujuan !== null ? $r->kota_tujuan : null,
            $r->estimasi_jarak_km !== null ? (float) $r->estimasi_jarak_km : null,
            $r->estimasi_durasi_menit !== null ? (int) $r->estimasi_durasi_menit : null,
            $r->keterangan,
            (int) $r->aktif === 1 ? 'ya' : 'tidak',
        ], $data);

        return new LembarDataImpor('Rute', self::KOLOM_EXCEL, $baris);
    }

    public function import(UploadedFile $file, string $idPerusahaan): array {
        $baris = PembacaImpor::baca($file, array_column(self::KOLOM_EXCEL, 'kunci'), ['nama_rute'], 'rute');

        $frekuensi = PembacaImpor::frekuensi($baris, static fn (array $nilai) => PembacaImpor::kecil($nilai['nama_rute']));
        $terpakai  = array_flip(array_map(
            static fn ($nama) => PembacaImpor::kecil((string) $nama),
            $this->repo->namaTerpakai($idPerusahaan),
        ));

        $peta = ['lengkap' => [], 'nama' => []];
        foreach ($this->lokasiRepo->semuaMilikPerusahaan($idPerusahaan, PHP_INT_MAX) as $satu) {
            if ((int) $satu->aktif !== 1) {
                continue;
            }
            $nama = PembacaImpor::kecil((string) $satu->nama_lokasi);
            $peta['lengkap'][$nama . '|' . (PembacaImpor::kecil($satu->kota !== null ? (string) $satu->kota : null) ?? '')] = (string) $satu->id_lokasi;
            $peta['nama'][$nama][] = (string) $satu->id_lokasi;
        }
        $aturan = (new StoreRuteRequest())->rules();

        return PembacaImpor::jalankan(
            $baris,
            static fn (array $nilai) => $nilai['nama_rute'] ?? '',
            function (array $nilai, array $asli) use ($frekuensi, $terpakai, $peta, $aturan, $idPerusahaan): ?string {
                if ($nilai['nama_rute'] === null) {
                    return 'Kolom nama_rute wajib diisi';
                }
                $kunci = PembacaImpor::kecil($nilai['nama_rute']);
                if (($frekuensi[$kunci] ?? 0) > 1) {
                    return 'Nama rute duplikat di dalam file';
                }
                if (isset($terpakai[$kunci])) {
                    return 'Nama rute sudah terdaftar';
                }

                [$idAsal, $galat] = $this->cariLokasiImpor($nilai['lokasi_asal'], $nilai['kota_asal'], $peta, 'asal');
                if ($galat !== null) {
                    return $galat;
                }
                [$idTujuan, $galat] = $this->cariLokasiImpor($nilai['lokasi_tujuan'], $nilai['kota_tujuan'], $peta, 'tujuan');
                if ($galat !== null) {
                    return $galat;
                }

                $jarak = $nilai['estimasi_jarak_km'] === null ? null : PembacaImpor::angka($asli['estimasi_jarak_km'] ?? null, 999999.99);
                if ($nilai['estimasi_jarak_km'] !== null && $jarak === null) {
                    return 'Kolom estimasi_jarak_km harus berupa angka nol atau lebih';
                }
                $durasi = $nilai['estimasi_durasi_menit'] === null ? null : PembacaImpor::angka($asli['estimasi_durasi_menit'] ?? null, 1000000);
                if ($nilai['estimasi_durasi_menit'] !== null && ($durasi === null || floor($durasi) !== $durasi)) {
                    return 'Kolom estimasi_durasi_menit harus berupa angka bulat nol atau lebih';
                }
                $aktif = PembacaImpor::yaTidakAtau($nilai['aktif'], true);
                if ($aktif === null) {
                    return 'Kolom aktif harus ya atau tidak';
                }

                $data = [
                    'nama_rute'             => $nilai['nama_rute'],
                    'id_lokasi_asal'        => $idAsal,
                    'id_lokasi_tujuan'      => $idTujuan,
                    'estimasi_jarak_km'     => $jarak,
                    'estimasi_durasi_menit' => $durasi !== null ? (int) $durasi : null,
                    'keterangan'            => $nilai['keterangan'],
                    'aktif'                 => $aktif,
                ];
                $galat = PembacaImpor::validasi($data, $aturan, ['id_lokasi_asal' => 'lokasi_asal', 'id_lokasi_tujuan' => 'lokasi_tujuan']);
                if ($galat !== null) {
                    return $galat;
                }

                PembacaImpor::denganKodeBaru(fn () => $this->create(array_merge($data, ['id_perusahaan' => $idPerusahaan])));

                return null;
            },
        );
    }

    private function cariLokasiImpor(?string $nama, ?string $kota, array $peta, string $arah): array {
        if ($nama === null) {
            return $kota === null ? [null, null] : [null, "Kolom kota_{$arah} diisi tetapi lokasi_{$arah} kosong"];
        }

        $kunciNama = PembacaImpor::kecil($nama);
        if ($kota !== null) {
            $id = $peta['lengkap'][$kunciNama . '|' . PembacaImpor::kecil($kota)] ?? null;

            return $id !== null
                ? [$id, null]
                : [null, "Lokasi {$arah} \"{$nama}\" di kota \"{$kota}\" tidak ditemukan di master Lokasi"];
        }

        $cocok = $peta['nama'][$kunciNama] ?? [];
        if (count($cocok) === 1) {
            return [$cocok[0], null];
        }
        if (isset($peta['lengkap'][$kunciNama . '|'])) {
            return [$peta['lengkap'][$kunciNama . '|'], null];
        }
        if (count($cocok) > 1) {
            return [null, "Lokasi {$arah} \"{$nama}\" ada di lebih dari satu kota — isi kolom kota_{$arah}"];
        }

        return [null, "Lokasi {$arah} \"{$nama}\" tidak ditemukan di master Lokasi (lihat sheet Referensi pada template)"];
    }
}
