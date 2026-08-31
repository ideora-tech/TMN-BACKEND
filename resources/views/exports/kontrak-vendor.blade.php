<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Kontrak Vendor</title>
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
        td.label { width: 120px; color: #6b7280; }
        td.titik { width: 10px; }

        .subjudul { font-size: 12px; font-weight: bold; color: #1e2a5a; margin: 14px 0 6px; }

        table.rincian { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        table.rincian th { background: #0e7490; color: #ffffff; padding: 6px 8px; text-align: left; font-size: 10px; }
        table.rincian td { padding: 5.5px 8px; border-bottom: 1px solid #e5e7eb; }
        .ket { color: #9ca3af; font-size: 9.5px; }

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
        $mekanismeLabel = ['unit_only' => 'Unit Only', 'unit_driver' => 'Unit + Driver', 'full' => 'All In'];
        $statusLabel = ['draft' => 'Draft', 'menunggu_approval' => 'Menunggu Approval', 'aktif' => 'Aktif', 'selesai' => 'Selesai', 'batal' => 'Batal'];
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
            <h1>KONTRAK VENDOR</h1>
            <div class="garis"></div>
        </div>
        <p class="periode">{{ $namaVendor ?? '-' }}</p>

        <table class="info">
            <tr>
                <td class="label">No. Kontrak</td><td class="titik">:</td>
                <td><strong>{{ $kontrak->nomor_kontrak ?? '-' }}</strong></td>
                <td class="label">Mekanisme</td><td class="titik">:</td>
                <td>{{ $mekanismeLabel[$kontrak->mekanisme] ?? $kontrak->mekanisme }}</td>
            </tr>
            <tr>
                <td class="label">Vendor</td><td class="titik">:</td>
                <td>{{ $namaVendor ?? '-' }}</td>
                <td class="label">Nilai Kontrak</td><td class="titik">:</td>
                <td>{{ $kontrak->nilai_kontrak ? $rp($kontrak->nilai_kontrak) : '-' }}</td>
            </tr>
            <tr>
                <td class="label">Rate</td><td class="titik">:</td>
                <td>{{ $kontrak->rate ? $rp($kontrak->rate) . ($kontrak->satuan ? ' / ' . $kontrak->satuan : '') : '-' }}</td>
                <td class="label">Termin Pembayaran</td><td class="titik">:</td>
                <td>{{ $kontrak->termin_pembayaran_hari !== null ? $kontrak->termin_pembayaran_hari . ' hari' : '-' }}</td>
            </tr>
            <tr>
                <td class="label">Periode</td><td class="titik">:</td>
                <td>{{ $tgl($kontrak->tanggal_mulai) }} — {{ $tgl($kontrak->tanggal_selesai) }}</td>
                <td class="label">Status</td><td class="titik">:</td>
                <td>{{ $statusLabel[$kontrak->status] ?? strtoupper((string) $kontrak->status) }}</td>
            </tr>
        </table>

        <p class="subjudul">{{ $paket ? 'Pasangan Unit + Driver' : 'Unit Disewa' }} ({{ count($units) }})</p>
        <table class="rincian">
            <thead>
                <tr>
                    <th>NO</th>
                    <th>NOPOL</th>
                    <th>MERK</th>
                    <th>JENIS</th>
                    <th>KAPASITAS</th>
                    @if ($paket)
                        <th>DRIVER</th>
                        <th>TELEPON</th>
                        <th>NO. SIM</th>
                    @endif
                    <th>HABIS STNK</th>
                    <th>HABIS KIR</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($units as $i => $u)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td><strong>{{ $u->nopol }}</strong></td>
                        <td>{{ $u->merk ?? '-' }}</td>
                        <td>{{ $u->jenis ?? '-' }}</td>
                        <td>{{ $u->kapasitas ?? '-' }}</td>
                        @if ($paket)
                            <td>{{ $u->driver_nama ?? '-' }}</td>
                            <td>{{ $u->driver_telepon ?? '-' }}</td>
                            <td>{{ $u->driver_no_sim ?? '-' }}</td>
                        @endif
                        <td>{{ $tgl($u->masa_berlaku_stnk) }}</td>
                        <td>{{ $tgl($u->masa_berlaku_kir) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $paket ? 10 : 7 }}" class="ket">Belum ada unit tertaut ke kontrak ini.</td></tr>
                @endforelse
            </tbody>
        </table>

        @if ($paket && count($cadangan) > 0)
            <p class="subjudul">Driver Cadangan ({{ count($cadangan) }})</p>
            <table class="rincian">
                <thead>
                    <tr>
                        <th>NO</th>
                        <th>NAMA</th>
                        <th>TELEPON</th>
                        <th>NO. SIM</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($cadangan as $i => $s)
                        <tr>
                            <td>{{ $i + 1 }}</td>
                            <td>{{ $s->nama }}</td>
                            <td>{{ $s->telepon ?? '-' }}</td>
                            <td>{{ $s->no_sim ?? '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
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
