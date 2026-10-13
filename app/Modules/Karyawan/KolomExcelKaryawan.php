<?php

declare(strict_types=1);

namespace App\Modules\Karyawan;

final class KolomExcelKaryawan
{
    public const DAFTAR = [
        ['kunci' => 'nik',                       'wajib' => true,  'teks' => true,  'keterangan' => 'Nomor induk karyawan. Harus unik, maksimal 50 karakter.', 'contoh' => 'KRY-0001'],
        ['kunci' => 'nama_karyawan',             'wajib' => true,  'teks' => true,  'keterangan' => 'Nama lengkap, maksimal 200 karakter.', 'contoh' => 'Budi Santoso'],
        ['kunci' => 'nik_ktp',                   'wajib' => false, 'teks' => true,  'keterangan' => 'Nomor KTP, maksimal 20 karakter.', 'contoh' => '3201234567890001'],
        ['kunci' => 'jabatan',                   'wajib' => false, 'teks' => true,  'keterangan' => 'Kode atau nama jabatan yang sudah ada di master (lihat sheet Referensi).', 'contoh' => 'Staff Operasional'],
        ['kunci' => 'lokasi_kantor',             'wajib' => false, 'teks' => true,  'keterangan' => 'Kode atau nama lokasi kantor yang sudah ada di master (lihat sheet Referensi).', 'contoh' => 'Kantor Pusat'],
        ['kunci' => 'tanggal_masuk',             'wajib' => false, 'teks' => true,  'keterangan' => 'Format TTTT-BB-HH atau HH/BB/TTTT.', 'contoh' => '2026-01-15'],
        ['kunci' => 'status_kepegawaian',        'wajib' => false, 'teks' => true,  'keterangan' => 'tetap, kontrak, atau magang. Kosong berarti tetap.', 'contoh' => 'tetap'],
        ['kunci' => 'gaji_pokok',                'wajib' => false, 'teks' => false, 'keterangan' => 'Angka, misalnya 5000000. Kosong berarti 0.', 'contoh' => '5000000'],
        ['kunci' => 'jenis_kelamin',             'wajib' => false, 'teks' => true,  'keterangan' => 'L atau P.', 'contoh' => 'L'],
        ['kunci' => 'tempat_lahir',              'wajib' => false, 'teks' => true,  'keterangan' => 'Maksimal 100 karakter.', 'contoh' => 'Bandung'],
        ['kunci' => 'tanggal_lahir',             'wajib' => false, 'teks' => true,  'keterangan' => 'Format TTTT-BB-HH atau HH/BB/TTTT.', 'contoh' => '1990-05-20'],
        ['kunci' => 'telepon',                   'wajib' => false, 'teks' => true,  'keterangan' => 'Maksimal 30 karakter.', 'contoh' => '081234567890'],
        ['kunci' => 'email',                     'wajib' => false, 'teks' => true,  'keterangan' => 'Alamat email yang valid.', 'contoh' => 'budi@contoh.co.id'],
        ['kunci' => 'alamat_ktp',                'wajib' => false, 'teks' => true,  'keterangan' => 'Alamat sesuai KTP.', 'contoh' => 'Jl. Merdeka No. 1, Bandung'],
        ['kunci' => 'alamat_domisili',           'wajib' => false, 'teks' => true,  'keterangan' => 'Alamat tempat tinggal sekarang.', 'contoh' => 'Jl. Merdeka No. 1, Bandung'],
        ['kunci' => 'status_pernikahan',         'wajib' => false, 'teks' => true,  'keterangan' => 'belum_menikah, menikah, atau cerai.', 'contoh' => 'menikah'],
        ['kunci' => 'jumlah_tanggungan',         'wajib' => false, 'teks' => false, 'keterangan' => 'Angka 0 sampai 10. Kosong berarti 0.', 'contoh' => '2'],
        ['kunci' => 'status_ptkp',               'wajib' => false, 'teks' => true,  'keterangan' => 'TK/0, TK/1, TK/2, TK/3, K/0, K/1, K/2, atau K/3.', 'contoh' => 'K/2'],
        ['kunci' => 'npwp',                      'wajib' => false, 'teks' => true,  'keterangan' => 'Maksimal 30 karakter.', 'contoh' => '01.234.567.8-901.000'],
        ['kunci' => 'pendidikan_terakhir',       'wajib' => false, 'teks' => true,  'keterangan' => 'Misalnya SD, SMP, SMA/SMK, D3, S1.', 'contoh' => 'S1'],
        ['kunci' => 'nama_bank',                 'wajib' => false, 'teks' => true,  'keterangan' => 'Maksimal 50 karakter.', 'contoh' => 'BCA'],
        ['kunci' => 'nomor_rekening',            'wajib' => false, 'teks' => true,  'keterangan' => 'Maksimal 50 karakter.', 'contoh' => '1234567890'],
        ['kunci' => 'atas_nama_rekening',        'wajib' => false, 'teks' => true,  'keterangan' => 'Maksimal 150 karakter.', 'contoh' => 'Budi Santoso'],
        ['kunci' => 'ikut_bpjs_kesehatan',       'wajib' => false, 'teks' => true,  'keterangan' => 'ya atau tidak. Kosong berarti tidak.', 'contoh' => 'ya'],
        ['kunci' => 'no_bpjs_kesehatan',         'wajib' => false, 'teks' => true,  'keterangan' => 'Maksimal 30 karakter.', 'contoh' => '0001234567890'],
        ['kunci' => 'ikut_bpjs_ketenagakerjaan', 'wajib' => false, 'teks' => true,  'keterangan' => 'ya atau tidak. Kosong berarti tidak.', 'contoh' => 'ya'],
        ['kunci' => 'no_bpjs_ketenagakerjaan',   'wajib' => false, 'teks' => true,  'keterangan' => 'Maksimal 30 karakter.', 'contoh' => '12345678901'],
        ['kunci' => 'kontak_darurat_nama',       'wajib' => false, 'teks' => true,  'keterangan' => 'Maksimal 150 karakter.', 'contoh' => 'Siti Aminah'],
        ['kunci' => 'kontak_darurat_telepon',    'wajib' => false, 'teks' => true,  'keterangan' => 'Maksimal 30 karakter.', 'contoh' => '081298765432'],
        ['kunci' => 'kontak_darurat_hubungan',   'wajib' => false, 'teks' => true,  'keterangan' => 'Misalnya Istri, Suami, Orang Tua.', 'contoh' => 'Istri'],
        ['kunci' => 'aktif',                     'wajib' => false, 'teks' => true,  'keterangan' => 'ya atau tidak. Kosong berarti ya.', 'contoh' => 'ya'],
    ];

    public static function judul(): array
    {
        return array_column(self::DAFTAR, 'kunci');
    }
}
