<?php
/**
 * helpers.php, fungsi pembantu yang dipakai semua halaman.
 *
 * KONVENSI PENAMAAN (berlaku di seluruh proyek)
 * ---------------------------------------------
 * - Kata kerja / kata struktur memakai bahasa Inggris:
 *       get, add, update, delete, find, count, is, has, validate, format, generate, render
 * - Istilah domain memakai bahasa Indonesia:
 *       Barang, Transaksi, Peminjaman, Pengembalian, Stok, Petugas, Kuantitas, Tanggal
 *   Contoh: getAllBarang(), deleteBarang(), addUser(), formatTanggal(), generateIdUnik()
 * - Class dan method memakai camelCase / PascalCase; variabel memakai snake_case
 *   dengan istilah domain Indonesia ($daftar_barang, $nama_penerima, $id_transaksi).
 * - Konstanta memakai HURUF_BESAR (JenisTransaksi::PEMINJAMAN).
 *
 * Kompatibel dengan PHP 7.3 ke atas (syarat PHPUnit 9; kode aplikasinya sendiri jalan di 7.1+).
 */

// Autoload sederhana: class "BarangManager" dimuat dari classes/BarangManager.php
spl_autoload_register(function ($nama_class) {
    $file = __DIR__ . '/classes/' . $nama_class . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

const BULAN_SINGKAT = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
const BULAN_PANJANG = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

/* ------------------------------------------------------------------ OUTPUT */

/** Escape output HTML (mencegah XSS). */
function e($teks): string
{
    return htmlspecialchars((string)$teks, ENT_QUOTES, 'UTF-8');
}

/**
 * Format tanggal ke bahasa Indonesia, tidak bergantung locale server.
 *   formatTanggal('2026-10-10 08:00:00')       -> "10 Okt 2026"
 *   formatTanggal('2026-10-03', true)          -> "3 Oktober 2026"
 *
 * @param string|int|null $tanggal string tanggal (YYYY-MM-DD ...) atau timestamp
 */
function formatTanggal($tanggal, bool $panjang = false): string
{
    $stempel = is_numeric($tanggal) ? (int)$tanggal : strtotime((string)$tanggal);
    if ($stempel === false || $stempel === null || $stempel <= 0) {
        return '-';
    }
    $bulan = (int)date('n', $stempel);
    $nama_bulan = $panjang ? BULAN_PANJANG[$bulan] : BULAN_SINGKAT[$bulan];
    return date('j', $stempel) . ' ' . $nama_bulan . ' ' . date('Y', $stempel);
}

/**
 * Kelas CSS badge stok: merah (< 5), kuning (< 10), hijau (selebihnya).
 */
function kelasStok(int $stok): string
{
    if ($stok < 5) {
        return 'badge-red';
    }
    if ($stok < 10) {
        return 'badge-yellow';
    }
    return 'badge-green';
}

/** HTML "empty state" (tampilan saat data kosong). Semua argumen teks di-escape. */
function renderEmptyState(string $ikon, string $judul, string $teks = '', string $aksi_html = ''): string
{
    return "<div class='empty-state'>"
        . "<i class='bx " . e($ikon) . "'></i>"
        . "<h4>" . e($judul) . "</h4>"
        . ($teks !== '' ? "<p>" . e($teks) . "</p>" : '')
        . $aksi_html
        . "</div>";
}

/* -------------------------------------------------------------- ID ACAK */

/**
 * Buat kode acak: awalan + angka acak dengan jumlah digit tertentu.
 *   generateKodeAcak('TRX', 5) -> "TRX48213"
 * Memakai random_int() (acak yang tidak bisa ditebak), bukan rand().
 */
function generateKodeAcak(string $awalan, int $digit): string
{
    if ($digit < 1 || $digit > 18) {
        throw new InvalidArgumentException('Jumlah digit harus 1 sampai 18.');
    }
    $min = (int)pow(10, $digit - 1);
    $maks = (int)pow(10, $digit) - 1;
    return $awalan . random_int($min, $maks);
}

/**
 * Buat ID acak yang DIJAMIN belum dipakai: ulangi sampai ketemu yang unik.
 *
 * @param callable $sudah_ada fungsi(string $id): bool — true jika ID sudah ada di database
 * @throws RuntimeException jika setelah $maks_coba percobaan masih bentrok
 */
function generateIdUnik(callable $sudah_ada, string $awalan, int $digit, int $maks_coba = 25): string
{
    for ($i = 0; $i < $maks_coba; $i++) {
        $id = generateKodeAcak($awalan, $digit);
        if (!$sudah_ada($id)) {
            return $id;
        }
    }
    throw new RuntimeException("Gagal membuat ID unik dengan awalan '$awalan' (ruang ID hampir penuh).");
}

/* ------------------------------------------------------ SESI, ROLE, CSRF */

function peranAktif(): string
{
    return $_SESSION['role'] ?? '';
}

function isAdmin(): bool
{
    return peranAktif() === 'admin';
}

/** Wajib login; jika belum, arahkan ke halaman login. */
function requireLogin(string $base_path = ''): void
{
    if (!isset($_SESSION['username'])) {
        header('Location: ' . $base_path . 'login.php');
        exit();
    }
}

/** Wajib salah satu role tertentu. */
function requireRole(array $peran_boleh, string $base_path = ''): void
{
    requireLogin($base_path);
    if (!in_array(peranAktif(), $peran_boleh, true)) {
        setFlash('err', 'Anda tidak memiliki akses ke halaman itu.');
        header('Location: ' . $base_path . 'index.php');
        exit();
    }
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrfOk(): bool
{
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']);
}

/** Input hidden CSRF siap pakai di dalam <form>. */
function csrfField(): string
{
    return "<input type='hidden' name='csrf' value='" . e(csrfToken()) . "'>";
}

/* ------------------------------------------------------ FOTO PROFIL */

/**
 * Kapsul profil di header: foto/avatar + nama + tombol settings (gerigi),
 * beserta dialog untuk mengubah / menghapus foto.
 *
 * @param string $base        awalan path ('' dari root, '../' dari folder CRUD/)
 * @param string $label_peran teks kecil setelah nama, mis. 'Wakasek' (boleh kosong)
 */
function renderProfilUser(mysqli $koneksi, string $base = '', string $label_peran = ''): string
{
    $username = (string)($_SESSION['username'] ?? '');
    $nama     = (string)($_SESSION['nama_lengkap'] ?? $username);

    $foto_url = null;
    try {
        $foto_url = UserManager::urlFoto((new UserManager($koneksi))->getFoto($username), $base);
    } catch (Throwable $ex) {
        // Mis. kolom Foto belum dibuat: halaman tetap tampil dengan avatar default.
        error_log('[renderProfilUser] ' . $ex->getMessage());
    }

    $avatar = $foto_url !== null
        ? "<img src='" . e($foto_url) . "' alt='Foto profil'>"
        : "<i class='bx bx-user'></i>";
    $peran  = $label_peran !== '' ? " <small style='color: var(--info);'>(" . e($label_peran) . ")</small>" : '';

    // Isi form yang sama untuk simpan & hapus: token CSRF + halaman asal untuk kembali
    $aksi  = e($base . 'profil_foto.php');
    $dasar = csrfField() . "<input type='hidden' name='kembali' value='" . e($_SERVER['REQUEST_URI'] ?? '') . "'>";

    $tombol_hapus = $foto_url === null ? '' :
        "<form method='post' action='$aksi' data-loading class='form-hapus-foto'>$dasar"
        . "<input type='hidden' name='aksi' value='hapus'>"
        . "<button type='submit' class='btn-hapus-foto'><i class='bx bx-trash'></i> Hapus foto</button></form>";

    return "<div class='user-profile'><div class='avatar'>$avatar</div><span>" . e($nama) . "$peran</span>"
        . "<button type='button' class='btn-profil-set' id='btnFotoSetting' title='Ubah foto profil'><i class='bx bx-cog'></i></button></div>"
        . "<dialog class='dlg-foto' id='dlgFoto'>"
        .   "<h3>Foto profil</h3>"
        .   "<div class='foto-preview' id='fotoPreview'>$avatar</div>"
        .   "<form method='post' action='$aksi' enctype='multipart/form-data' data-loading id='formFoto'>$dasar"
        .     "<input type='hidden' name='aksi' value='simpan'>"
        .     "<input type='file' name='foto' id='inputFoto' class='foto-input' accept='image/jpeg,image/png,image/webp'>"
        .     "<label for='inputFoto' class='btn btn-ghost'><i class='bx bx-image-add'></i> Pilih foto</label>"
        .     "<p class='form-hint'>JPG, PNG, atau WebP, maksimal 2 MB. Dipangkas otomatis menjadi persegi.</p>"
        .     "<div class='dlg-aksi'>"
        .       "<button type='button' class='btn btn-ghost' id='btnTutupFoto'>Batal</button>"
        .       "<button type='submit' class='btn' id='btnSimpanFoto' disabled>Simpan</button>"
        .     "</div>"
        .   "</form>"
        .   $tombol_hapus
        . "</dialog>";
}

/* ----------------------------------------------- NOTIFIKASI (FLASH) */

/**
 * Simpan pesan untuk ditampilkan di halaman berikutnya (menggantikan alert()).
 * @param string $tipe 'ok' | 'err' | 'info'
 */
function setFlash(string $tipe, string $pesan): void
{
    $_SESSION['flash'][] = ['tipe' => $tipe, 'pesan' => $pesan];
}

/** Ambil semua pesan lalu kosongkan. */
function ambilFlash(): array
{
    $daftar = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $daftar;
}
