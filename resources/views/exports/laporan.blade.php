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
                <th>ID Laporan</th>
                <th>ID Proyek</th>
                <th>Ringkasan</th>
                <th class="num">Total Trip</th>
                <th>Diserahkan Pada</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $item)
            <tr>
                <td>{{ $item->id_laporan }}</td>
                <td>{{ $item->id_proyek }}</td>
                <td>{{ $item->ringkasan }}</td>
                <td class="num">{{ $item->total_trip }}</td>
                <td>{{ $item->diserahkan_pada ? $item->diserahkan_pada->format('d/m/Y H:i') : '-' }}</td>
            </tr>
            @empty
            <tr><td colspan="5" style="text-align:center">Tidak ada data</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>