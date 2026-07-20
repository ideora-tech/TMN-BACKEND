<?php

use App\Modules\Notifikasi\NotifikasiModel;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Artisan::command('notifikasi:dokumen-kadaluarsa', function () {
    $today  = now()->toDateString();
    $batas  = now()->addDays(30)->toDateString();

    $dokumen = DB::table('dokumen_armada as d')
        ->join('armada as a', 'd.id_armada', '=', 'a.id_armada')
        ->whereNull('d.dihapus_pada')
        ->whereNull('a.dihapus_pada')
        ->whereBetween('d.berlaku_sampai', [$today, $batas])
        ->select('d.id_dokumen_armada', 'd.jenis_dokumen', 'd.berlaku_sampai', 'a.nopol', 'a.id_perusahaan')
        ->get();

    $created = 0;
    foreach ($dokumen as $dok) {
        $exists = NotifikasiModel::where('referensi_id', $dok->id_dokumen_armada)
            ->where('referensi_tipe', 'dokumen_armada')
            ->whereDate('dibuat_pada', $today)
            ->exists();
        if ($exists) continue;

        $exp      = now()->parse($dok->berlaku_sampai);
        $daysLeft = (int) now()->diffInDays($exp, false);
        $prefix   = $daysLeft <= 7 ? '[SEGERA] ' : '';

        NotifikasiModel::create([
            'id_notifikasi'  => Str::uuid()->toString(),
            'id_perusahaan'  => $dok->id_perusahaan,
            'id_pengguna'    => null,
            'judul'          => "{$prefix}Dokumen {$dok->jenis_dokumen} {$dok->nopol} kadaluarsa dalam {$daysLeft} hari",
            'isi'            => "Dokumen {$dok->jenis_dokumen} untuk armada {$dok->nopol} akan kadaluarsa pada ".
                                $exp->format('d M Y')." ({$daysLeft} hari lagi). Segera perbarui.",
            'tipe'           => 'alert_dokumen',
            'referensi_id'   => $dok->id_dokumen_armada,
            'referensi_tipe' => 'dokumen_armada',
            'dibaca'         => 0,
        ]);
        $created++;
    }

    $dokumenVendor = DB::table('dokumen_vendor as d')
        ->join('vendor as v', 'd.id_vendor', '=', 'v.id_vendor')
        ->whereNull('d.dihapus_pada')
        ->whereNull('v.dihapus_pada')
        ->whereBetween('d.berlaku_sampai', [$today, $batas])
        ->select('d.id_dokumen_vendor', 'd.jenis_dokumen', 'd.berlaku_sampai', 'v.nama_vendor', 'v.id_perusahaan')
        ->get();

    foreach ($dokumenVendor as $dok) {
        $exists = NotifikasiModel::where('referensi_id', $dok->id_dokumen_vendor)
            ->where('referensi_tipe', 'dokumen_vendor')
            ->whereDate('dibuat_pada', $today)
            ->exists();
        if ($exists) continue;

        $exp      = now()->parse($dok->berlaku_sampai);
        $daysLeft = (int) now()->diffInDays($exp, false);
        $prefix   = $daysLeft <= 7 ? '[SEGERA] ' : '';

        NotifikasiModel::create([
            'id_notifikasi'  => Str::uuid()->toString(),
            'id_perusahaan'  => $dok->id_perusahaan,
            'id_pengguna'    => null,
            'judul'          => "{$prefix}Dokumen {$dok->jenis_dokumen} vendor {$dok->nama_vendor} kadaluarsa dalam {$daysLeft} hari",
            'isi'            => "Dokumen {$dok->jenis_dokumen} untuk vendor {$dok->nama_vendor} akan kadaluarsa pada ".
                                $exp->format('d M Y')." ({$daysLeft} hari lagi). Segera perbarui.",
            'tipe'           => 'alert_dokumen',
            'referensi_id'   => $dok->id_dokumen_vendor,
            'referensi_tipe' => 'dokumen_vendor',
            'dibaca'         => 0,
        ]);
        $created++;
    }

    $this->info("Notifikasi dokumen kadaluarsa: {$created} notifikasi baru dibuat.");
    Log::info("notifikasi:dokumen-kadaluarsa — {$created} notifikasi dibuat.");
})->purpose('Buat notifikasi untuk dokumen armada yang akan kadaluarsa dalam 30 hari')->dailyAt('07:00');

Artisan::command('notifikasi:trip-belum-selesai', function () {
    $cutoff = now()->subHours(24)->toDateTimeString();
    $today  = now()->toDateString();

    // Trip yang status 'berjalan' sudah lebih dari 24 jam tanpa checkout
    $trips = DB::table('trip as t')
        ->join('jadwal_keberangkatan as j', 't.id_jadwal', '=', 'j.id_jadwal')
        ->join('penugasan as pn', 'j.id_penugasan', '=', 'pn.id_penugasan')
        ->join('proyek as pr', 'pn.id_proyek', '=', 'pr.id_proyek')
        ->whereNull('t.dihapus_pada')
        ->where('t.status', 'berjalan')
        ->whereNotNull('t.waktu_checkin')
        ->where('t.waktu_checkin', '<', $cutoff)
        ->whereNull('t.waktu_checkout')
        ->select('t.id_trip', 't.waktu_checkin', 'pr.id_perusahaan', 'pr.nama_proyek')
        ->get();

    if ($trips->isEmpty()) {
        $this->info('Tidak ada trip yang belum selesai.');
        return;
    }

    $created = 0;
    foreach ($trips as $trip) {
        $exists = NotifikasiModel::where('referensi_id', $trip->id_trip)
            ->where('referensi_tipe', 'trip')
            ->whereDate('dibuat_pada', $today)
            ->exists();
        if ($exists) continue;

        $hours = (int) now()->diffInHours(now()->parse($trip->waktu_checkin), true);

        NotifikasiModel::create([
            'id_notifikasi'  => Str::uuid()->toString(),
            'id_perusahaan'  => $trip->id_perusahaan,
            'id_pengguna'    => null,
            'judul'          => "Trip belum selesai ({$hours} jam)",
            'isi'            => "Trip dalam proyek \"{$trip->nama_proyek}\" sudah berjalan {$hours} jam sejak check-in dan belum dilaporkan selesai. Harap segera verifikasi.",
            'tipe'           => 'reminder_trip',
            'referensi_id'   => $trip->id_trip,
            'referensi_tipe' => 'trip',
            'dibaca'         => 0,
        ]);
        $created++;
    }

    $this->info("Notifikasi trip belum selesai: {$created} notifikasi baru dibuat.");
    Log::info("notifikasi:trip-belum-selesai — {$created} notifikasi dibuat.");
})->purpose('Buat notifikasi untuk trip yang berjalan lebih dari 24 jam tanpa checkout')->dailyAt('08:00');

Artisan::command('servis:backfill-jadwal', function () {
    // Servis TERBARU per armada (correlated subquery: tanggal DESC, dibuat_pada DESC)
    // yang jadwal_servis_berikutnya masih kosong dan punya id_jenis_perawatan.
    $kandidat = DB::table('perawatan_armada as p1')
        ->join('armada as a', 'a.id_armada', '=', 'p1.id_armada')
        ->whereNull('p1.dihapus_pada')
        ->whereNull('a.dihapus_pada')
        ->whereNull('p1.jadwal_servis_berikutnya')
        ->whereNotNull('p1.id_jenis_perawatan')
        ->whereRaw('p1.id_perawatan = (
            SELECT p2.id_perawatan FROM perawatan_armada p2
            WHERE p2.id_armada = p1.id_armada AND p2.dihapus_pada IS NULL
            ORDER BY p2.tanggal DESC, p2.dibuat_pada DESC
            LIMIT 1
        )')
        ->select('p1.id_perawatan', 'p1.tanggal', 'p1.id_jenis_perawatan', 'a.id_jenis_kendaraan', 'a.id_perusahaan')
        ->get();

    $terisi = 0;
    foreach ($kandidat as $row) {
        if ($row->id_jenis_kendaraan === null) {
            continue;
        }

        $interval = DB::table('interval_perawatan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $row->id_perusahaan)
            ->where('id_jenis_perawatan', $row->id_jenis_perawatan)
            ->where('id_jenis_kendaraan', $row->id_jenis_kendaraan)
            ->value('interval_hari');

        if ($interval === null) {
            continue;
        }

        $jadwal = now()->parse($row->tanggal)->addDays((int) $interval)->toDateString();

        DB::table('perawatan_armada')
            ->where('id_perawatan', $row->id_perawatan)
            ->update(['jadwal_servis_berikutnya' => $jadwal]);
        $terisi++;
    }

    $this->info("Backfill jadwal servis: {$terisi} catatan perawatan ter-update.");
    Log::info("servis:backfill-jadwal — {$terisi} catatan ter-update.");
})->purpose('Isi jadwal_servis_berikutnya yang masih kosong dari interval yang sudah didefinisikan (sekali-jalan, aman diulang)');

Artisan::command('notifikasi:servis-jatuh-tempo', function () {
    $today = now()->toDateString();
    $batas = now()->addDays(30)->toDateString();

    // Servis TERBARU per armada (pola sama dgn servis:backfill-jadwal) yang
    // jadwal_servis_berikutnya jatuh dalam 30 hari ke depan.
    $servis = DB::table('perawatan_armada as p1')
        ->join('armada as a', 'a.id_armada', '=', 'p1.id_armada')
        ->whereNull('p1.dihapus_pada')
        ->whereNull('a.dihapus_pada')
        ->whereNotNull('p1.jadwal_servis_berikutnya')
        ->where('p1.jadwal_servis_berikutnya', '<=', $batas)
        ->whereRaw('p1.id_perawatan = (
            SELECT p2.id_perawatan FROM perawatan_armada p2
            WHERE p2.id_armada = p1.id_armada AND p2.dihapus_pada IS NULL
            ORDER BY p2.tanggal DESC, p2.dibuat_pada DESC
            LIMIT 1
        )')
        ->select('p1.id_perawatan', 'p1.jenis_perawatan', 'p1.jadwal_servis_berikutnya', 'a.nopol', 'a.id_perusahaan')
        ->get();

    $created = 0;
    foreach ($servis as $s) {
        $exists = NotifikasiModel::where('referensi_id', $s->id_perawatan)
            ->where('referensi_tipe', 'perawatan_armada')
            ->whereDate('dibuat_pada', $today)
            ->exists();
        if ($exists) continue;

        $jadwal    = now()->parse($s->jadwal_servis_berikutnya);
        $daysLeft  = (int) now()->diffInDays($jadwal, false);
        $prefix    = $daysLeft <= 7 ? '[SEGERA] ' : '';

        NotifikasiModel::create([
            'id_notifikasi'  => Str::uuid()->toString(),
            'id_perusahaan'  => $s->id_perusahaan,
            'id_pengguna'    => null,
            'judul'          => "{$prefix}Servis {$s->jenis_perawatan} {$s->nopol} jatuh tempo dalam {$daysLeft} hari",
            'isi'            => "Servis {$s->jenis_perawatan} untuk armada {$s->nopol} jatuh tempo pada ".
                                $jadwal->format('d M Y')." ({$daysLeft} hari lagi). Segera jadwalkan servis.",
            'tipe'           => 'alert_servis',
            'referensi_id'   => $s->id_perawatan,
            'referensi_tipe' => 'perawatan_armada',
            'dibaca'         => 0,
        ]);
        $created++;
    }

    $this->info("Notifikasi servis jatuh tempo: {$created} notifikasi baru dibuat.");
    Log::info("notifikasi:servis-jatuh-tempo — {$created} notifikasi dibuat.");
})->purpose('Buat notifikasi untuk servis armada yang jatuh tempo dalam 30 hari (berdasarkan servis terbaru per armada)')->dailyAt('07:15');
