<?php

declare(strict_types=1);

namespace App\Modules\Karyawan;

use App\Modules\Karyawan\Contracts\KaryawanRepositoryInterface;
use App\Modules\Karyawan\Requests\StoreKaryawanRequest;
use App\Support\Impor\BukuTemplateImpor;
use App\Support\Impor\LembarDataImpor;
use App\Support\Impor\PembacaImpor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class KaryawanImportService
{
    public const MAKS_BARIS_EXPORT = 5000;

    private const STATUS_KEPEGAWAIAN = ['tetap', 'kontrak', 'magang'];
    private const STATUS_PERNIKAHAN  = ['belum_menikah', 'menikah', 'cerai'];

    private const CATATAN = [
        'Import hanya menambah karyawan baru. Baris dengan NIK yang sudah terdaftar ditolak dan tidak mengubah data lama.',
        'Jabatan dan lokasi kantor harus sudah ada di master. Daftarnya ada di sheet "Referensi".',
    ];

    public function __construct(
        private readonly KaryawanRepositoryInterface $repo,
        private readonly KaryawanService $karyawanService,
    ) {}

    public function template(string $idPerusahaan): BukuTemplateImpor
    {
        $jabatan = array_map(
            static fn ($j) => [$j->kode_jabatan, $j->nama_jabatan, $j->nama_departemen],
            $this->repo->referensiJabatan($idPerusahaan),
        );
        $lokasi = array_map(
            static fn ($l) => [$l->kode_lokasi, $l->nama_lokasi],
            $this->repo->referensiLokasi($idPerusahaan),
        );

        return new BukuTemplateImpor('Karyawan', KolomExcelKaryawan::DAFTAR, self::CATATAN, [
            ['judul' => ['Kode Jabatan', 'Nama Jabatan', 'Departemen'], 'baris' => $jabatan],
            ['judul' => ['Kode Lokasi', 'Nama Lokasi Kantor'], 'baris' => $lokasi],
        ]);
    }

    public function export(string $idPerusahaan, ?string $status, ?string $search): LembarDataImpor
    {
        $data = $this->repo->semuaUntukExport($idPerusahaan, $status, $search, self::MAKS_BARIS_EXPORT + 1);
        if (count($data) > self::MAKS_BARIS_EXPORT) {
            abort(422, 'Data karyawan melebihi ' . number_format(self::MAKS_BARIS_EXPORT, 0, ',', '.') . ' baris — persempit dengan pencarian atau saringan status');
        }

        $namaJabatanGanda = $this->namaGanda($this->repo->referensiJabatan($idPerusahaan), 'nama_jabatan');
        $namaLokasiGanda  = $this->namaGanda($this->repo->referensiLokasi($idPerusahaan), 'nama_lokasi');

        $baris = [];
        foreach ($data as $k) {
            $jabatan = $k->nama_jabatan !== null && isset($namaJabatanGanda[mb_strtolower((string) $k->nama_jabatan)])
                ? $k->kode_jabatan
                : $k->nama_jabatan;
            $lokasi = $k->nama_lokasi !== null && isset($namaLokasiGanda[mb_strtolower((string) $k->nama_lokasi)])
                ? $k->kode_lokasi
                : $k->nama_lokasi;

            $baris[] = [
                (string) $k->nik,
                (string) $k->nama_karyawan,
                $k->nik_ktp,
                $jabatan,
                $lokasi,
                $this->tanggalSaja($k->tanggal_masuk),
                $k->status_kepegawaian,
                (float) $k->gaji_pokok,
                $k->jenis_kelamin,
                $k->tempat_lahir,
                $this->tanggalSaja($k->tanggal_lahir),
                $k->telepon,
                $k->email,
                $k->alamat_ktp,
                $k->alamat_domisili,
                $k->status_pernikahan,
                (int) $k->jumlah_tanggungan,
                $k->status_ptkp,
                $k->npwp,
                $k->pendidikan_terakhir,
                $k->nama_bank,
                $k->nomor_rekening,
                $k->atas_nama_rekening,
                (int) $k->ikut_bpjs_kesehatan === 1 ? 'ya' : 'tidak',
                $k->no_bpjs_kesehatan,
                (int) $k->ikut_bpjs_ketenagakerjaan === 1 ? 'ya' : 'tidak',
                $k->no_bpjs_ketenagakerjaan,
                $k->kontak_darurat_nama,
                $k->kontak_darurat_telepon,
                $k->kontak_darurat_hubungan,
                (int) $k->aktif === 1 ? 'ya' : 'tidak',
            ];
        }

        return new LembarDataImpor('Karyawan', KolomExcelKaryawan::DAFTAR, $baris);
    }

    public function import(UploadedFile $file, string $idPerusahaan): array
    {
        $baris = PembacaImpor::baca($file, KolomExcelKaryawan::judul(), ['nik', 'nama_karyawan'], 'karyawan');

        $frekuensiNik = PembacaImpor::frekuensi($baris, static fn (array $nilai) => $nilai['nik'] !== null ? mb_strtolower($nilai['nik']) : null);
        $statusNik    = $this->repo->statusNik(array_values(array_filter(
            array_map(static fn (array $satu) => $satu['nilai']['nik'], $baris),
            static fn ($nik) => $nik !== null,
        )));

        $petaJabatan = $this->petaReferensi($this->repo->referensiJabatan($idPerusahaan), 'id_jabatan', 'kode_jabatan', 'nama_jabatan');
        $petaLokasi  = $this->petaReferensi($this->repo->referensiLokasi($idPerusahaan), 'id_lokasi', 'kode_lokasi', 'nama_lokasi');
        $aturan      = (new StoreKaryawanRequest())->rules();

        return PembacaImpor::jalankan(
            $baris,
            static fn (array $nilai) => $nilai['nik'] ?? $nilai['nama_karyawan'] ?? '',
            function (array $nilai, array $asli) use ($frekuensiNik, $statusNik, $petaJabatan, $petaLokasi, $aturan, $idPerusahaan): ?string {
                $galat = $this->galatIdentitas($nilai, $frekuensiNik, $statusNik);
                if ($galat !== null) {
                    return $galat;
                }

                [$data, $galat] = $this->susunData($nilai, $asli, $petaJabatan, $petaLokasi);
                $galat ??= PembacaImpor::validasi(
                    $data ?? [],
                    $aturan,
                    ['id_jabatan' => 'jabatan', 'id_lokasi' => 'lokasi_kantor'],
                    ['jumlah_tanggungan.max' => 'Kolom jumlah_tanggungan tidak boleh lebih dari :max'],
                );
                if ($galat !== null) {
                    return $galat;
                }

                DB::transaction(fn () => $this->karyawanService->create(array_merge($data, ['id_perusahaan' => $idPerusahaan])));

                return null;
            },
        );
    }

    private function galatIdentitas(array $nilai, array $frekuensiNik, array $statusNik): ?string
    {
        if ($nilai['nik'] === null) {
            return 'Kolom nik wajib diisi';
        }
        if ($nilai['nama_karyawan'] === null) {
            return 'Kolom nama_karyawan wajib diisi';
        }

        $kunci = mb_strtolower($nilai['nik']);
        if (($frekuensiNik[$kunci] ?? 0) > 1) {
            return 'NIK duplikat di dalam file';
        }

        return match ($statusNik[$kunci] ?? null) {
            'aktif'   => 'NIK sudah terdaftar',
            'dihapus' => 'NIK pernah dipakai karyawan yang sudah dihapus — gunakan NIK lain',
            default   => null,
        };
    }

    private function susunData(array $nilai, array $asli, array $petaJabatan, array $petaLokasi): array
    {
        $data = [
            'nik'           => $nilai['nik'],
            'nama_karyawan' => $nilai['nama_karyawan'],
        ];

        foreach ([
            'nik_ktp', 'tempat_lahir', 'telepon', 'email', 'alamat_ktp', 'alamat_domisili', 'npwp',
            'pendidikan_terakhir', 'nama_bank', 'nomor_rekening', 'atas_nama_rekening',
            'no_bpjs_kesehatan', 'no_bpjs_ketenagakerjaan',
            'kontak_darurat_nama', 'kontak_darurat_telepon', 'kontak_darurat_hubungan',
        ] as $kolom) {
            $data[$kolom] = $nilai[$kolom];
        }

        foreach (['jabatan' => ['id_jabatan', $petaJabatan, 'Jabatan'], 'lokasi_kantor' => ['id_lokasi', $petaLokasi, 'Lokasi kantor']] as $kolom => [$kunciId, $peta, $label]) {
            $data[$kunciId] = null;
            if ($nilai[$kolom] === null) {
                continue;
            }
            [$id, $galat] = $this->cariReferensi($nilai[$kolom], $peta, $label);
            if ($galat !== null) {
                return [null, $galat];
            }
            $data[$kunciId] = $id;
        }

        foreach (['tanggal_masuk', 'tanggal_lahir'] as $kolom) {
            $data[$kolom] = null;
            if ($nilai[$kolom] === null) {
                continue;
            }
            $tanggal = PembacaImpor::tanggal($asli[$kolom] ?? null);
            if ($tanggal === null) {
                return [null, "Kolom {$kolom} bukan tanggal yang valid (pakai TTTT-BB-HH atau HH/BB/TTTT)"];
            }
            $data[$kolom] = $tanggal;
        }

        $status = $nilai['status_kepegawaian'] === null ? 'tetap' : PembacaImpor::slug($nilai['status_kepegawaian']);
        if (!in_array($status, self::STATUS_KEPEGAWAIAN, true)) {
            return [null, 'Kolom status_kepegawaian harus tetap, kontrak, atau magang'];
        }
        $data['status_kepegawaian'] = $status;

        $data['status_pernikahan'] = null;
        if ($nilai['status_pernikahan'] !== null) {
            $pernikahan = PembacaImpor::slug($nilai['status_pernikahan']);
            if (!in_array($pernikahan, self::STATUS_PERNIKAHAN, true)) {
                return [null, 'Kolom status_pernikahan harus belum_menikah, menikah, atau cerai'];
            }
            $data['status_pernikahan'] = $pernikahan;
        }

        $data['jenis_kelamin'] = null;
        if ($nilai['jenis_kelamin'] !== null) {
            $jk = match (PembacaImpor::slug($nilai['jenis_kelamin'])) {
                'l', 'laki_laki', 'laki-laki', 'pria'  => 'L',
                'p', 'perempuan', 'wanita'            => 'P',
                default                               => null,
            };
            if ($jk === null) {
                return [null, 'Kolom jenis_kelamin harus L atau P'];
            }
            $data['jenis_kelamin'] = $jk;
        }

        $data['status_ptkp'] = $nilai['status_ptkp'] !== null
            ? mb_strtoupper(str_replace(' ', '', $nilai['status_ptkp']))
            : null;

        $gaji = $nilai['gaji_pokok'] === null ? 0.0 : PembacaImpor::angka($asli['gaji_pokok'] ?? null);
        if ($gaji === null) {
            return [null, 'Kolom gaji_pokok harus berupa angka nol atau lebih, tanpa rumus atau huruf'];
        }
        $data['gaji_pokok'] = $gaji;

        $tanggungan = $nilai['jumlah_tanggungan'] === null ? 0.0 : PembacaImpor::angka($asli['jumlah_tanggungan'] ?? null);
        if ($tanggungan === null || floor($tanggungan) !== $tanggungan) {
            return [null, 'Kolom jumlah_tanggungan harus berupa angka bulat 0 sampai 10'];
        }
        $data['jumlah_tanggungan'] = (int) $tanggungan;

        foreach (['ikut_bpjs_kesehatan' => false, 'ikut_bpjs_ketenagakerjaan' => false, 'aktif' => true] as $kolom => $bawaan) {
            $bool = $nilai[$kolom] === null ? $bawaan : PembacaImpor::yaTidak($nilai[$kolom]);
            if ($bool === null) {
                return [null, "Kolom {$kolom} harus ya atau tidak"];
            }
            $data[$kolom] = $bool;
        }

        return [$data, null];
    }

    private function petaReferensi(array $daftar, string $kunciId, string $kunciKode, string $kunciNama): array
    {
        $peta = ['kode' => [], 'nama' => []];
        foreach ($daftar as $satu) {
            $peta['kode'][mb_strtolower(trim((string) $satu->{$kunciKode}))] = (string) $satu->{$kunciId};
            $peta['nama'][mb_strtolower(trim((string) $satu->{$kunciNama}))][] = (string) $satu->{$kunciId};
        }

        return $peta;
    }

    private function cariReferensi(string $isi, array $peta, string $label): array
    {
        $kunci = mb_strtolower($isi);

        if (isset($peta['kode'][$kunci])) {
            return [$peta['kode'][$kunci], null];
        }

        $cocok = $peta['nama'][$kunci] ?? [];
        if (count($cocok) === 1) {
            return [$cocok[0], null];
        }
        if (count($cocok) > 1) {
            return [null, "{$label} \"{$isi}\" dipakai lebih dari satu data master — isi dengan kodenya"];
        }

        return [null, "{$label} \"{$isi}\" tidak ditemukan di master (lihat sheet Referensi pada template)"];
    }

    private function namaGanda(array $daftar, string $kunciNama): array
    {
        $hitung = [];
        foreach ($daftar as $satu) {
            $nama = mb_strtolower(trim((string) $satu->{$kunciNama}));
            $hitung[$nama] = ($hitung[$nama] ?? 0) + 1;
        }

        return array_filter($hitung, static fn ($n) => $n > 1);
    }

    private function tanggalSaja(mixed $nilai): ?string
    {
        return $nilai !== null && $nilai !== '' ? substr((string) $nilai, 0, 10) : null;
    }
}
