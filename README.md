# Sistem Inventaris Barang Pergudangan Sekolah

Aplikasi web (PHP + MySQL) untuk mencatat barang masuk, keluar, peminjaman, dan
pengembalian di gudang sekolah.

## Struktur folder

```
/                    (root = http://ujikom.test/)
├── index.php        Beranda (ringkasan, filter, ekspor laporan)
├── login.php        Halaman masuk
├── logout.php       Proses keluar + pop-up konfirmasi
├── wakasek.php      Dashboard pengawas (baca saja)
├── admin.php        Pengalih ke CRUD/read.php atau CRUD/tambah_user.php (URL lama)
├── layout.php       Kerangka halaman bersama (head, sidebar, notifikasi)
├── helpers.php      Fungsi pembantu (tanggal, escape, CSRF, ID acak, flash)
├── koneksi.php      Koneksi database  <- file milikmu, TIDAK ikut diganti
├── assets/
│   ├── style.css    Satu-satunya stylesheet
│   └── app.js       Perilaku bersama (fade halaman, spinner, toast)
├── classes/
│   ├── JenisTransaksi.php    Daftar jenis transaksi + aturan stok
│   ├── BarangManager.php     Database tabel barang
│   ├── UserManager.php       Database tabel user
│   └── TransaksiManager.php  Pencatatan transaksi (atomik, validasi stok)
├── CRUD/
│   ├── pendataan.php  Catat transaksi
│   ├── read.php       Data master barang
│   ├── update.php     Ubah data barang
│   └── tambah_user.php  Tambah user (admin)
├── database/
│   └── migrasi_pengembalian.sql
└── tests/             PHPUnit
```

## Cara memasang

1. **Backup** database `pergudangan` dan folder proyek lama.
2. Salin semua file dari folder ini ke proyek (timpa). **Jangan timpa `koneksi.php`.**
3. Hapus file lama yang namanya berbeda huruf besar: `CRUD/Read.php` dan `CRUD/Update.php`
   (sekarang `CRUD/read.php` dan `CRUD/update.php`).
4. Jalankan `database/migrasi_pengembalian.sql` di phpMyAdmin (tab SQL).
   Skrip ini mengganti nilai lama `Pemasukan` menjadi `Pengembalian` dan memeriksa
   bahwa tabel memakai InnoDB (wajib untuk rollback transaksi).
5. Buka `http://ujikom.test/login.php`.

Folder `img/` dan gambar latar login lama tidak dipakai lagi.

## Konvensi penamaan

- **Kata kerja dan struktur: bahasa Inggris** (`get`, `add`, `update`, `delete`, `find`,
  `count`, `is`, `has`, `validate`, `format`, `generate`, `render`).
- **Istilah domain: bahasa Indonesia** (`Barang`, `Transaksi`, `Peminjaman`, `Pengembalian`,
  `Stok`, `Petugas`, `Kuantitas`, `Tanggal`). Contoh: `getAllBarang()`, `addTransaksi()`,
  `formatTanggal()`.
- Class dan method: PascalCase / camelCase. Variabel: `snake_case` dengan istilah domain
  Indonesia (`$daftar_barang`, `$id_transaksi`). Konstanta: HURUF_BESAR.

## ID acak

ID transaksi (`TRX` + 5 digit), detail (`DET` + 5 digit), barang (`BRG` + 4 digit), dan
petugas (9 digit) dibuat acak dengan `random_int()`. `generateIdUnik()` memeriksa
database dan mengulang jika bentrok, jadi tidak ada lagi kegagalan diam-diam akibat
primary key ganda.

## Menjalankan tes otomatis (PHPUnit)

```
composer install
vendor/bin/phpunit
```

Tes ini menguji logika murni (validasi, aturan stok, format tanggal, ID unik) dan tidak
butuh database. Tes manual untuk login, peminjaman, dan pengembalian ada di
`Kasus_Uji_Inventaris.docx`.

## Belum dikerjakan

- Password masih disimpan dan dibandingkan sebagai teks biasa (`login.php`, `UserManager`).
  Perlu `password_hash()` / `password_verify()` beserta migrasi password lama.
- `login.php` masih memakai `real_escape_string`, belum prepared statement.
- Nama kolom database masih campuran huruf besar dan kecil (`Id_Transaksi`, `id_barang`).
