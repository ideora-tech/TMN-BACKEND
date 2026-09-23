<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Penawaran\Mail\PenawaranDikirimMail;
use App\Modules\Penawaran\PenawaranModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class PenawaranEmailTest extends TestCase
{
    use RefreshDatabase;

    private function makeKlien(?string $email = 'klien@tes.com'): object
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'PT Klien Email Test',
            'email'         => $email,
            'dibuat_pada'   => now(),
        ]);
        return DB::table('klien')->where('id_klien', $id)->first();
    }

    private function makePenawaran(?string $idKlien, string $status = 'terkirim'): PenawaranModel
    {
        return PenawaranModel::create([
            'id_perusahaan'     => self::PERUSAHAAN_ID,
            'id_klien'          => $idKlien,
            'nomor_penawaran'   => 'PWR-' . Str::random(8),
            'judul'             => 'Penawaran Jasa Transport',
            'nilai_penawaran'   => 15000000,
            'status'            => $status,
            'tanggal_penawaran' => now()->toDateString(),
            'tanggal_berlaku'   => now()->addDays(30)->toDateString(),
        ]);
    }

    private function payloadEmail(array $override = []): array
    {
        return array_merge([
            'email_tujuan' => 'tujuan@tes.com',
            'subjek'       => 'Subjek Penawaran',
            'pesan'        => '<p>Yth. Bapak/Ibu, berikut kami lampirkan penawaran.</p>',
        ], $override);
    }

    public function test_kirim_email_penawaran_berhasil_dengan_alamat_subjek_pesan_dari_form(): void
    {
        Mail::fake();
        $this->actingAsRole('SUPERADMIN');
        $klien     = $this->makeKlien('klien@tes.com');
        $penawaran = $this->makePenawaran($klien->id_klien, 'terkirim');

        $res = $this->postJson("/api/penawaran/{$penawaran->id_penawaran}/kirim-email", $this->payloadEmail([
            'email_tujuan' => 'tujuan-custom@tes.com',
            'subjek'       => "Penawaran {$penawaran->nomor_penawaran} - custom",
            'pesan'        => 'Isi pesan custom dari user.',
        ]));

        $res->assertStatus(200);
        $res->assertJsonPath('data.email_terkirim_ke', 'tujuan-custom@tes.com');
        $this->assertNotNull($res->json('data.email_terkirim_pada'));

        $this->assertDatabaseHas('penawaran', [
            'id_penawaran'      => $penawaran->id_penawaran,
            'email_terkirim_ke' => 'tujuan-custom@tes.com',
        ]);

        Mail::assertSent(PenawaranDikirimMail::class, function ($mail) use ($penawaran) {
            return $mail->hasTo('tujuan-custom@tes.com')
                && $mail->envelope()->subject === "Penawaran {$penawaran->nomor_penawaran} - custom"
                && count($mail->attachments()) === 1;
        });
    }

    public function test_kirim_email_berhasil_walau_penawaran_tidak_ada_klien_asal_email_tujuan_diisi(): void
    {
        Mail::fake();
        $this->actingAsRole('SUPERADMIN');
        $penawaran = $this->makePenawaran(null, 'terkirim');

        $res = $this->postJson("/api/penawaran/{$penawaran->id_penawaran}/kirim-email", $this->payloadEmail([
            'email_tujuan' => 'siapapun@tes.com',
        ]));

        $res->assertStatus(200);
        Mail::assertSent(PenawaranDikirimMail::class, fn ($mail) => $mail->hasTo('siapapun@tes.com'));
    }

    public function test_kirim_email_gagal_jika_email_tujuan_kosong(): void
    {
        Mail::fake();
        $this->actingAsRole('SUPERADMIN');
        $klien     = $this->makeKlien();
        $penawaran = $this->makePenawaran($klien->id_klien, 'terkirim');

        $res = $this->postJson("/api/penawaran/{$penawaran->id_penawaran}/kirim-email", $this->payloadEmail(['email_tujuan' => '']));

        $res->assertStatus(422);
        Mail::assertNothingSent();
    }

    public function test_kirim_email_gagal_jika_format_email_tujuan_salah(): void
    {
        Mail::fake();
        $this->actingAsRole('SUPERADMIN');
        $klien     = $this->makeKlien();
        $penawaran = $this->makePenawaran($klien->id_klien, 'terkirim');

        $res = $this->postJson("/api/penawaran/{$penawaran->id_penawaran}/kirim-email", $this->payloadEmail(['email_tujuan' => 'bukan-email']));

        $res->assertStatus(422);
        Mail::assertNothingSent();
    }

    public function test_kirim_email_gagal_jika_subjek_atau_pesan_kosong(): void
    {
        Mail::fake();
        $this->actingAsRole('SUPERADMIN');
        $klien     = $this->makeKlien();
        $penawaran = $this->makePenawaran($klien->id_klien, 'terkirim');

        $resSubjek = $this->postJson("/api/penawaran/{$penawaran->id_penawaran}/kirim-email", $this->payloadEmail(['subjek' => '']));
        $resPesan  = $this->postJson("/api/penawaran/{$penawaran->id_penawaran}/kirim-email", $this->payloadEmail(['pesan' => '']));

        $resSubjek->assertStatus(422);
        $resPesan->assertStatus(422);
        Mail::assertNothingSent();
    }

    public function test_kirim_email_gagal_untuk_status_draft(): void
    {
        Mail::fake();
        $this->actingAsRole('SUPERADMIN');
        $klien     = $this->makeKlien();
        $penawaran = $this->makePenawaran($klien->id_klien, 'draft');

        $res = $this->postJson("/api/penawaran/{$penawaran->id_penawaran}/kirim-email", $this->payloadEmail());

        $res->assertStatus(422);
        Mail::assertNothingSent();
    }

    public function test_kirim_email_gagal_untuk_status_menunggu_approval(): void
    {
        Mail::fake();
        $this->actingAsRole('SUPERADMIN');
        $klien     = $this->makeKlien();
        $penawaran = $this->makePenawaran($klien->id_klien, 'menunggu_approval');

        $res = $this->postJson("/api/penawaran/{$penawaran->id_penawaran}/kirim-email", $this->payloadEmail());

        $res->assertStatus(422);
        Mail::assertNothingSent();
    }

    public function test_kirim_email_boleh_untuk_status_negosiasi_dan_disetujui(): void
    {
        Mail::fake();
        $this->actingAsRole('SUPERADMIN');
        $klien = $this->makeKlien();

        foreach (['negosiasi', 'disetujui'] as $status) {
            $penawaran = $this->makePenawaran($klien->id_klien, $status);
            $res = $this->postJson("/api/penawaran/{$penawaran->id_penawaran}/kirim-email", $this->payloadEmail());
            $res->assertStatus(200);
        }
    }

    public function test_kirim_email_penawaran_tidak_ditemukan_mengembalikan_404(): void
    {
        Mail::fake();
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/penawaran/' . (string) Str::uuid() . '/kirim-email', $this->payloadEmail());

        $res->assertStatus(404);
    }

    public function test_kirim_email_penawaran_milik_perusahaan_lain_mengembalikan_404(): void
    {
        Mail::fake();
        $this->actingAsRole('SUPERADMIN');

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert([
            'id_perusahaan' => $idPerusahaanLain,
            'nama'          => 'Perusahaan Lain Test',
            'dibuat_pada'   => now(),
        ]);

        $penawaranLain = PenawaranModel::create([
            'id_perusahaan'     => $idPerusahaanLain,
            'nomor_penawaran'   => 'PWR-' . Str::random(8),
            'judul'             => 'Penawaran Perusahaan Lain',
            'nilai_penawaran'   => 5000000,
            'status'            => 'terkirim',
            'tanggal_penawaran' => now()->toDateString(),
            'tanggal_berlaku'   => now()->addDays(30)->toDateString(),
        ]);

        $res = $this->postJson("/api/penawaran/{$penawaranLain->id_penawaran}/kirim-email", $this->payloadEmail());

        $res->assertStatus(404);
    }

    public function test_kirim_email_pesan_html_dipertahankan_dan_script_dibuang(): void
    {
        Mail::fake();
        $this->actingAsRole('SUPERADMIN');
        $klien     = $this->makeKlien();
        $penawaran = $this->makePenawaran($klien->id_klien, 'terkirim');

        $res = $this->postJson("/api/penawaran/{$penawaran->id_penawaran}/kirim-email", $this->payloadEmail([
            'pesan' => '<p>Bayar <strong>dimuka</strong></p><script>alert(1)</script>',
        ]));

        $res->assertStatus(200);

        Mail::assertSent(PenawaranDikirimMail::class, function ($mail) {
            $html = $mail->render();
            return str_contains($html, '<strong>dimuka</strong>')
                && !str_contains($html, '<script>');
        });
    }

    public function test_kirim_email_dengan_lampiran_tambahan_ikut_terkirim(): void
    {
        Mail::fake();
        $this->actingAsRole('SUPERADMIN');
        $klien     = $this->makeKlien();
        $penawaran = $this->makePenawaran($klien->id_klien, 'terkirim');

        $res = $this->postJson("/api/penawaran/{$penawaran->id_penawaran}/kirim-email", $this->payloadEmail([
            'lampiran' => [
                UploadedFile::fake()->create('dokumen.pdf', 200, 'application/pdf'),
                UploadedFile::fake()->image('foto.jpg'),
            ],
        ]));

        $res->assertStatus(200);

        Mail::assertSent(PenawaranDikirimMail::class, function ($mail) {
            $nama = array_map(fn ($a) => $a->as, $mail->attachments());
            return count($mail->attachments()) === 3
                && in_array('dokumen.pdf', $nama, true)
                && in_array('foto.jpg', $nama, true);
        });
    }

    public function test_kirim_email_boleh_tanpa_lampiran_tambahan(): void
    {
        Mail::fake();
        $this->actingAsRole('SUPERADMIN');
        $klien     = $this->makeKlien();
        $penawaran = $this->makePenawaran($klien->id_klien, 'terkirim');

        $res = $this->postJson("/api/penawaran/{$penawaran->id_penawaran}/kirim-email", $this->payloadEmail());

        $res->assertStatus(200);
        Mail::assertSent(PenawaranDikirimMail::class, fn ($mail) => count($mail->attachments()) === 1);
    }

    public function test_kirim_email_gagal_jika_lampiran_lebih_dari_10(): void
    {
        Mail::fake();
        $this->actingAsRole('SUPERADMIN');
        $klien     = $this->makeKlien();
        $penawaran = $this->makePenawaran($klien->id_klien, 'terkirim');

        $lampiran = [];
        for ($i = 0; $i < 11; $i++) {
            $lampiran[] = UploadedFile::fake()->create("file{$i}.pdf", 50, 'application/pdf');
        }

        $res = $this->postJson("/api/penawaran/{$penawaran->id_penawaran}/kirim-email", $this->payloadEmail(['lampiran' => $lampiran]));

        $res->assertStatus(422);
        Mail::assertNothingSent();
    }

    public function test_kirim_email_gagal_jika_tipe_lampiran_tidak_diizinkan(): void
    {
        Mail::fake();
        $this->actingAsRole('SUPERADMIN');
        $klien     = $this->makeKlien();
        $penawaran = $this->makePenawaran($klien->id_klien, 'terkirim');

        $res = $this->postJson("/api/penawaran/{$penawaran->id_penawaran}/kirim-email", $this->payloadEmail([
            'lampiran' => [UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')],
        ]));

        $res->assertStatus(422);
        Mail::assertNothingSent();
    }

    public function test_kirim_email_gagal_jika_lampiran_lebih_dari_5mb(): void
    {
        Mail::fake();
        $this->actingAsRole('SUPERADMIN');
        $klien     = $this->makeKlien();
        $penawaran = $this->makePenawaran($klien->id_klien, 'terkirim');

        $res = $this->postJson("/api/penawaran/{$penawaran->id_penawaran}/kirim-email", $this->payloadEmail([
            'lampiran' => [UploadedFile::fake()->create('besar.pdf', 6000, 'application/pdf')],
        ]));

        $res->assertStatus(422);
        Mail::assertNothingSent();
    }

    public function test_detail_penawaran_menyertakan_nama_dan_email_klien(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien     = $this->makeKlien('kontak@klientes.com');
        $penawaran = $this->makePenawaran($klien->id_klien, 'terkirim');

        $res = $this->getJson("/api/penawaran/{$penawaran->id_penawaran}");

        $res->assertStatus(200);
        $res->assertJsonPath('data.nama_klien', 'PT Klien Email Test');
        $res->assertJsonPath('data.email_klien', 'kontak@klientes.com');
    }
}
