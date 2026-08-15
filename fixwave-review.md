# Fix-wave scoped diff
diff --git a/fixwave-before/ArusKasExport.php b/D:/PROJECT-TMN/TMN-TRANSPORT-BACKEND/app/Modules/ArusKas/Exports/ArusKasExport.php
index c34c909..87cb551 100644
--- a/fixwave-before/ArusKasExport.php
+++ b/D:/PROJECT-TMN/TMN-TRANSPORT-BACKEND/app/Modules/ArusKas/Exports/ArusKasExport.php
@@ -18,12 +18,13 @@ class ArusKasExport implements FromCollection, WithHeadings, WithMapping, Should
     use DenganGayaLaporan;
 
     private const LABEL_SUMBER = [
         'faktur'                => 'Invoice',
         'pengajuan_pengeluaran' => 'Pengajuan Pengeluaran',
         'pembayaran_vendor'     => 'Pembayaran Vendor',
+        'pemasukan_manual'      => 'Pemasukan Manual',
         'payroll_periode'       => 'Payroll',
         'pembelian_sparepart'   => 'Pembelian Sparepart',
     ];
 
     public function __construct(
         private readonly Collection $data,
