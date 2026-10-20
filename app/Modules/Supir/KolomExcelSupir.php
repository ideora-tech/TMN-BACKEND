<?php

declare(strict_types=1);

namespace App\Modules\Supir;

final class KolomExcelSupir
{
    public const DAFTAR = [
        ['kunci' => 'nik_karyawan',         'wajib' => false, 'teks' => true,  'keterangan' => 'NIK karyawan berjabatan supir (lihat sheet Referensi). Kosongkan untuk supir yang bukan karyawan.', 'contoh' => '3216025109960012'],
        ['kunci' => 'nama',                 'wajib' => false, 'teks' => true,  'keterangan' => 'Wajib bila nik_karyawan kosong. Bila nik_karyawan diisi, kosongkan saja — nama diambil dari data karyawan.', 'contoh' => 'Budi Santoso'],
        ['kunci' => 'no_sim',               'wajib' => true,  'teks' => true,  'keterangan' => 'Nomor SIM, harus unik. Maksimal 50 karakter.', 'contoh' => '1234567890'],
        ['kunci' => 'jenis_sim',            'wajib' => false, 'teks' => true,  'keterangan' => 'A, B1, B2, C, atau D. Kosong berarti B1.', 'contoh' => 'B2'],
        ['kunci' => 'tgl_kadaluarsa_sim',   'wajib' => false, 'teks' => true,  'keterangan' => 'Tanggal habis masa berlaku SIM. Format TTTT-BB-HH atau HH/BB/TTTT.', 'contoh' => '2028-01-31'],
        ['kunci' => 'telepon',              'wajib' => false, 'teks' => true,  'keterangan' => 'Maksimal 30 karakter. Bila nik_karyawan diisi dan kolom ini kosong, telepon diambil dari data karyawan.', 'contoh' => '081234567890'],
        ['kunci' => 'status',               'wajib' => false, 'teks' => true,  'keterangan' => 'aktif atau nonaktif. Kosong berarti aktif.', 'contoh' => 'aktif'],
        ['kunci' => 'nopol_armada_default', 'wajib' => false, 'teks' => true,  'keterangan' => 'Nopol armada yang biasa dibawa. Satu armada hanya untuk satu supir.', 'contoh' => 'B 1234 XYZ'],
    ];

    public static function judul(): array
    {
        return array_column(self::DAFTAR, 'kunci');
    }
}
