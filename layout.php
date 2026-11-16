<?php
/**
 * layout.php — kerangka halaman yang dipakai bersama (head, sidebar, notifikasi, penutup).
 * Menggantikan <head>, sidebar, dan script yang dulu disalin ke setiap halaman.
 *
 * Cara pakai di sebuah halaman:
 *
 *     session_start();
 *     include 'koneksi.php';
 *     require_once __DIR__ . '/layout.php';      // di folder CRUD/: '/../layout.php'
 *     ...logika halaman...
 *     layoutStart(['judul' => 'Dashboard', 'aktif' => 'beranda', 'base' => '']);
 *     ...isi halaman...
 *     layoutEnd(['base' => '']);
 *
 * 'base' adalah awalan path ke folder root aplikasi: '' untuk halaman di root,
 * '../' untuk halaman di folder CRUD/.
 */

require_once __DIR__ . '/helpers.php';

/**
 * Daftar menu sidebar standar (admin & petugas). Href relatif terhadap folder root.
 * @return array<int, array<string, string>>
 */
function getMenuUtama(string $peran): array
{
    $menu = [
        ['key' => 'beranda',   'href' => 'index.php',            'ikon' => 'bxs-grid-alt', 'label' => 'Beranda',      'judul' => 'Beranda'],
        ['key' => 'transaksi', 'href' => 'CRUD/pendataan.php',   'ikon' => 'bx-transfer',  'label' => 'Transaksi',    'judul' => 'Catat transaksi barang'],
        ['key' => 'barang',    'href' => 'CRUD/read.php',        'ikon' => 'bx-list-ul',   'label' => 'Data Barang',  'judul' => 'Daftar data master barang'],
        ['key' => 'ubah',      'href' => 'CRUD/update.php',      'ikon' => 'bx-edit',      'label' => 'Ubah Barang',  'judul' => 'Ubah data barang'],
    ];
    if ($peran === 'admin') {
        $menu[] = ['key' => 'user', 'href' => 'CRUD/tambah_user.php', 'ikon' => 'bx-user-plus', 'label' => 'Tambah User', 'judul' => 'Tambah user baru'];
    }
    return $menu;
}

function renderMenuItem(array $item, string $aktif, string $base): string
{
    $kelas = 'nav-item' . (($item['key'] ?? '') === $aktif ? ' active' : '');
    $isi = "<i class='bx " . e($item['ikon']) . "'></i><span>" . e($item['label']) . "</span>";
    $judul = e($item['judul'] ?? $item['label']);

    // Tombol tab (dipakai halaman wakasek) atau tautan biasa
    if (isset($item['tab'])) {
        return "<button type='button' class='" . $kelas . "' data-tab='" . e($item['tab']) . "' title='" . $judul . "'>" . $isi . "</button>";
    }
    $href = (strpos($item['href'], 'http') === 0) ? $item['href'] : $base . $item['href'];
    $current = ($item['key'] ?? '') === $aktif ? " aria-current='page'" : '';
    return "<a href='" . e($href) . "' class='" . $kelas . "' title='" . $judul . "'" . $current . ">" . $isi . "</a>";
}

/** Bagian pembuka: <head>, notifikasi, sidebar, dan awal <main>. */
function layoutStart(array $opsi): void
{
    $judul = $opsi['judul'] ?? 'Inventaris';
    $aktif = $opsi['aktif'] ?? '';
    $base = $opsi['base'] ?? '';
    $kelas_body = $opsi['body_class'] ?? '';
    $menu = $opsi['menu'] ?? getMenuUtama(peranAktif());
    $ikon_logo = $opsi['ikon_logo'] ?? 'bx-layer';
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($judul); ?> Inventaris Sekolah</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <link href="<?php echo e($base); ?>assets/style.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/style.css'); ?>" rel="stylesheet">
    <script src="<?php echo e($base); ?>assets/app.js?v=<?php echo (int)@filemtime(__DIR__ . '/assets/app.js'); ?>" defer></script>
</head>
<body class="<?php echo e($kelas_body); ?>">
    <div class="load-bar" id="loadBar"></div>
    <?php renderFlash(); ?>

    <div class="app-wrapper">
        <aside class="sidebar" aria-label="Menu utama">
            <div class="logo-box"><i class='bx <?php echo e($ikon_logo); ?>'></i></div>
            <nav class="nav-links">
                <?php foreach ($menu as $item) { echo renderMenuItem($item, $aktif, $base), "\n                "; } ?>
            </nav>
            <a href="<?php echo e($base); ?>logout.php" class="nav-item logout" title="Keluar dari akun" data-logout data-no-fade>
                <i class='bx bx-log-out'></i><span>Keluar</span>
            </a>
        </aside>

        <main class="main-content" id="konten">
<?php
}

/** Bagian penutup: tutup <main>, pop-up logout, dan script halaman. */
function layoutEnd(array $opsi = []): void
{
    $script = $opsi['script'] ?? '';
    ?>
        </main>
    </div>
<?php
    include __DIR__ . '/logout.php';
    if ($script !== '') {
        echo "    <script>\n" . $script . "\n    </script>\n";
    }
    echo "</body>\n</html>\n";
}

/** Tampilkan semua notifikasi flash sebagai toast. */
function renderFlash(): void
{
    $daftar = ambilFlash();
    echo "<div class='toast-area' id='toastArea' role='status' aria-live='polite'>";
    foreach ($daftar as $f) {
        $tipe = in_array($f['tipe'], ['ok', 'err', 'info'], true) ? $f['tipe'] : 'info';
        $ikon = ['ok' => 'bx-check-circle', 'err' => 'bx-error-circle', 'info' => 'bx-info-circle'][$tipe];
        echo "<div class='toast " . $tipe . "'><i class='bx " . $ikon . "'></i><span>" . e($f['pesan']) . "</span></div>";
    }
    echo "</div>\n";
}

/** Judul halaman standar. $kanan_html adalah HTML siap pakai (sudah aman) di sisi kanan. */
function renderHeader(string $judul, string $subjudul = '', string $kanan_html = ''): string
{
    return "<div class='header'><div><h1>" . e($judul) . "</h1>"
        . ($subjudul !== '' ? "<p>" . e($subjudul) . "</p>" : '')
        . "</div>" . $kanan_html . "</div>";
}