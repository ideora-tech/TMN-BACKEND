<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Laporan Armada</title>
    <style>
        body { font-family: sans-serif; font-size: 11px; }
        h2 { margin-bottom: 4px; }
        p { margin: 2px 0 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 5px 7px; text-align: left; }
        th { background: #f4f4f4; font-weight: bold; }
        tr:nth-child(even) { background: #fafafa; }
    </style>
</head>
<body>
    <h2>Laporan Armada</h2>
    <p>Dicetak: {{ now()->format('d M Y H:i') }}</p>
    <table>
        <thead>
            <tr>
                <th>Nopol</th>
                <th>Merk</th>
                <th>Model</th>
                <th>Tahun</th>
                <th>Kepemilikan</th>
                <th>Status</th>
                <th>Aktif</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $item)
            <tr>
                <td>{{ $item->nopol ?? '-' }}</td>
                <td>{{ $item->merk ?? '-' }}</td>
                <td>{{ $item->model ?? '-' }}</td>
                <td>{{ $item->tahun ?? '-' }}</td>
                <td>{{ $item->kepemilikan ?? '-' }}</td>
                <td>{{ $item->status ?? '-' }}</td>
                <td>{{ ((bool) $item->aktif) ? 'Ya' : 'Tidak' }}</td>
            </tr>
            @empty
            <tr><td colspan="7" style="text-align:center">Tidak ada data</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
