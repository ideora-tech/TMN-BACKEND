<?php

declare(strict_types=1);

namespace App\Support\Impor;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class PembacaImpor
{
    public const MAKS_BARIS   = 1000;
    public const MAKS_EXPORT  = 5000;
    public const MAKS_NOMINAL = 9999999999999.0;

    private const NILAI_YA    = ['ya', 'y', '1', 'true', 'aktif', 'ikut'];
    private const NILAI_TIDAK = ['tidak', 't', '0', 'false', 'nonaktif', 'tidak aktif', 'tidak ikut'];

    private const GALAT_RUMUS  = ['#REF!', '#N/A', '#VALUE!', '#NAME?', '#DIV/0!', '#NULL!', '#NUM!'];
    private const MAKS_COBA_KODE = 25;

    private const PESAN_TAK_TERBACA = 'File tidak bisa dibaca — pakai file Excel (.xlsx) dari template, bukan hasil ganti nama. Rumus yang merujuk sheet atau file lain tidak didukung; tempel sebagai nilai';

    private const PESAN_VALIDASI = [
        'required' => 'Kolom :attribute wajib diisi',
        'max'      => 'Kolom :attribute maksimal :max karakter',
        'in'       => 'Isi kolom :attribute tidak dikenali',
        'email'    => 'Kolom :attribute bukan alamat email yang valid',
        'date'     => 'Kolom :attribute bukan tanggal yang valid',
        'numeric'  => 'Kolom :attribute harus berupa angka',
        'integer'  => 'Kolom :attribute harus berupa angka bulat',
        'min'      => 'Kolom :attribute tidak boleh kurang dari :min',
        'string'   => 'Kolom :attribute harus berupa teks',
        'boolean'  => 'Kolom :attribute harus ya atau tidak',
    ];

    public static function baca(UploadedFile $file, array $judulDikenal, array $judulWajib, string $labelData, int $maksBaris = self::MAKS_BARIS): array
    {
        $info = self::infoSheetPertama($file);
        if ($info === null) {
            abort(422, self::PESAN_TAK_TERBACA);
        }
        if ($info['nama'] === '' || is_numeric($info['nama'])) {
            abort(422, 'Nama sheet pertama tidak boleh kosong atau hanya angka — ganti namanya (misalnya "Data") lalu unggah ulang');
        }
        if ($info['baris'] - 1 > $maksBaris) {
            abort(422, 'Sheet pertama berisi ' . number_format($info['baris'] - 1, 0, ',', '.') . ' baris, maksimal '
                . number_format($maksBaris, 0, ',', '.') . ' per file — pecah menjadi beberapa file, atau hapus baris kosong di bawah data');
        }
        if ($info['kolom'] > max(count($judulDikenal) + 20, 60)) {
            abort(422, 'Sheet pertama berisi ' . number_format($info['kolom'], 0, ',', '.') . ' kolom — pakai template dan hapus kolom tambahan');
        }

        $mentah = self::muatSheet($file, $info['nama']);
        if ($mentah === null) {
            abort(422, self::PESAN_TAK_TERBACA);
        }

        if ($mentah !== []) {
            $judulFile = array_keys($mentah[0]);

            $hilang = array_values(array_diff($judulWajib, $judulFile));
            if ($hilang !== []) {
                abort(422, 'Format file tidak sesuai template: kolom ' . implode(', ', $hilang) . ' tidak ditemukan di baris pertama');
            }

            $asing = array_values(array_filter(
                $judulFile,
                static fn ($judul) => is_string($judul) && $judul !== '' && !in_array($judul, $judulDikenal, true),
            ));
            if ($asing !== []) {
                abort(422, 'Judul kolom tidak dikenal: ' . implode(', ', array_slice($asing, 0, 5))
                    . ' — pakai judul kolom persis seperti di template, hapus kolom tambahan');
            }
        }

        $baris = [];
        foreach ($mentah as $indeks => $row) {
            $nilai = [];
            foreach ($judulDikenal as $kunci) {
                $nilai[$kunci] = self::teks($row[$kunci] ?? null);
            }
            if (array_filter($nilai, static fn ($v) => $v !== null) === []) {
                continue;
            }
            $baris[] = ['nomor' => $indeks + 2, 'nilai' => $nilai, 'asli' => $row];
        }

        if ($baris === []) {
            abort(422, "File tidak berisi data {$labelData} — isi mulai baris 2 pada sheet pertama");
        }

        return $baris;
    }

    public static function jalankan(array $baris, callable $kunciBaris, callable $proses): array
    {
        $berhasil = 0;
        $gagal    = [];

        foreach ($baris as $satu) {
            try {
                $galat = self::galatRumus($satu['nilai']) ?? $proses($satu['nilai'], $satu['asli']);
                if ($galat === null) {
                    $berhasil++;
                    continue;
                }
            } catch (HttpExceptionInterface $e) {
                $galat = $e->getMessage();
            } catch (\Throwable $e) {
                report($e);
                $galat = 'Gagal disimpan — periksa kembali isi baris ini';
            }

            $gagal[] = ['baris' => $satu['nomor'], 'kunci' => (string) $kunciBaris($satu['nilai']), 'alasan' => $galat];
        }

        return ['berhasil' => $berhasil, 'gagal' => $gagal];
    }

    public static function denganKodeBaru(callable $buat): mixed
    {
        for ($percobaan = 1; ; $percobaan++) {
            try {
                return $buat();
            } catch (HttpExceptionInterface $e) {
                if ($e->getStatusCode() !== 409 || $percobaan >= self::MAKS_COBA_KODE) {
                    throw $e;
                }
            } catch (UniqueConstraintViolationException $e) {
                if ($percobaan >= self::MAKS_COBA_KODE) {
                    throw $e;
                }
            }
        }
    }

    public static function validasi(array $data, array $aturan, array $namaKolom = [], array $pesanTambahan = []): ?string
    {
        $atribut   = array_merge(array_combine(array_keys($aturan), array_keys($aturan)), $namaKolom);
        $validator = Validator::make($data, $aturan, array_merge(self::PESAN_VALIDASI, $pesanTambahan), $atribut);

        return $validator->fails() ? (string) $validator->errors()->first() : null;
    }

    public static function frekuensi(array $baris, callable $kunci): array
    {
        $hitung = [];
        foreach ($baris as $satu) {
            $nilai = $kunci($satu['nilai']);
            if ($nilai !== null) {
                $hitung[$nilai] = ($hitung[$nilai] ?? 0) + 1;
            }
        }

        return $hitung;
    }

    public static function kecil(?string $nilai): ?string
    {
        return $nilai === null ? null : mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($nilai)));
    }

    public static function teks(mixed $nilai): ?string
    {
        if ($nilai === null) {
            return null;
        }
        if (is_bool($nilai)) {
            return $nilai ? 'ya' : 'tidak';
        }
        if (is_float($nilai)) {
            if (!is_finite($nilai)) {
                return 'bukan angka';
            }
            $nilai = floor($nilai) === $nilai
                ? number_format($nilai, 0, '.', '')
                : rtrim(rtrim(number_format($nilai, 6, '.', ''), '0'), '.');
        }

        $teks = (string) $nilai;
        $teks = preg_replace('/^[\s\x{00A0}\x{200B}\x{FEFF}]+|[\s\x{00A0}\x{200B}\x{FEFF}]+$/u', '', $teks) ?? trim($teks);

        return $teks === '' ? null : $teks;
    }

    public static function slug(string $nilai): string
    {
        return (string) preg_replace('/\s+/', '_', mb_strtolower(trim($nilai)));
    }

    public static function yaTidak(string $nilai): ?bool
    {
        $kecil = mb_strtolower(trim($nilai));

        if (in_array($kecil, self::NILAI_YA, true)) {
            return true;
        }
        if (in_array($kecil, self::NILAI_TIDAK, true)) {
            return false;
        }

        return null;
    }

    public static function yaTidakAtau(?string $nilai, bool $bawaan): ?bool
    {
        return $nilai === null ? $bawaan : self::yaTidak($nilai);
    }

    public static function batasiExport(array $data, int $maks, string $labelData): array
    {
        if (count($data) > $maks) {
            abort(422, "Data {$labelData} melebihi " . number_format($maks, 0, ',', '.') . ' baris — hubungi admin untuk mengekspor bertahap');
        }

        return $data;
    }

    public static function angka(mixed $nilai, float $maks = self::MAKS_NOMINAL): ?float
    {
        if (is_int($nilai) || is_float($nilai)) {
            $angka = (float) $nilai;
        } else {
            $bersih = str_replace(['Rp', 'rp', 'RP', ' ', "\u{00A0}"], '', trim((string) $nilai));

            if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $bersih) === 1) {
                $bersih = str_replace(',', '.', str_replace('.', '', $bersih));
            } elseif (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $bersih) === 1) {
                $bersih = str_replace(',', '', $bersih);
            } elseif (preg_match('/^\d+,\d+$/', $bersih) === 1) {
                $bersih = str_replace(',', '.', $bersih);
            } elseif (preg_match('/^\d+(\.\d+)?$/', $bersih) !== 1) {
                return null;
            }

            $angka = (float) $bersih;
        }

        return is_finite($angka) && $angka >= 0 && $angka <= $maks ? $angka : null;
    }

    public static function tanggal(mixed $nilai, int $tahunTerawal = 1930, int $tahunTerakhir = 2100): ?string
    {
        if ($nilai instanceof \DateTimeInterface) {
            [$tahun, $bulan, $hari] = [(int) $nilai->format('Y'), (int) $nilai->format('n'), (int) $nilai->format('j')];
        } elseif (is_int($nilai) || is_float($nilai)) {
            if (!is_finite((float) $nilai) || $nilai <= 0) {
                return null;
            }
            try {
                $dariExcel = ExcelDate::excelToDateTimeObject((float) $nilai);
            } catch (\Throwable) {
                return null;
            }
            [$tahun, $bulan, $hari] = [(int) $dariExcel->format('Y'), (int) $dariExcel->format('n'), (int) $dariExcel->format('j')];
        } else {
            $teks = trim((string) $nilai);
            if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $teks, $cocok) === 1) {
                [$tahun, $bulan, $hari] = [(int) $cocok[1], (int) $cocok[2], (int) $cocok[3]];
            } elseif (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $teks, $cocok) === 1) {
                [$tahun, $bulan, $hari] = [(int) $cocok[3], (int) $cocok[2], (int) $cocok[1]];
            } else {
                return null;
            }
        }

        if ($tahun < $tahunTerawal || $tahun > $tahunTerakhir || !checkdate($bulan, $hari, $tahun)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $tahun, $bulan, $hari);
    }

    private static function galatRumus(array $nilai): ?string
    {
        foreach ($nilai as $kolom => $isi) {
            if ($isi !== null && in_array(mb_strtoupper($isi), self::GALAT_RUMUS, true)) {
                return "Kolom {$kolom} berisi galat rumus ({$isi}) — tempel sebagai nilai lalu unggah ulang";
            }
        }

        return null;
    }

    private static function infoSheetPertama(UploadedFile $file): ?array
    {
        try {
            $path = (string) $file->getRealPath();
            $info = IOFactory::createReaderForFile($path)->listWorksheetInfo($path);
            if (!isset($info[0]['totalRows'])) {
                return null;
            }

            return [
                'nama'  => trim((string) ($info[0]['worksheetName'] ?? '')),
                'baris' => (int) $info[0]['totalRows'],
                'kolom' => (int) ($info[0]['totalColumns'] ?? 0),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private static function muatSheet(UploadedFile $file, string $namaSheet): ?array
    {
        try {
            $hasil = Excel::toArray(new ImporBerjudul($namaSheet), $file);

            return $hasil[$namaSheet] ?? [];
        } catch (\Throwable) {
            return null;
        }
    }
}
