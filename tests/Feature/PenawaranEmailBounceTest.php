<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Penawaran\PenawaranEmailBounceService;
use App\Modules\Penawaran\PenawaranModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PenawaranEmailBounceTest extends TestCase
{
    use RefreshDatabase;

    private const BOUNCE_EXIM = <<<'TXT'
This message was created automatically by mail delivery software.

A message that you sent could not be delivered to one or more of its
recipients. This is a permanent error. The following address(es) failed:

  ariefmanggalaputra25@gmail.com
    host gmail-smtp-in.l.google.com [142.250.4.26]
    SMTP error from remote mail server after RCPT TO:<ariefmanggalaputra25@gmail.com>:
    550-5.1.1 The email account that you tried to reach does not exist.

------ This is a copy of the message, including all the headers. ------

Return-path: <david.j@sulita.co.id>
From: PT Sulita Logistik Indonesia <david.j@sulita.co.id>
To: ariefmanggalaputra25@gmail.com
Subject: Penawaran PNW-202609-0011
Message-ID: <abc123@sulita.co.id>
TXT;

    private const BOUNCE_DSN = <<<'TXT'
The original message was received at Wed, 23 Sep 2026 10:32:40 +0700

   ----- The following addresses had permanent fatal errors -----
<klien@tes.com>

Reporting-MTA: dns; mail.sulita.co.id
Final-Recipient: rfc822; klien@tes.com
Action: failed
Status: 5.1.1
Diagnostic-Code: smtp; 550 5.1.1 User unknown
TXT;

    private function makePenawaranTerkirim(string $emailTujuan, string $waktu, ?string $messageId = null): PenawaranModel
    {
        return PenawaranModel::create([
            'id_perusahaan'       => self::PERUSAHAAN_ID,
            'nomor_penawaran'     => 'PWR-' . Str::random(8),
            'judul'               => 'Penawaran Bounce Test',
            'status'              => 'terkirim',
            'email_terkirim_ke'   => $emailTujuan,
            'email_terkirim_pada' => $waktu,
            'email_message_id'    => $messageId,
        ]);
    }

    private function service(): PenawaranEmailBounceService
    {
        return app(PenawaranEmailBounceService::class);
    }

    public function test_analisis_bounce_gaya_exim_mengambil_penerima_alasan_dan_message_id(): void
    {
        $hasil = $this->service()->analisisBounce(
            'Mail delivery failed: returning message to sender',
            'Mail Delivery System <Mailer-Daemon@mail.sulita.co.id>',
            self::BOUNCE_EXIM,
        );

        $this->assertNotNull($hasil);
        $this->assertSame('ariefmanggalaputra25@gmail.com', $hasil['penerima']);
        $this->assertStringContainsString('550', $hasil['alasan']);
        $this->assertContains('abc123@sulita.co.id', $hasil['message_id']);
    }

    public function test_analisis_bounce_gaya_dsn_mengambil_final_recipient_dan_diagnostic_code(): void
    {
        $hasil = $this->service()->analisisBounce(
            'Undelivered Mail Returned to Sender',
            'MAILER-DAEMON@mail.sulita.co.id',
            self::BOUNCE_DSN,
        );

        $this->assertNotNull($hasil);
        $this->assertSame('klien@tes.com', $hasil['penerima']);
        $this->assertStringContainsString('User unknown', $hasil['alasan']);
    }

    public function test_analisis_bounce_mengabaikan_email_biasa(): void
    {
        $hasil = $this->service()->analisisBounce(
            'Re: Penawaran PNW-202609-0011',
            'Klien <klien@tes.com>',
            'Terima kasih, penawarannya sudah kami terima.',
        );

        $this->assertNull($hasil);
    }

    public function test_cocokkan_prioritas_message_id(): void
    {
        $this->makePenawaranTerkirim('ariefmanggalaputra25@gmail.com', '2026-09-23 09:00:00');
        $target = $this->makePenawaranTerkirim('ariefmanggalaputra25@gmail.com', '2026-09-23 08:00:00', 'abc123@sulita.co.id');

        $hasil = $this->service()->cocokkanDanTandai(
            ['penerima' => 'ariefmanggalaputra25@gmail.com', 'alasan' => '550 tidak ada', 'message_id' => ['abc123@sulita.co.id']],
            now()->parse('2026-09-23 10:00:00'),
        );

        $this->assertNotNull($hasil);
        $this->assertSame($target->id_penawaran, $hasil->id_penawaran);
        $this->assertDatabaseHas('penawaran', [
            'id_penawaran'       => $target->id_penawaran,
            'email_gagal_alasan' => '550 tidak ada',
        ]);
        $this->assertNotNull($hasil->email_gagal_pada);
    }

    public function test_cocokkan_fallback_ke_kiriman_terakhir_untuk_penerima_yang_sama(): void
    {
        $lama  = $this->makePenawaranTerkirim('klien@tes.com', '2026-09-22 10:00:00');
        $baru  = $this->makePenawaranTerkirim('klien@tes.com', '2026-09-23 10:00:00');
        $lain  = $this->makePenawaranTerkirim('orang-lain@tes.com', '2026-09-23 10:30:00');

        $hasil = $this->service()->cocokkanDanTandai(
            ['penerima' => 'KLIEN@tes.com', 'alasan' => 'User unknown', 'message_id' => []],
            now()->parse('2026-09-23 11:00:00'),
        );

        $this->assertSame($baru->id_penawaran, $hasil?->id_penawaran);
        $this->assertNull($lama->fresh()->email_gagal_pada);
        $this->assertNull($lain->fresh()->email_gagal_pada);
    }

    public function test_cocokkan_mengabaikan_yang_sudah_ditandai_gagal_dan_kiriman_setelah_bounce(): void
    {
        $sudahGagal = $this->makePenawaranTerkirim('klien@tes.com', '2026-09-23 09:00:00');
        $sudahGagal->update(['email_gagal_pada' => '2026-09-23 09:30:00', 'email_gagal_alasan' => 'lama']);
        $this->makePenawaranTerkirim('klien@tes.com', '2026-09-23 12:00:00');

        $hasil = $this->service()->cocokkanDanTandai(
            ['penerima' => 'klien@tes.com', 'alasan' => 'x', 'message_id' => []],
            now()->parse('2026-09-23 11:00:00'),
        );

        $this->assertNull($hasil);
    }

    public function test_cocokkan_membuat_notifikasi_ke_pemilik_menu_penawaran(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $target = $this->makePenawaranTerkirim('klien@tes.com', '2026-09-23 10:00:00');

        $this->service()->cocokkanDanTandai(
            ['penerima' => 'klien@tes.com', 'alasan' => 'User unknown', 'message_id' => []],
            now()->parse('2026-09-23 11:00:00'),
        );

        $this->assertDatabaseHas('notifikasi', [
            'referensi_id'   => $target->id_penawaran,
            'referensi_tipe' => 'penawaran',
            'tipe'           => 'email_gagal',
        ]);
    }

    public function test_kirim_email_menyimpan_message_id_dan_mereset_status_gagal(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $penawaran = $this->makePenawaranTerkirim('klien@tes.com', '2026-09-20 10:00:00');
        $penawaran->update(['email_gagal_pada' => '2026-09-20 11:00:00', 'email_gagal_alasan' => 'lama']);

        $res = $this->postJson("/api/penawaran/{$penawaran->id_penawaran}/kirim-email", [
            'email_tujuan' => 'klien@tes.com',
            'subjek'       => 'Subjek',
            'pesan'        => '<p>Halo</p>',
        ]);

        $res->assertStatus(200);
        $segar = $penawaran->fresh();
        $this->assertNotEmpty($segar->email_message_id);
        $this->assertNull($segar->email_gagal_pada);
        $this->assertNull($segar->email_gagal_alasan);
        $res->assertJsonPath('data.email_gagal_pada', null);
    }

    public function test_detail_penawaran_menyertakan_status_gagal_email(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $penawaran = $this->makePenawaranTerkirim('klien@tes.com', '2026-09-23 10:00:00');
        $penawaran->update(['email_gagal_pada' => '2026-09-23 11:00:00', 'email_gagal_alasan' => '550 User unknown']);

        $res = $this->getJson("/api/penawaran/{$penawaran->id_penawaran}");

        $res->assertStatus(200);
        $res->assertJsonPath('data.email_gagal_alasan', '550 User unknown');
        $this->assertNotNull($res->json('data.email_gagal_pada'));
    }

    public function test_command_cek_bounce_dilewati_jika_imap_belum_dikonfigurasi(): void
    {
        config(['mail.default' => 'array']);

        $this->artisan('email:cek-bounce')
            ->expectsOutputToContain('dilewati')
            ->assertSuccessful();
    }
}
