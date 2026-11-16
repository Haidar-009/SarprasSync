<?php
/**
 * JenisTransaksi — daftar jenis transaksi dan aturan stok masing-masing.
 * Satu-satunya tempat nilai teks jenis transaksi didefinisikan (tidak ada lagi
 * string "Pemasukan"/"Peminjaman" yang tersebar di banyak file).
 */
final class JenisTransaksi
{
    public const PEMINJAMAN   = 'Peminjaman';
    public const PENGEMBALIAN = 'Pengembalian';   // sebelumnya bernama "Pemasukan"
    public const BARANG_MASUK = 'Barang Masuk';   // barang baru (beli / hibah)
    public const BARANG_KELUAR = 'Barang Keluar'; // rusak / afkir

    public const SEMUA = [
        self::PEMINJAMAN,
        self::PENGEMBALIAN,
        self::BARANG_MASUK,
        self::BARANG_KELUAR,
    ];

    /** Dihitung sebagai "masuk" pada statistik dashboard. */
    public const KELOMPOK_MASUK = [self::BARANG_MASUK, self::PENGEMBALIAN];

    /** Dihitung sebagai "keluar" pada statistik dashboard. */
    public const KELOMPOK_KELUAR = [self::BARANG_KELUAR, self::PEMINJAMAN];

    public static function isValid(string $jenis): bool
    {
        return in_array($jenis, self::SEMUA, true);
    }

    /** Apakah transaksi ini mengurangi stok barang? */
    public static function isMengurangiStok(string $jenis): bool
    {
        return $jenis === self::PEMINJAMAN || $jenis === self::BARANG_KELUAR;
    }

    /** Apakah transaksi ini menambah stok barang yang sudah ada? */
    public static function isMenambahStok(string $jenis): bool
    {
        return $jenis === self::PENGEMBALIAN;
    }

    /** Apakah transaksi ini butuh nama peminjam? */
    public static function isButuhPeminjam(string $jenis): bool
    {
        return $jenis === self::PEMINJAMAN || $jenis === self::PENGEMBALIAN;
    }

    /** Alasan barang keluar: nilai di database => label di form. */
    public const ALASAN_KELUAR = [
        'Rusak'  => 'Rusak',
        'Afkir'  => 'Afkir (tidak layak pakai)',
        'Hilang' => 'Hilang',
    ];

    public static function isAlasanKeluarValid(string $alasan): bool
    {
        return array_key_exists($alasan, self::ALASAN_KELUAR);
    }

    /** Foto wajib dilampirkan: kondisi awal (pinjam), kondisi setelah kembali, kondisi barang baru. */
    public static function isFotoWajib(string $jenis): bool
    {
        return $jenis === self::PEMINJAMAN
            || $jenis === self::PENGEMBALIAN
            || $jenis === self::BARANG_MASUK;
    }

    /** Pesan error jika foto wajib tidak dilampirkan. */
    public static function pesanFotoWajib(string $jenis): string
    {
        switch ($jenis) {
            case self::PEMINJAMAN:
                return 'Foto kondisi awal barang wajib dilampirkan sebelum peminjaman disimpan.';
            case self::PENGEMBALIAN:
                return 'Foto kondisi barang setelah dikembalikan wajib dilampirkan.';
            default:
                return 'Foto kondisi barang wajib dilampirkan.';
        }
    }

    /** Kelas CSS badge untuk tampilan. */
    public static function kelasBadge(string $jenis): string
    {
        switch ($jenis) {
            case self::BARANG_MASUK:
            case self::PENGEMBALIAN:
                return 'badge-green';
            case self::PEMINJAMAN:
                return 'badge-yellow';
            default:
                return 'badge-red';
        }
    }
}