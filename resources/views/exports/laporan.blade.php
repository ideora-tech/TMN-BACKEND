<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Laporan Proyek</title>
    <style>
        body { font-family: sans-serif; font-size: 11px; }
        h2 { margin-bottom: 4px; }
        p { margin: 2px 0 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 5px 7px; text-align: left; }
        th { background: #f4f4f4; font-weight: bold; }
        tr:nth-child(even) { background: #fafafa; }
        .num { text-align: right; }
    </style>
</head>
<body>
    <h2>Laporan Proyek</h2>
    <p>Dicetak: {{ now()->format('d M Y H:i') }}</p>
    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>Kode Proyek</th>
                <th>Nama Proyek</th>
                <th>Klien</th>
                <th class="num">Trip Selesai</th>
                <th class="num">Total Jarak (km)</th>
                <th class="num">Total Biaya Ops</th>
                <th>Diserahkan Oleh</th>
                <th>Diserahkan Pada</th>
                <th>Ringkasan</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $item)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ $item->kode_proyek ?? '-' }}</td>
                <td>{{ $item->nama_proyek ?? '-' }}</td>
                <td>{{ $item->nama_klien ?? '-' }}</td>
                <td class="num">{{ number_format((int) $item->total_trip, 0, ',', '.') }}</td>
                <td class="num">{{ number_format((float) $item->total_jarak_km, 1, ',', '.') }}</td>
                <td class="num">Rp {{ number_format((float) $item->total_biaya, 0, ',', '.') }}</td>
                <td>{{ $item->diserahkan_oleh ?? '-' }}</td>
                <td>{{ $item->diserahkan_pada ? \Carbon\Carbon::parse($item->diserahkan_pada)->format('d/m/Y H:i') : '-' }}</td>
                <td>{{ $item->ringkasan ?? '-' }}</td>
            </tr>
            @empty
            <tr><td colspan="10" style="text-align:center">Tidak ada data</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
