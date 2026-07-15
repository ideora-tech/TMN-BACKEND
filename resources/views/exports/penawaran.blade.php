<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Surat Penawaran</title>
    <style>
        body { font-family: sans-serif; font-size: 11px; }
        h2 { margin-bottom: 4px; }
        p { margin: 2px 0 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 5px 7px; text-align: left; }
        th { background: #f4f4f4; font-weight: bold; }
        .amount { text-align: right; }
        .info { margin-bottom: 4px; }
        .label { font-weight: bold; }
    </style>
</head>
<body>
    <h2>SURAT PENAWARAN</h2>
    <p class="info"><span class="label">No. Penawaran:</span> {{ $p->nomor_penawaran }}</p>
    <p class="info"><span class="label">Tanggal:</span> {{ $p->tanggal_penawaran ?? '-' }}</p>
    <p class="info"><span class="label">Kepada:</span> {{ $klien->nama_klien ?? '-' }}</p>

    <table>
        <thead>
            <tr>
                <th>Judul</th>
                <th class="amount">Nilai</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $p->judul }}</td>
                <td class="amount">Rp {{ number_format($p->nilai_penawaran ?? 0, 0, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>

    <p class="info"><span class="label">Berlaku hingga:</span> {{ $p->tanggal_berlaku ?? '-' }}</p>
    <p class="info"><span class="label">Catatan:</span> {{ $p->catatan ?? '-' }}</p>
    <p class="info"><span class="label">Status:</span> {{ $p->status }}</p>
</body>
</html>
