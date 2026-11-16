<?php
/*
 * profil_foto.php — penerima form foto profil (simpan / hapus).
 * Tidak menampilkan halaman: setelah selesai langsung kembali ke halaman asal
 * dengan notifikasi. Form-nya dibuat oleh renderProfilUser() di helpers.php;
 * pemrosesan fotonya ada di UserManager::simpanFoto() dan hapusFoto().
 */
session_start();
include 'koneksi.php';
require_once __DIR__ . '/helpers.php';

requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit();
}

// Halaman asal: hanya path lokal yang boleh (bukan "//situs-lain" atau alamat penuh)
$kembali = (string)($_POST['kembali'] ?? '');
if ($kembali === '' || $kembali[0] !== '/' || strpos($kembali, '//') === 0 || strpos($kembali, '\\') !== false) {
    $kembali = 'index.php';
}

$user = new UserManager($koneksi);
$aksi = (string)($_POST['aksi'] ?? '');

if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    // File melebihi post_max_size: PHP membuang seluruh $_POST
    $error = 'Ukuran foto terlalu besar (maksimal 2 MB).';
} elseif (!csrfOk()) {
    $error = 'Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.';
} elseif ($aksi === 'simpan') {
    // Username dari sesi (bukan dari form): user hanya bisa mengubah fotonya sendiri
    $error = $user->simpanFoto($_SESSION['username'], $_FILES['foto'] ?? []);
} elseif ($aksi === 'hapus') {
    $error = $user->hapusFoto($_SESSION['username']);
} else {
    $error = 'Aksi tidak dikenal.';
}

if ($error !== null) {
    setFlash('err', $error);
} else {
    setFlash('ok', $aksi === 'hapus' ? 'Foto profil dihapus.' : 'Foto profil berhasil diperbarui.');
}
header('Location: ' . $kembali);
exit();
