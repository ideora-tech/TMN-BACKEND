<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Laporan Karyawan</title>
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
    <h2>Laporan Karyawan</h2>
    <p>Dicetak: {{ now()->format('d M Y H:i') }}</p>
    <table>
        <thead>
            <tr>
                <th>NIK</th>
                <th>Nama Karyawan</th>
                <th>Email</th>
                <th>Telepon</th>
                <th>Jenis Kelamin</th>
                <th>Status Kepegawaian</th>
                <th>Tanggal Masuk</th>
                <th>Aktif</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $item)
            <tr>
                <td>{{ $item->nik ?? '-' }}</td>
                <td>{{ $item->nama_karyawan ?? '-' }}</td>
                <td>{{ $item->email ?? '-' }}</td>
                <td>{{ $item->telepon ?? '-' }}</td>
                <td>{{ $item->jenis_kelamin ?? '-' }}</td>
                <td>{{ $item->status_kepegawaian ?? '-' }}</td>
                <td>{{ $item->tanggal_masuk ? \Illuminate\Support\Carbon::parse($item->tanggal_masuk)->format('d/m/Y') : '-' }}</td>
                <td>{{ ((bool) $item->aktif) ? 'Ya' : 'Tidak' }}</td>
            </tr>
            @empty
            <tr><td colspan="8" style="text-align:center">Tidak ada data</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
