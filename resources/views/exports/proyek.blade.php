<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Proyek</title>
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

        table.rincian { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        table.rincian th { background: #0e7490; color: #ffffff; padding: 6px 9px; text-align: left; font-size: 10.5px; }
        table.rincian th.jumlah { text-align: right; }
        table.rincian td { padding: 5.5px 9px; border-bottom: 1px solid #e5e7eb; }
        table.rincian td.jumlah { text-align: right; white-space: nowrap; }
        table.rincian tr.subtotal td { font-weight: bold; background: #f0fdfa; border-top: 1.5px solid #0e7490; }
        .ket { color: #9ca3af; font-size: 9.5px; }

        .kosong { color: #9ca3af; text-align: center; padding: 18px 0; }
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
        $tgl = fn ($v) => $v ? date('d/m/Y', strtotime($v)) : '-';
        $total = collect($items)->sum(fn ($i) => $i->harga_penawaran !== null
            ? (float) $i->harga_penawaran * (int) $i->estimasi_ritase
            : 0);
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
            <h1>PROYEK</h1>
            <div class="garis"></div>
        </div>
        <p class="periode">{{ $p->nama_proyek }}</p>

        <table class="info">
            <tr>
                <td class="label">Kode Proyek</td><td class="titik">:</td>
                <td><strong>{{ $p->kode_proyek }}</strong></td>
                <td class="label">Tanggal Mulai</td><td class="titik">:</td>
                <td>{{ $tgl($p->tanggal_mulai) }}</td>
            </tr>
            <tr>
                <td class="label">Klien</td><td class="titik">:</td>
                <td>{{ $klien->nama_klien ?? '-' }}</td>
                <td class="label">Tanggal Selesai</td><td class="titik">:</td>
                <td>{{ $tgl($p->tanggal_selesai) }}</td>
            </tr>
        </table>

        @if (count($items) > 0)
            <table class="rincian">
                <thead>
                    <tr>
                        <th>RUTE</th>
                        <th>JENIS KENDARAAN</th>
                        <th class="jumlah">HARGA SATUAN</th>
                        <th class="jumlah">RITASE</th>
                        <th class="jumlah">SUBTOTAL</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $item)
                        <tr>
                            <td>
                                {{ $item->nama_rute ?? '-' }}
                                @if ($item->asal && $item->tujuan)
                                    <span class="ket">({{ $item->asal }} — {{ $item->tujuan }})</span>
                                @endif
                                @if ($item->keterangan)
                                    <br><span class="ket">{{ $item->keterangan }}</span>
                                @endif
                            </td>
                            <td>{{ $item->nama_jenis ?? '-' }}</td>
                            <td class="jumlah">{{ $item->harga_penawaran !== null ? $rp($item->harga_penawaran) : '-' }}</td>
                            <td class="jumlah">{{ number_format((int) $item->estimasi_ritase, 0, ',', '.') }}</td>
                            <td class="jumlah">{{ $item->harga_penawaran !== null ? $rp((float) $item->harga_penawaran * (int) $item->estimasi_ritase) : '-' }}</td>
                        </tr>
                    @endforeach
                    <tr class="subtotal">
                        <td colspan="4">TOTAL NILAI PROYEK</td>
                        <td class="jumlah">{{ $rp($total) }}</td>
                    </tr>
                </tbody>
            </table>
        @else
            <p class="kosong">Belum ada rute untuk proyek ini.</p>
        @endif

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
