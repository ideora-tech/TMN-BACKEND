<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Slip Gaji</title>
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

        .konten { padding: 6px 45px 130px 45px; }

        .judul { text-align: center; margin: 14px 0 2px; }
        .judul h1 { font-size: 17px; letter-spacing: 4px; color: #1e2a5a; margin: 0; }
        .judul .garis { width: 190px; border-bottom: 2.5px solid #0e7490; margin: 5px auto 0; }
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

        .bersih { background: #1e2a5a; color: #ffffff; padding: 9px 12px; margin-top: 4px; }
        .bersih table { width: 100%; border-collapse: collapse; }
        .bersih .nominal { text-align: right; font-size: 14px; font-weight: bold; }

        .catatan { margin-top: 12px; color: #6b7280; font-size: 10px; }
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
        $persen = fn ($v) => rtrim(rtrim(number_format((float) $v, 1, ',', '.'), '0'), ',');
        $jamLembur = intdiv((int) $slip->menit_lembur, 60);
        $menitLembur = ((int) $slip->menit_lembur) % 60;
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
            <h1>SLIP GAJI</h1>
            <div class="garis"></div>
        </div>
        <p class="periode">Periode {{ $periode->nama ?? '-' }}</p>

        <table class="info">
            <tr>
                <td class="label">Nama</td><td class="titik">:</td>
                <td><strong>{{ $slip->nama_karyawan }}</strong></td>
                <td class="label">NIK</td><td class="titik">:</td>
                <td>{{ $slip->karyawan_nik }}</td>
            </tr>
            <tr>
                <td class="label">Jabatan</td><td class="titik">:</td>
                <td>{{ $slip->nama_jabatan ?? '-' }}</td>
                <td class="label">Rekening</td><td class="titik">:</td>
                <td>{{ $slip->nama_bank ? ($slip->nama_bank . ' ' . $slip->nomor_rekening) : '-' }}</td>
            </tr>
            @if ($slip->proyek || $slip->absen_masuk)
                <tr>
                    <td class="label">Proyek</td><td class="titik">:</td>
                    <td>{{ $slip->proyek ?? '-' }}@if ($slip->tipe_truck) <span class="ket">({{ $slip->tipe_truck }})</span>@endif</td>
                    <td class="label">Absen Masuk</td><td class="titik">:</td>
                    <td>{{ $slip->absen_masuk ?? '-' }}</td>
                </tr>
            @endif
        </table>

        <table class="rincian">
            <thead>
                <tr><th>PENDAPATAN</th><th class="jumlah">JUMLAH</th></tr>
            </thead>
            <tbody>
                <tr><td>Gaji Pokok</td><td class="jumlah">{{ $rp($slip->gaji_pokok) }}</td></tr>
                @if ((float) $slip->upah_lembur > 0)
                    <tr>
                        <td>Upah Lembur <span class="ket">({{ $jamLembur }}j {{ $menitLembur }}m)</span></td>
                        <td class="jumlah">{{ $rp($slip->upah_lembur) }}</td>
                    </tr>
                @endif
                @if ((float) $slip->uang_makan > 0)
                    <tr><td>Uang Makan</td><td class="jumlah">{{ $rp($slip->uang_makan) }}</td></tr>
                @endif
                @if ((float) $slip->tunjangan_lain > 0)
                    <tr>
                        <td>Tunjangan Lain @if ($slip->keterangan_tunjangan)<span class="ket">({{ $slip->keterangan_tunjangan }})</span>@endif</td>
                        <td class="jumlah">{{ $rp($slip->tunjangan_lain) }}</td>
                    </tr>
                @endif
                <tr class="subtotal"><td>Total Pendapatan</td><td class="jumlah">{{ $rp($slip->total_bruto) }}</td></tr>
            </tbody>
        </table>

        <table class="rincian">
            <thead>
                <tr><th>POTONGAN</th><th class="jumlah">JUMLAH</th></tr>
            </thead>
            <tbody>
                @if ((float) $slip->potongan_absen > 0)
                    <tr>
                        <td>Potongan Absen <span class="ket">({{ $slip->jumlah_alpha }} hari alpha)</span></td>
                        <td class="jumlah">{{ $rp($slip->potongan_absen) }}</td>
                    </tr>
                @endif
                @if ((float) $slip->potongan_bpjs_kesehatan > 0)
                    <tr><td>BPJS Kesehatan ({{ $persen($slip->persen_bpjs_kesehatan) }}%)</td><td class="jumlah">{{ $rp($slip->potongan_bpjs_kesehatan) }}</td></tr>
                @endif
                @if ((float) $slip->potongan_bpjs_tk > 0)
                    <tr><td>BPJS Ketenagakerjaan (JHT {{ $persen($slip->persen_bpjs_jht) }}% + JP {{ $persen($slip->persen_bpjs_jp) }}%)</td><td class="jumlah">{{ $rp($slip->potongan_bpjs_tk) }}</td></tr>
                @endif
                @if ((float) $slip->pph21 > 0)
                    <tr><td>PPh 21</td><td class="jumlah">{{ $rp($slip->pph21) }}</td></tr>
                @endif
                @if ((float) $slip->uang_makan_mingguan > 0)
                    <tr><td>Uang Makan Mingguan</td><td class="jumlah">{{ $rp($slip->uang_makan_mingguan) }}</td></tr>
                @endif
                @if ((float) $slip->kasbon > 0)
                    <tr><td>Kasbon</td><td class="jumlah">{{ $rp($slip->kasbon) }}</td></tr>
                @endif
                @if ((float) $slip->uang_jalan_terpakai > 0)
                    <tr><td>Uang Jalan Terpakai</td><td class="jumlah">{{ $rp($slip->uang_jalan_terpakai) }}</td></tr>
                @endif
                @if ((float) $slip->tilangan > 0)
                    <tr><td>Tilangan</td><td class="jumlah">{{ $rp($slip->tilangan) }}</td></tr>
                @endif
                @if ((float) $slip->potongan_lain > 0)
                    <tr>
                        <td>Potongan Lain @if ($slip->keterangan_potongan)<span class="ket">({{ $slip->keterangan_potongan }})</span>@endif</td>
                        <td class="jumlah">{{ $rp($slip->potongan_lain) }}</td>
                    </tr>
                @endif
                @if ((float) $slip->total_potongan <= 0)
                    <tr><td colspan="2" class="ket">Tidak ada potongan</td></tr>
                @endif
                <tr class="subtotal"><td>Total Potongan</td><td class="jumlah">{{ $rp($slip->total_potongan) }}</td></tr>
            </tbody>
        </table>

        <div class="bersih">
            <table>
                <tr>
                    <td>GAJI BERSIH (Take Home Pay)</td>
                    <td class="nominal">{{ $rp($slip->gaji_bersih) }}</td>
                </tr>
            </table>
        </div>

        @if ($slip->catatan)
            <p class="catatan"><strong>Catatan:</strong> {{ $slip->catatan }}</p>
        @endif

        <p class="cetak">
            Dicetak {{ now()->format('d/m/Y H:i') }} — dokumen ini dihasilkan otomatis oleh sistem dan sah tanpa tanda tangan.
            Status periode: {{ strtoupper($periode->status ?? '-') }}.
        </p>
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
