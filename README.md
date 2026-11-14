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
├── app.js         # Kontrol tema gelap/terang, filter dropdown kategori, kontrol modal, dan inisialisasi Chart.js
└── README.md      # Dokumentasi proyek dan panduan instalasi
```

## Prasyarat & Instalasi

1. **Siapkan Lingkungan Lokal:**
   Pastikan **XAMPP** (PHP 8.0+ dan MySQL/MariaDB) sudah terpasang di komputer Anda.
2. **Salin Folder Proyek:**
   Clone repositori ini atau ekstrak folder proyek ke dalam direktori `htdocs` XAMPP (misalnya `C:\xampp\htdocs\Keuangan`).
3. **Jalankan Layanan XAMPP:**
   Buka **XAMPP Control Panel**, lalu klik **Start** pada modul **Apache** dan **MySQL**.
4. **Buka Aplikasi di Browser:**
   Akses `http://localhost/Keuangan/` melalui browser. Aplikasi akan membuat database `expense_tracker`, 6 tabel utama, serta data awal dompet dan kategori secara otomatis saat pertama kali dibuka.

> **Catatan Konfigurasi Database:** Pengaturan bawaan di `db.php` menggunakan host `127.0.0.1`, user `root`, dan password kosong (`""`). Sesuaikan variabel `$host`, `$user`, dan `$pass` di `db.php` apabila konfigurasi MySQL Anda berbeda.

## Panduan Penggunaan

* **Mencatat Transaksi:** Klik tombol **+ Catat Transaksi** di pojok kanan atas, pilih tipe transaksi, tanggal, dompet, kategori, nominal, dan catatan opsional, lalu simpan.
* **Transfer Antar-Dompet:** Klik **⇄ Transfer Saldo**, pilih dompet asal dan tujuan, masukkan nominal serta tanggal transfer. Saldo kedua dompet berubah tanpa mengubah angka pemasukan/pengeluaran.
* **Mengatur Transaksi Otomatis:** Klik **↻ Otomatis**, isi tipe, tanggal jatuh tempo bulanan (1-28), nominal, dompet, kategori, dan keterangan. Centang opsi eksekusi bulan berjalan jika ingin langsung tercatat saat itu juga.
* **Ekspor & Backup Lokal:** Klik **☁ Backup & Sheets** untuk mengunduh laporan `.csv` sesuai filter aktif, mengunduh backup penuh `.json` atau `.sql`, maupun memulihkan database dari file `.json`.

## Setup Auto-Sync Google Sheets

Aplikasi terhubung ke Google Sheets melalui Google Apps Script Web App tanpa memerlukan API Key atau library pihak ketiga.

1. Buka file Google Spreadsheet tujuan, lalu pilih menu **Extensions (Ekstensi) > Apps Script**.
2. Hapus kode bawaan di editor (`Kode.gs`).
3. Buka aplikasi **Expense Tracker**, klik **☁ Backup & Sheets > Lihat Cara Setup & Kode Google Apps Script**, lalu klik **Salin Kode**.
4. Tempel kode tersebut ke editor Apps Script dan simpan (`Ctrl + S`).
5. Klik **Deploy (Terapkan) > New deployment (Deployment baru)**.
6. Klik ikon roda gigi di sebelah *Select type*, pilih **Web app (Aplikasi web)**, lalu atur:
   * **Execute as (Jalankan sebagai):** `Me`
   * **Who has access (Yang memiliki akses):** `Anyone`
7. Klik **Deploy**, selesaikan otorisasi izin akun Google, lalu salin **Web app URL** yang berawalan `[https://script.google.com/macros/s/](https://script.google.com/macros/s/)` dan berakhiran `/exec`.
8. Tempel URL `/exec` tersebut ke kolom **URL Web App Google Apps Script** di modal **☁ Backup & Sheets**, centang **Auto-sync tiap kali ada transaksi/transfer baru**, lalu klik **Simpan Pengaturan**.

Setelah aktif, skrip otomatis membuat dan memperbarui tiga tab sheet di spreadsheet Anda: **`Transaksi`**, **`Dompet`**, dan **`Transfer`**.

## Keamanan Aplikasi

* **Proteksi CSRF:** Setiap formulir `POST` menyertakan token acak berbasis sesi (`random_bytes`) yang diverifikasi menggunakan `hash_equals()`.
* **Pembatasan Akses Langsung:** File internal (`db.php`, `actions.php`, `queries.php`, `modals.php`) memeriksa konstanta `APP_INIT` dan mengembalikan status `403 Forbidden` jika dibuka langsung lewat URL.
* **Integritas Transaksi Database:** Operasi pengubahan saldo memakai transaksi PDO (`beginTransaction`/`commit`) dan penguncian baris (`SELECT ... FOR UPDATE`).
* **Sanitasi Input & Output:** Query database memakai *prepared statements* (`PDO::ATTR_EMULATE_PREPARES => false`), format tanggal divalidasi ketat, dan output HTML di-escape menggunakan `htmlspecialchars()`.
