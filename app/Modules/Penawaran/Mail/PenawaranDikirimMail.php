<?php

declare(strict_types=1);

namespace App\Modules\Penawaran\Mail;

use App\Modules\Penawaran\PenawaranModel;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PenawaranDikirimMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly PenawaranModel $penawaran,
        public readonly string $subjek,
        public readonly string $pesan,
        private readonly string $pdfBinary,
        private readonly string $namaFilePdf,
        private readonly array $lampiranTambahan = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjek);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.penawaran-dikirim',
            with: ['pesan' => $this->pesan],
        );
    }

    public function attachments(): array
    {
        $daftar = [
            Attachment::fromData(fn () => $this->pdfBinary, $this->namaFilePdf)
                ->withMime('application/pdf'),
        ];

        foreach ($this->lampiranTambahan as $lampiran) {
            $daftar[] = Attachment::fromData(fn () => $lampiran['isi'], $lampiran['nama'])
                ->withMime($lampiran['mime']);
        }

        return $daftar;
    }
}
