<?php

declare(strict_types=1);

namespace App\Modules\Karyawan;

use App\Modules\DokumenKaryawan\Contracts\DokumenKaryawanRepositoryInterface;
use App\Modules\Karyawan\Contracts\KaryawanRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * Menggabungkan karyawan dobel: orang yang sama tercatat dua kali, satunya ber-NIK tidak
 * lengkap (kode otomatis DRV-xxxx, angka pendek, salah ketik 17 digit) dan satunya ber-NIK 16 digit.
 *
 * Aturannya sengaja sempit supaya tidak ada orang berbeda yang ikut tergabung:
 *  - hanya nama yang sama persis (huruf besar/kecil dan spasi ganda diabaikan), tepat 2 record;
 *  - tepat satu dari keduanya ber-NIK 16 digit;
 *  - paling banyak satu record yang memegang relasi (supir, dokumen, kontrak, absensi, dst).
 *
 * Record pemegang relasi dipertahankan, record satunya di-soft-delete. Data yang kosong di record
 * yang dipertahankan diisi dari duplikat (nilai yang sudah ada tidak pernah ditimpa), NIK-nya
 * diganti NIK 16 digit, dan status kepegawaiannya diset sesuai pilihan.
 */
class KaryawanGabungDuplikatService
{
    public const STATUS_KEPEGAWAIAN = ['tetap', 'kontrak', 'magang', 'paruh_waktu'];

    private const KOLOM_SALIN = [
        'id_lokasi', 'email', 'telepon', 'jenis_kelamin', 'tempat_lahir', 'tanggal_lahir',
        'alamat_ktp', 'alamat_domisili', 'status_pernikahan', 'jumlah_tanggungan', 'status_ptkp',
        'npwp', 'nama_bank', 'nomor_rekening', 'atas_nama_rekening',
        'ikut_bpjs_kesehatan', 'no_bpjs_kesehatan', 'ikut_bpjs_ketenagakerjaan', 'no_bpjs_ketenagakerjaan',
        'override_persen_bpjs_kesehatan', 'override_persen_bpjs_jht', 'override_persen_bpjs_jp',
        'override_plafon_bpjs_kesehatan', 'override_tunjangan_jabatan',
        'kontak_darurat_nama', 'kontak_darurat_telepon', 'kontak_darurat_hubungan',
        'pendidikan_terakhir', 'tanggal_masuk', 'gaji_pokok',
    ];

    /** Kolom yang nilai bawaannya 0: angka 0 dianggap belum diisi. */
    private const KOLOM_NOL_BERARTI_KOSONG = [
        'jumlah_tanggungan', 'gaji_pokok', 'ikut_bpjs_kesehatan', 'ikut_bpjs_ketenagakerjaan',
    ];

    public function __construct(
        private readonly KaryawanRepositoryInterface $repo,
        private readonly DokumenKaryawanRepositoryInterface $dokumenRepo,
    ) {}

    /**
     * @param string[]|null $filterNama potongan nama (tidak peka huruf besar/kecil); null = semua
     * @return array{pasangan: array<int,array>, dilewati: array<int,array{nama:string,alasan:string}>}
     */
    public function rencana(?array $filterNama = null, ?string $idPerusahaan = null): array
    {
        $pasangan = [];
        $dilewati = [];

        foreach ($idPerusahaan !== null ? [$idPerusahaan] : $this->repo->daftarIdPerusahaan() as $id) {
            $kelompok = [];
            foreach ($this->repo->semuaAktif($id) as $baris) {
                $kelompok[$this->kunciNama((string) $baris->nama_karyawan)][] = $baris;
            }

            foreach ($kelompok as $kunci => $anggota) {
                if (count($anggota) < 2 || !$this->lolosFilter($kunci, $filterNama)) {
                    continue;
                }

                $hasil = $this->analisis($kunci, $anggota);
                if (isset($hasil['alasan'])) {
                    $dilewati[] = $hasil;
                } else {
                    $pasangan[] = $hasil;
                }
            }
        }

        return ['pasangan' => $pasangan, 'dilewati' => $dilewati];
    }

    /** Menjalankan satu pasangan hasil rencana(). Semua perubahan satu transaksi. */
    public function gabung(array $pasangan, string $statusKepegawaian): void
    {
        /** @var object $dipertahankan */
        $dipertahankan = $pasangan['dipertahankan'];
        /** @var object $duplikat */
        $duplikat = $pasangan['duplikat'];

        DB::transaction(function () use ($pasangan, $dipertahankan, $duplikat, $statusKepegawaian) {
            $this->repo->kunci((string) $dipertahankan->id_karyawan);
            $this->repo->kunci((string) $duplikat->id_karyawan);

            // Kolom nik UNIQUE dan baris soft-delete tetap menyimpan nilainya, jadi bila NIK 16 digit
            // ada di duplikat, nilainya harus dilepas dulu sebelum dipasang ke record yang dipertahankan.
            if ((string) $duplikat->nik === $pasangan['nik_akhir']) {
                $this->repo->update($duplikat, ['nik' => $this->nikPenanda((string) $duplikat->nik)]);
            }
            $this->repo->delete($duplikat);

            $data = array_merge($pasangan['salin'], [
                'nik'                => $pasangan['nik_akhir'],
                'status_kepegawaian' => $statusKepegawaian,
            ]);
            if ($pasangan['nama_baru'] !== null) {
                $data['nama_karyawan'] = $pasangan['nama_baru'];
            }

            $this->repo->update($dipertahankan, $data);
            $this->dokumenRepo->selaraskanKtpKaryawan((string) $dipertahankan->id_karyawan);
        });
    }

    private function analisis(string $kunci, array $anggota): array
    {
        $nama = (string) $anggota[0]->nama_karyawan;

        if (count($anggota) !== 2) {
            return ['nama' => $nama, 'alasan' => 'Ada ' . count($anggota) . ' karyawan bernama sama, gabungkan manual'];
        }

        [$a, $b] = $anggota;
        $aLengkap = $this->nikLengkap((string) $a->nik);
        $bLengkap = $this->nikLengkap((string) $b->nik);

        if ($aLengkap && $bLengkap) {
            return ['nama' => $nama, 'alasan' => "Kedua NIK sudah 16 digit ({$a->nik} dan {$b->nik}), bisa jadi dua orang berbeda, cek manual"];
        }
        if (!$aLengkap && !$bLengkap) {
            return ['nama' => $nama, 'alasan' => "Tidak ada NIK 16 digit ({$a->nik} dan {$b->nik}), NIK yang benar harus diisi manual"];
        }

        [$lengkap, $tidakLengkap] = $aLengkap ? [$a, $b] : [$b, $a];

        $relasiLengkap      = $this->repo->hitungRelasi((string) $lengkap->id_karyawan);
        $relasiTidakLengkap = $this->repo->hitungRelasi((string) $tidakLengkap->id_karyawan);

        if ($relasiLengkap !== [] && $relasiTidakLengkap !== []) {
            return ['nama' => $nama, 'alasan' => "Kedua record memegang relasi ({$lengkap->nik}: {$this->ringkasRelasi($relasiLengkap)}; {$tidakLengkap->nik}: {$this->ringkasRelasi($relasiTidakLengkap)}), gabungkan manual"];
        }

        [$dipertahankan, $duplikat, $relasiDipertahankan, $relasiDuplikat] = $relasiTidakLengkap !== []
            ? [$tidakLengkap, $lengkap, $relasiTidakLengkap, $relasiLengkap]
            : [$lengkap, $tidakLengkap, $relasiLengkap, $relasiTidakLengkap];

        $nikAkhir = (string) $lengkap->nik;
        $catatan  = [];

        $salin = [];
        foreach (self::KOLOM_SALIN as $kolom) {
            if ($this->kosong($dipertahankan->{$kolom} ?? null, $kolom) && !$this->kosong($duplikat->{$kolom} ?? null, $kolom)) {
                $salin[$kolom] = $duplikat->{$kolom};
            }
        }

        $nikKtp = trim((string) ($dipertahankan->nik_ktp ?? ''));
        if ($nikKtp === '') {
            $salin['nik_ktp'] = trim((string) ($duplikat->nik_ktp ?? '')) !== '' ? $duplikat->nik_ktp : $nikAkhir;
        } elseif ($nikKtp !== $nikAkhir) {
            $catatan[] = "nik_ktp record yang dipertahankan ({$nikKtp}) beda dengan NIK akhir ({$nikAkhir}), tidak diubah";
        }

        // Nama dari record ber-NIK 16 digit (asalnya impor HR) bila hanya beda huruf besar/kecil atau spasi.
        $namaBaru = (string) $dipertahankan->nama_karyawan !== (string) $lengkap->nama_karyawan
            ? (string) $lengkap->nama_karyawan
            : null;

        return [
            'nama'                => $nama,
            'dipertahankan'       => $dipertahankan,
            'duplikat'            => $duplikat,
            'relasi_dipertahankan' => $relasiDipertahankan,
            'relasi_duplikat'     => $relasiDuplikat,
            'nik_akhir'           => $nikAkhir,
            'salin'               => $salin,
            'nama_baru'           => $namaBaru,
            'catatan'             => $catatan,
        ];
    }

    private function nikLengkap(string $nik): bool
    {
        return preg_match('/^\d{16}$/', $nik) === 1;
    }

    private function kunciNama(string $nama): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $nama)));
    }

    private function lolosFilter(string $kunci, ?array $filterNama): bool
    {
        if ($filterNama === null || $filterNama === []) {
            return true;
        }
        foreach ($filterNama as $potongan) {
            if ($potongan !== '' && str_contains($kunci, $this->kunciNama($potongan))) {
                return true;
            }
        }

        return false;
    }

    private function kosong(mixed $nilai, string $kolom): bool
    {
        if ($nilai === null || trim((string) $nilai) === '') {
            return true;
        }

        return in_array($kolom, self::KOLOM_NOL_BERARTI_KOSONG, true) && (float) $nilai === 0.0;
    }

    private function nikPenanda(string $nik): string
    {
        $dasar = mb_substr('DUP-' . $nik, 0, 44);
        $calon = $dasar;
        for ($urut = 2; $this->repo->nikTerpakai($calon); $urut++) {
            $calon = $dasar . '-' . $urut;
        }

        return $calon;
    }

    private function ringkasRelasi(array $relasi): string
    {
        $bagian = [];
        foreach ($relasi as $tabel => $jumlah) {
            $bagian[] = "{$tabel} {$jumlah}";
        }

        return implode(', ', $bagian);
    }
}
