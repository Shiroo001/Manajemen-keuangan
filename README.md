# Expense Tracker (PHP & MySQL)

Aplikasi pencatat keuangan pribadi berbasis web yang berjalan secara lokal menggunakan PHP dan MySQL (XAMPP). Aplikasi ini mendukung pencatatan multi-dompet, transfer antar-dompet, jadwal transaksi rutin otomatis, grafik arus kas, ekspor laporan, serta sinkronisasi langsung ke Google Sheets.

## Fitur Utama

* **Manajemen Multi-Dompet & Transfer Saldo:** Kelola saldo beberapa dompet sekaligus (tunai, rekening bank, e-wallet) dan pindahkan dana antar-dompet tanpa memengaruhi total laporan pemasukan maupun pengeluaran.
* **Pencatatan Pemasukan & Pengeluaran:** Catat transaksi dengan filter kategori otomatis berdasarkan tipe transaksi (`Income` atau `Expense`).
* **Transaksi Rutin Otomatis (Recurring):** Jadwalkan tagihan atau pemasukan bulanan (tanggal 1 hingga 28). Sistem mencatat transaksi secara otomatis saat aplikasi dibuka pada atau setelah tanggal jatuh tempo.
* **Visualisasi Grafik Interaktif:** Pantau tren arus kas harian/bulanan (Bar Chart) dan proporsi pengeluaran per kategori (Doughnut Chart) menggunakan Chart.js.
* **Filter Cepat & Pencarian:** Saring riwayat berdasarkan periode cepat (*Hari Ini*, *7 Hari Terakhir*, *1 Bulan Terakhir*, *Bulan Ini*, *Semua*), bulan spesifik, dompet, kategori, tipe, atau kata kunci catatan.
* **Trash Bin (Soft Delete):** Transaksi, dompet, dan kategori yang dihapus masuk ke Trash terlebih dahulu sehingga dapat dipulihkan (*Restore*) beserta penyesuaian saldonya, atau dihapus permanen.
* **Ekspor & Backup/Restore 1-Klik:** Unduh laporan transaksi terfilter ke format `.csv` (dengan UTF-8 BOM), cadangkan seluruh database ke `.json` atau `.sql`, dan pulihkan database langsung dari file `.json`.
* **Auto-Sync Google Sheets:** Hubungkan aplikasi ke Google Spreadsheet menggunakan Google Apps Script Web App agar setiap transaksi, saldo dompet, dan riwayat transfer tersinkronisasi otomatis.
* **Mode Tampilan Adaptif:** Mendukung tema Terang (*Light*), Gelap (*Dark*), dan mengikuti pengaturan sistem perangkat.

## Struktur File

```text
Keuangan/
├── index.php      # Halaman utama dashboard, kartu ringkasan, grafik, dan tabel transaksi
├── db.php         # Koneksi PDO MySQL, inisialisasi database/tabel, helper keamanan, dan fungsi sync Google Sheets
├── actions.php    # Pemroses eksekusi otomatis jadwal rutin dan seluruh aksi POST (CRUD, transfer, backup, sync)
├── queries.php    # Pengambilan data master, penyusunan filter pencarian, dan agregasi data grafik
├── modals.php     # Komponen antarmuka modal dan template kode Google Apps Script
├── export.php     # Generator unduhan laporan CSV serta backup database JSON dan SQL
└── app.js         # Kontrol tema gelap/terang, filter dropdown kategori, kontrol modal, dan inisialisasi Chart.js
