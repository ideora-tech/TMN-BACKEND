<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice Vendor</title>
    <style>
        @page { margin: 0; }
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; margin: 0; }

        .kop { position: relative; height: 105px; }
        .kop-band { position: absolute; top: 0; left: 0; right: 0; height: 16px; background: #0e7490; }
        .kop-band-navy { position: absolute; top: 16px; left: 55%; right: 0; height: 7px; background: #1e2a5a; }
        .kop-isi { padding: 30px 45px 0 45px; }
        .kop-isi table { border-collapse: collapse; }
        .kop-isi td { vertical-align: middle; padding: 0; }
        .kop-logo { width: 58px; }
        .kop-logo img { width: 50px; height: 50px; }
        .kop-teks { padding-left: 14px; }
        .logo-utama { font-size: 24px; font-weight: bold; color: #0e7490; letter-spacing: 1px; line-height: 1.1; }
        .logo-sub { font-size: 9px; color: #1e2a5a; letter-spacing: 3px; margin-top: 2px; }

        .konten { padding: 6px 45px 100px 45px; }

        .judul { text-align: center; margin: 14px 0 2px; }
        .judul h1 { font-size: 17px; letter-spacing: 4px; color: #1e2a5a; margin: 0; }
        .judul .garis { width: 240px; border-bottom: 2.5px solid #0e7490; margin: 5px auto 0; }
        .periode { text-align: center; color: #4b5563; margin: 6px 0 16px; font-size: 11px; }

        table.info { width: 100%; margin-bottom: 14px; border-collapse: collapse; }
        table.info td { padding: 2.5px 0; vertical-align: top; }
        td.label { width: 110px; color: #6b7280; }
        td.titik { width: 10px; }

        .subjudul { font-size: 12px; font-weight: bold; color: #1e2a5a; margin: 14px 0 6px; }

        table.rincian { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        table.rincian th { background: #0e7490; color: #ffffff; padding: 6px 9px; text-align: left; font-size: 10.5px; }
        table.rincian th.jumlah { text-align: right; }
        table.rincian td { padding: 5.5px 9px; border-bottom: 1px solid #e5e7eb; }
        table.rincian td.jumlah { text-align: right; white-space: nowrap; }
        table.rincian tr.subtotal td { font-weight: bold; background: #f0fdfa; border-top: 1.5px solid #0e7490; }
        .ket { color: #9ca3af; font-size: 9.5px; }

        .terbilang { margin-top: 10px; font-size: 10.5px; color: #374151; }

        table.riwayat { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.riwayat th { background: #1e2a5a; color: #ffffff; padding: 6px 8px; text-align: left; font-size: 10px; }
        table.riwayat th.jumlah { text-align: right; }
        table.riwayat td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; }
        table.riwayat td.jumlah { text-align: right; white-space: nowrap; }
        table.riwayat tr.ringkasan td { font-weight: bold; background: #f9fafb; }

        .cetak { margin-top: 14px; color: #9ca3af; font-size: 9px; }

        .footer { position: fixed; bottom: 0; left: 0; right: 0; height: 68px; }
        .footer-band { height: 6px; background: #0e7490; }
        .footer-isi { padding: 10px 45px; font-size: 9.5px; color: #1e2a5a; }
        .footer-isi td { padding-right: 22px; }
    </style>
</head>
<body>
    @php
        $rp = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
        $tgl = fn ($v) => $v ? date('d/m/Y', strtotime((string) $v)) : '-';
        $statusLabel = ['draft' => 'Draft', 'menunggu_approval' => 'Menunggu Approval', 'diverifikasi' => 'Diverifikasi', 'ditolak' => 'Ditolak'];
        $metodeLabel = ['transfer' => 'Transfer', 'tunai' => 'Tunai', 'giro' => 'Giro'];
    @endphp

    <div class="kop">
        <div class="kop-band"></div>
        <div class="kop-band-navy"></div>
        <div class="kop-isi">
            <table>
                <tr>
                    @if ($logoBase64)
                        <td class="kop-logo"><img src="{{ $logoBase64 }}" alt="Logo"></td>
                    @endif
                    <td class="kop-teks">
                        <div class="logo-utama">SULITA</div>
                        <div class="logo-sub">LOGISTIK INDONESIA</div>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <div class="konten">
        <div class="judul">
            <h1>INVOICE VENDOR</h1>
            <div class="garis"></div>
        </div>
        <p class="periode">{{ $d['nomor_invoice'] }}</p>

        <table class="info">
            <tr>
                <td class="label">No. Invoice</td><td class="titik">:</td>
                <td><strong>{{ $d['nomor_invoice'] }}</strong></td>
                <td class="label">Vendor</td><td class="titik">:</td>
                <td>{{ $d['vendor']['nama_vendor'] ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Tanggal Invoice</td><td class="titik">:</td>
                <td>{{ $tgl($d['tanggal_invoice']) }}</td>
                <td class="label">Kontrak</td><td class="titik">:</td>
                <td>{{ $d['kontrak']['nomor_kontrak'] ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Jatuh Tempo</td><td class="titik">:</td>
                <td>{{ $tgl($d['jatuh_tempo']) }}</td>
                <td class="label">Nopol</td><td class="titik">:</td>
                <td>{{ $d['nopol'] ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">No. PO</td><td class="titik">:</td>
                <td>{{ $d['no_po'] ?? '-' }}</td>
                <td class="label">Status</td><td class="titik">:</td>
                <td>{{ $statusLabel[$d['status']] ?? strtoupper((string) $d['status']) }}</td>
            </tr>
        </table>

        <table class="rincian">
            <thead>
                <tr>
                    <th>RINCIAN</th>
                    <th class="jumlah">NILAI</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>DPP</td>
                    <td class="jumlah">{{ $rp($d['dpp']) }}</td>
                </tr>
                <tr>
                    <td>PPN</td>
                    <td class="jumlah">{{ $rp($d['ppn']) }}</td>
                </tr>
                <tr>
                    <td>PPh</td>
                    <td class="jumlah">-{{ $rp($d['pph']) }}</td>
                </tr>
                <tr class="subtotal">
                    <td>TOTAL TAGIHAN</td>
                    <td class="jumlah">{{ $rp($d['total']) }}</td>
                </tr>
            </tbody>
        </table>

        <p class="terbilang">Terbilang: <em>{{ \App\Support\Terbilang::rupiah((float) $d['total']) }}</em></p>

        <p class="subjudul">Riwayat Pembayaran (Termin)</p>
        <table class="riwayat">
            <thead>
                <tr>
                    <th>NO</th>
                    <th>TANGGAL BAYAR</th>
                    <th>METODE</th>
                    <th>BANK PENGIRIM</th>
                    <th>NO. REFERENSI</th>
                    <th class="jumlah">NOMINAL</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($d['pembayaran'] as $i => $p)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td>{{ $tgl($p['tanggal_bayar']) }}</td>
                        <td>{{ $metodeLabel[$p['metode']] ?? $p['metode'] }}</td>
                        <td>{{ $p['bank_pengirim'] ?? '-' }}</td>
                        <td>{{ $p['no_referensi'] ?? '-' }}</td>
                        <td class="jumlah">{{ $rp($p['nominal']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="ket">Belum ada pembayaran tercatat.</td></tr>
                @endforelse
                <tr class="ringkasan">
                    <td colspan="5">Total Dibayar</td>
                    <td class="jumlah">{{ $rp($d['total_dibayar']) }}</td>
                </tr>
                <tr class="ringkasan">
                    <td colspan="5">Sisa Tagihan</td>
                    <td class="jumlah">{{ $rp($d['sisa']) }}</td>
                </tr>
            </tbody>
        </table>

        <p class="cetak">Dicetak {{ now()->format('d/m/Y H:i') }} — dokumen ini dihasilkan otomatis oleh sistem.</p>
    </div>

    <div class="footer">
        <div class="footer-band"></div>
        <div class="footer-isi">
            <table>
                <tr>
                    @if ($perusahaan->telepon ?? null)<td>Telp: {{ $perusahaan->telepon }}</td>@endif
                    @if ($perusahaan->email ?? null)<td>{{ $perusahaan->email }}</td>@endif
                    @if ($perusahaan->alamat ?? null)<td>{{ $perusahaan->alamat }}</td>@endif
                </tr>
            </table>
        </div>
    </div>
</body>
</html>
