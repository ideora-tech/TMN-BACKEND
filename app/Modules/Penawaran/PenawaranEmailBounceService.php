<?php

declare(strict_types=1);

namespace App\Modules\Penawaran;

use App\Modules\Notifikasi\NotifikasiService;
use App\Modules\Penawaran\Contracts\PenawaranRepositoryInterface;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Webklex\PHPIMAP\ClientManager;

class PenawaranEmailBounceService
{
    private const MENU_PENAWARAN = ['/penawaran'];

    private const POLA_PENGIRIM_BOUNCE = '/mailer-daemon|postmaster|mail delivery (system|subsystem)/i';

    private const POLA_SUBJEK_BOUNCE = '/undeliver|delivery (status|failure|failed|has failed|notification)|mail delivery failed|returned mail|failure notice|returning message|could not be delivered/i';

    public function __construct(
        private readonly PenawaranRepositoryInterface $repo,
        private readonly NotifikasiService $notifikasi,
    ) {}

    public function imapTersedia(): bool
    {
        $c = (array) config('mail.imap', []);

        return config('mail.default') === 'smtp'
            && !empty($c['host'])
            && !empty($c['username'])
            && !empty($c['password']);
    }

    public function periksa(): array
    {
        $ringkas = ['diperiksa' => 0, 'bounce' => 0, 'cocok' => 0];

        if (!$this->imapTersedia()) {
            return $ringkas + ['dilewati' => 'IMAP belum dikonfigurasi (MAIL_MAILER bukan smtp atau kredensial kosong)'];
        }

        $c = (array) config('mail.imap');
        $client = (new ClientManager())->make([
            'host'          => $c['host'],
            'port'          => (int) ($c['port'] ?: 993),
            'encryption'    => $c['encryption'] ?: false,
            'validate_cert' => true,
            'username'      => $c['username'],
            'password'      => $c['password'],
            'protocol'      => 'imap',
        ]);
        $client->connect();

        try {
            $folder = $client->getFolderByName($c['folder'] ?: 'INBOX');
            if ($folder === null) {
                return $ringkas + ['dilewati' => 'Folder IMAP tidak ditemukan'];
            }

            $daftar = $folder->query()
                ->whereUnseen()
                ->whereSince(now()->subDays((int) ($c['hari_mundur'] ?: 7)))
                ->setFetchBody(true)
                ->leaveUnread()
                ->get();

            foreach ($daftar as $pesan) {
                $ringkas['diperiksa']++;

                $dari = collect($pesan->getFrom()?->all() ?? [])
                    ->map(fn ($a) => is_object($a) ? ($a->full ?: $a->mail) : (string) $a)
                    ->implode(', ');
                $isi = $pesan->getTextBody() ?: strip_tags($pesan->getHTMLBody());

                $bounce = $this->analisisBounce((string) $pesan->getSubject(), $dari, $isi);
                if ($bounce === null) {
                    continue;
                }

                $ringkas['bounce']++;
                $tanggal = $pesan->getDate();
                $waktu = $tanggal && $tanggal->first() ? $tanggal->toDate() : now();

                if ($this->cocokkanDanTandai($bounce, $waktu) !== null) {
                    $ringkas['cocok']++;
                }

                $pesan->setFlag('Seen');
            }
        } finally {
            $client->disconnect();
        }

        return $ringkas;
    }

    public function analisisBounce(string $subjek, string $dari, string $isi): ?array
    {
        $indikasi = preg_match(self::POLA_PENGIRIM_BOUNCE, $dari) === 1
            || preg_match(self::POLA_SUBJEK_BOUNCE, $subjek) === 1;
        if (!$indikasi) {
            return null;
        }

        return [
            'penerima'   => $this->ambilPenerima($isi),
            'alasan'     => $this->ambilAlasan($isi, $subjek),
            'message_id' => $this->ambilMessageId($isi),
        ];
    }

    public function cocokkanDanTandai(array $bounce, CarbonInterface $waktuBounce): ?PenawaranModel
    {
        $record = null;
        foreach ($bounce['message_id'] ?? [] as $messageId) {
            $record = $this->repo->findByEmailMessageId($messageId);
            if ($record !== null) {
                break;
            }
        }

        if ($record === null && !empty($bounce['penerima'])) {
            $record = $this->repo->findKirimanEmailTerakhirKe($bounce['penerima'], $waktuBounce->toDateTimeString());
        }

        if ($record === null) {
            return null;
        }

        $alasan  = $bounce['alasan'] ?? null;
        $updated = $this->repo->update($record, [
            'email_gagal_pada'   => $waktuBounce->toDateTimeString(),
            'email_gagal_alasan' => $alasan,
        ]);

        $this->notifikasi->kirimKePemilikIzinMenu(
            self::MENU_PENAWARAN,
            $updated->id_perusahaan,
            "Email penawaran {$updated->nomor_penawaran} gagal terkirim",
            "Email ke {$updated->email_terkirim_ke} gagal terkirim" . ($alasan ? ": {$alasan}" : '') . '. Periksa alamat email klien lalu kirim ulang.',
            'email_gagal',
            'penawaran',
            $updated->id_penawaran,
            '/penawaran/' . $updated->id_penawaran,
        );

        return $updated;
    }

    private function ambilPenerima(string $isi): ?string
    {
        $pola = [
            '/(?:Final|Original)-Recipient:\s*rfc822;\s*<?([^\s<>]+@[^\s<>]+)>?/i',
            '/address\(es\) failed:\s*\R\s*<?([^\s<>]+@[^\s<>]+)>?/i',
            '/<([^\s<>]+@[^\s<>]+)>:/',
        ];
        foreach ($pola as $p) {
            if (preg_match($p, $isi, $m)) {
                return strtolower(trim($m[1], " .,;\t"));
            }
        }

        preg_match_all('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/i', $isi, $semua);
        $pengirimSendiri = strtolower((string) config('mail.from.address'));
        foreach ($semua[0] ?? [] as $kandidat) {
            $kandidat = strtolower($kandidat);
            if (preg_match('/mailer-daemon|postmaster/i', $kandidat) || $kandidat === $pengirimSendiri) {
                continue;
            }
            return $kandidat;
        }

        return null;
    }

    private function ambilAlasan(string $isi, string $subjek): string
    {
        $alasan = null;
        if (preg_match('/Diagnostic-Code:\s*(?:smtp;)?\s*(.+)/i', $isi, $m)) {
            $alasan = $m[1];
        } elseif (preg_match('/\b(5\d\d[- ][^\r\n]*)/', $isi, $m)) {
            $alasan = $m[1];
        } elseif (preg_match('/^.*(does not exist|user unknown|no such user|mailbox (?:full|unavailable)|rejected|blocked|quota).*$/im', $isi, $m)) {
            $alasan = $m[0];
        }

        $alasan = trim(preg_replace('/\s+/', ' ', (string) ($alasan ?: $subjek)));

        return Str::limit($alasan !== '' ? $alasan : 'Gagal terkirim', 500, '');
    }

    private function ambilMessageId(string $isi): array
    {
        preg_match_all('/Message-ID:\s*<([^>]+)>/i', $isi, $m);

        return array_values(array_unique(array_map('strtolower', $m[1] ?? [])));
    }
}
