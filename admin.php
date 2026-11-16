<?php
/*
 * admin.php — dipertahankan hanya agar URL lama tidak 404.
 *
 * Halaman ini dulu berisi salinan fitur yang sama dengan CRUD/read.php (hapus barang)
 * dan CRUD/tambah_user.php (tambah user), dan tidak pernah dituju dari login.
 * Sekarang kedua fitur itu hidup di satu tempat saja:
 *     admin.php?page=barang  ->  CRUD/read.php
 *     admin.php?page=user    ->  CRUD/tambah_user.php
 * File ini boleh dihapus jika tidak ada yang menyimpan URL-nya.
 */
    session_start();
    include 'koneksi.php';
    require_once __DIR__ . '/layout.php';

    requireRole(['admin']);

    $tujuan = (($_GET['page'] ?? 'barang') === 'user') ? 'CRUD/tambah_user.php' : 'CRUD/read.php';
    header('Location: ' . $tujuan);
    exit();
