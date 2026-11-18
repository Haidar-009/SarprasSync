# SarprasSync

**School warehouse inventory and asset-tracking system** built with PHP and MySQL.

SarprasSync (_Sarpras_ = _Sarana dan Prasarana_, school facilities and infrastructure) helps a
school warehouse keep an accurate, auditable record of every item: what came in, what went
out, who borrowed it, and whether it came back in good condition.

## Features

### Stock and transaction tracking

- **Four transaction types:** Incoming Goods (_Barang Masuk_), Outgoing Goods (_Barang Keluar_),
  Borrowing (_Peminjaman_), and Returns (_Pengembalian_).
- **Automatic stock rules:** each transaction type adds to or subtracts from stock according to
  its own rule, and a transaction that would push stock below zero is rejected.
- **Atomic recording:** a transaction and its details are saved together or not at all
  (InnoDB transactions with rollback), so stock never ends up half-updated.
- **Smart returns:** when a borrower's name is entered, the return form automatically fills in
  the item and quantity that person borrowed.
- **Loss and damage handling:** items that are damaged, retired, or lost are recorded as
  outgoing goods with a stated reason (_Rusak_ / _Afkir_ / _Hilang_).

### Photo evidence

- **Item condition photos:** a photo is required when borrowing (initial condition) and when
  returning (condition after return). The initial photo is shown locked on the return form so it
  cannot be altered. Incoming goods are photographed on arrival; photos for damaged or lost
  items are optional.
- **Profile photos:** every user can upload, change, or remove their own photo (JPG, PNG, or
  WebP). Photos are cropped automatically and old files are deleted on replacement.

### Master data

- **Item master data:** browse all items with their ID, name, type, funding source, and current
  stock, with color-coded stock levels so low stock is easy to spot.
- **Item updates:** staff can add stock (for example after a purchase) but cannot overwrite the
  recorded opening stock.

### Dashboard and reporting

- **Summary dashboard:** key figures at a glance, with date filters.
- **Trend chart:** a line chart of items coming in versus items currently on loan, switchable
  between 3, 6, and 12 months.
- **Report export:** export transaction reports directly from the dashboard.

### Roles and access control

| Role                       | What they can do                                                  |
| -------------------------- | ----------------------------------------------------------------- |
| **Officer** (_Petugas_)    | Record transactions, view and update item data, upload photos     |
| **Admin**                  | Everything an officer can do, plus delete items and add new users |
| **Supervisor** (_Wakasek_) | Read-only dashboard for oversight (`wakasek.php`)                 |

Login redirects each role to its own landing page, and pages are protected by role checks.

### Security and reliability

- CSRF tokens on forms and output escaping on rendered data.
- Random, collision-checked IDs (see [Random IDs](#random-ids)).
- Flash messages, toast notifications, and a logout confirmation pop-up.
- Responsive layout with a collapsible sidebar that expands on hover.

## Tech stack

- **Backend:** PHP (7.3 or newer), object-oriented classes with autoloading
- **Database:** MySQL / MariaDB with InnoDB tables (`pergudangan`: `user`, `barang`,
  `pendataan_barang`, `detail_pendataan`)
- **Frontend:** plain HTML, one shared stylesheet, and a small JavaScript file
- **Testing:** PHPUnit 9.6

## Project structure

```
/                      (root = http://ujikom.test/)
├── index.php          Dashboard (summary, filters, trend chart, report export)
├── login.php          Sign-in page
├── logout.php         Sign-out with confirmation pop-up
├── wakasek.php        Supervisor dashboard (read-only)
├── admin.php          Redirects to CRUD/read.php or CRUD/tambah_user.php (legacy URL)
├── profil_foto.php    Profile photo upload, change, and removal
├── layout.php         Shared page frame (head, sidebar, notifications)
├── helpers.php        Helper functions (dates, escaping, CSRF, random IDs, flash messages)
├── koneksi.php        Database connection  <- your own file, NOT replaced on update
├── assets/
│   ├── style.css      The only stylesheet
│   └── app.js         Shared behavior (page fade, spinner, toast)
├── classes/
│   ├── JenisTransaksi.php    Transaction types and stock rules
│   ├── BarangManager.php     Item table access
│   ├── UserManager.php       User table access
│   ├── TransaksiManager.php  Transaction recording (atomic, stock validation)
│   └── FotoManager.php       Photo upload, validation, and cleanup
├── CRUD/
│   ├── pendataan.php     Record a transaction
│   ├── read.php          Item master data
│   ├── update.php        Edit item data
│   └── tambah_user.php   Add a user (admin only)
├── uploads/           Condition photos and profile photos
├── database/
│   └── migrasi_pengembalian.sql
└── tests/             PHPUnit tests
```

## Installation

1. **Back up** the `pergudangan` database and your existing project folder.
2. Copy all files from this package into the project, overwriting existing files.
   **Do not overwrite `koneksi.php`.**
3. Delete the old files whose names differ only by letter case: `CRUD/Read.php` and
   `CRUD/Update.php` (they are now `CRUD/read.php` and `CRUD/update.php`).
4. Run `database/migrasi_pengembalian.sql` in phpMyAdmin (SQL tab). The script changes the old
   `Pemasukan` value to `Pengembalian` and checks that the tables use InnoDB, which is required
   for transaction rollback.
5. Make sure the `uploads/` folder is writable by the web server.
6. Open `http://ujikom.test/login.php`.

The `img/` folder and the old login background image are no longer used.

## Naming conventions

- **Verbs and structure in English:** `get`, `add`, `update`, `delete`, `find`, `count`, `is`,
  `has`, `validate`, `format`, `generate`, `render`.
- **Domain terms in Indonesian:** `Barang` (item), `Transaksi` (transaction), `Peminjaman`
  (borrowing), `Pengembalian` (return), `Stok` (stock), `Petugas` (officer), `Kuantitas`
  (quantity), `Tanggal` (date). Examples: `getAllBarang()`, `addTransaksi()`, `formatTanggal()`.
- Classes use PascalCase, methods use camelCase, variables use `snake_case` with Indonesian
  domain terms (`$daftar_barang`, `$id_transaksi`), and constants use UPPER_CASE.

## Random IDs

Transaction IDs (`TRX` + 5 digits), detail IDs (`DET` + 5 digits), item IDs (`BRG` + 4 digits),
and officer IDs (9 digits) are generated randomly with `random_int()`. `generateIdUnik()` checks
the database and retries on a collision, so duplicate primary keys no longer cause silent
failures.

## Running the automated tests

```
composer install
vendor/bin/phpunit
```

These tests cover pure logic (validation, stock rules, date formatting, unique IDs) and do not
need a database. Manual test cases for login, borrowing, and returning are in
`Kasus_Uji_Inventaris.docx`.

## Roadmap

- Hash passwords with `password_hash()` / `password_verify()`, and migrate existing passwords.
  Passwords are currently stored and compared as plain text (`login.php`, `UserManager`).
- Replace `real_escape_string` in `login.php` with prepared statements.
- Standardize database column names, which currently mix upper and lower case
  (`Id_Transaksi`, `id_barang`).
