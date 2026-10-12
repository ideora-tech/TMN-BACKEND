<?php

declare(strict_types=1);

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const TIPE_MIME = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('dokumen_vendor')) {
            return;
        }

        $this->pindahkanBerkasKeLampiran();

        DB::table('notifikasi')->where('referensi_tipe', 'dokumen_vendor')->delete();

        Schema::dropIfExists('dokumen_vendor');
    }

    public function down(): void
    {
        if (Schema::hasTable('dokumen_vendor')) {
            return;
        }

        Schema::create('dokumen_vendor', function (Blueprint $table) {
            $table->char('id_dokumen_vendor', 36)->primary();
            $table->char('id_vendor', 36);
            $table->string('jenis_dokumen', 50);
            $table->string('nomor', 100)->nullable();
            $table->date('berlaku_sampai')->nullable();
            $table->string('url_file', 500)->nullable();
            MigrationHelper::auditColumns($table);

            $table->index('id_vendor', 'idx_dokumen_vendor_id_vendor');
        });
    }

    private function pindahkanBerkasKeLampiran(): void
    {
        $dokumen = DB::table('dokumen_vendor as d')
            ->join('vendor as v', 'v.id_vendor', '=', 'd.id_vendor')
            ->whereNull('d.dihapus_pada')
            ->whereNull('v.dihapus_pada')
            ->whereNotNull('d.url_file')
            ->where('d.url_file', '!=', '')
            ->orderBy('d.dibuat_pada')
            ->get([
                'd.id_vendor', 'd.jenis_dokumen', 'd.nomor', 'd.berlaku_sampai', 'd.url_file',
                'd.dibuat_pada', 'd.dibuat_oleh', 'v.id_perusahaan',
            ]);

        if ($dokumen->isEmpty()) {
            return;
        }
        if (!Schema::hasTable('lampiran_master')) {
            throw new RuntimeException('Tabel lampiran_master belum ada — berkas dokumen vendor tidak bisa dipindahkan ke Foto & Lampiran. Jalankan migrasi lampiran_master lebih dulu.');
        }

        $urutan = [];
        foreach ($dokumen as $satu) {
            $sudahAda = DB::table('lampiran_master')
                ->where('jenis_pemilik', 'vendor')
                ->where('id_pemilik', $satu->id_vendor)
                ->where('url_file', $satu->url_file)
                ->exists();
            if ($sudahAda) {
                continue;
            }

            if (!isset($urutan[$satu->id_vendor])) {
                $urutan[$satu->id_vendor] = (int) DB::table('lampiran_master')
                    ->where('jenis_pemilik', 'vendor')
                    ->where('id_pemilik', $satu->id_vendor)
                    ->max('urutan');
            }

            $url      = (string) $satu->url_file;
            $lokal    = !str_starts_with($url, 'http://') && !str_starts_with($url, 'https://');
            $ekstensi = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

            $nama = trim($satu->jenis_dokumen . ($satu->nomor ? ' ' . $satu->nomor : ''));
            if ($satu->berlaku_sampai) {
                $nama .= ' (berlaku s.d. ' . date('d-m-Y', strtotime((string) $satu->berlaku_sampai)) . ')';
            }
            $nama = Str::limit((string) preg_replace('/[\\\\\/:*?"<>|]+/', '-', $nama), 240, '')
                . ($ekstensi !== '' ? '.' . $ekstensi : '');

            DB::table('lampiran_master')->insert([
                'id_lampiran'   => (string) Str::uuid(),
                'id_perusahaan' => $satu->id_perusahaan,
                'jenis_pemilik' => 'vendor',
                'id_pemilik'    => $satu->id_vendor,
                'url_file'      => $url,
                'nama_asli'     => $nama,
                'tipe_mime'     => self::TIPE_MIME[$ekstensi] ?? null,
                'ukuran'        => $lokal && Storage::disk('public')->exists($url) ? (int) Storage::disk('public')->size($url) : 0,
                'urutan'        => ++$urutan[$satu->id_vendor],
                'dibuat_pada'   => $satu->dibuat_pada ?? now(),
                'dibuat_oleh'   => $satu->dibuat_oleh,
            ]);
        }
    }
};
