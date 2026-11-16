<?php
/**
 * FotoManager — menyimpan foto kondisi barang (peminjaman, pengembalian, barang masuk,
 * barang keluar). Isi file diperiksa (bukan sekadar nama/ekstensi), diputar sesuai
 * orientasi kamera HP, dikecilkan, lalu disimpan sebagai JPEG bernama acak.
 */
class FotoManager
{
    public const MAKS_BYTE = 8388608;          // 8 MB (foto kamera HP biasanya 2-6 MB)
    public const SISI_MAKS = 1280;             // sisi terpanjang hasil akhir (piksel)
    public const FOLDER    = 'uploads/barang/'; // relatif terhadap folder proyek

    /** Apakah pengguna memilih file? (input file kosong = UPLOAD_ERR_NO_FILE) */
    public static function ada(array $berkas): bool
    {
        return ($berkas['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    }

    /**
     * Ubah bentuk $_FILES['foto'] (input name="foto[0]", "foto[1]", ...) yang "terbalik"
     * menjadi daftar per barang: [indeks => ['name','type','tmp_name','error','size']].
     */
    public static function rapikan(array $foto): array
    {
        $hasil = [];
        if (!isset($foto['error']) || !is_array($foto['error'])) {
            return $hasil;
        }
        foreach ($foto['error'] as $idx => $kode) {
            if (is_array($kode)) {
                continue;
            }
            $hasil[$idx] = [
                'name'     => $foto['name'][$idx] ?? '',
                'type'     => $foto['type'][$idx] ?? '',
                'tmp_name' => $foto['tmp_name'][$idx] ?? '',
                'error'    => $kode,
                'size'     => $foto['size'][$idx] ?? 0,
            ];
        }
        return $hasil;
    }

    /**
     * Proses dan simpan satu foto.
     * @param array $berkas isi $_FILES['foto']
     * @return array{0: ?string, 1: ?string} [nama_file, pesan_error] — salah satunya null
     */
    public static function simpan(array $berkas): array
    {
        // 1. Cek hasil upload
        $kode = $berkas['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($kode === UPLOAD_ERR_NO_FILE) {
            return [null, 'Pilih foto terlebih dahulu.'];
        }
        if ($kode !== UPLOAD_ERR_OK || $berkas['size'] > self::MAKS_BYTE) {
            return [null, 'Foto gagal diunggah atau ukurannya lebih dari 8 MB.'];
        }
        if (!extension_loaded('gd')) {
            return [null, 'Ekstensi GD belum aktif di PHP.'];
        }

        // 2. Pastikan ISI file benar-benar gambar JPG/PNG/WebP
        $info = @getimagesize($berkas['tmp_name']);
        if ($info === false || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return [null, 'Format foto harus JPG, PNG, atau WebP.'];
        }
        if ($info[0] * $info[1] > 40000000) {   // cegah gambar raksasa menghabiskan memori
            return [null, 'Dimensi foto terlalu besar.'];
        }

        // 3. Baca gambar sesuai jenisnya
        if ($info['mime'] === 'image/jpeg') {
            $asal = @imagecreatefromjpeg($berkas['tmp_name']);
        } elseif ($info['mime'] === 'image/png') {
            $asal = @imagecreatefrompng($berkas['tmp_name']);
        } else {
            $asal = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($berkas['tmp_name']) : false;
        }
        if (!$asal) {
            return [null, 'Foto tidak bisa dibaca. Coba gunakan file lain.'];
        }

        // 4. Foto kamera HP sering "miring" (orientasi disimpan di EXIF) — putar sesuai EXIF
        if ($info['mime'] === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($berkas['tmp_name']);
            $arah = (int)($exif['Orientation'] ?? 1);
            $derajat = [3 => 180, 6 => -90, 8 => 90][$arah] ?? 0;
            if ($derajat !== 0) {
                $putar = @imagerotate($asal, $derajat, 0);
                if ($putar) {
                    imagedestroy($asal);
                    $asal = $putar;
                }
            }
        }

        // 5. Kecilkan (rasio asli dipertahankan, tidak dipangkas). Latar putih untuk PNG transparan.
        $lebar  = imagesx($asal);
        $tinggi = imagesy($asal);
        $skala  = min(1, self::SISI_MAKS / max($lebar, $tinggi));
        $bw = max(1, (int)round($lebar * $skala));
        $bh = max(1, (int)round($tinggi * $skala));
        $hasil = imagecreatetruecolor($bw, $bh);
        imagefill($hasil, 0, 0, imagecolorallocate($hasil, 255, 255, 255));
        imagecopyresampled($hasil, $asal, 0, 0, 0, 0, $bw, $bh, $lebar, $tinggi);
        imagedestroy($asal);

        // 6. Simpan sebagai JPEG dengan nama acak
        $folder = dirname(__DIR__) . '/' . self::FOLDER;
        if (!is_dir($folder) && !@mkdir($folder, 0755, true)) {
            imagedestroy($hasil);
            return [null, 'Folder penyimpanan foto tidak bisa dibuat.'];
        }
        $nama_baru = 'b_' . bin2hex(random_bytes(8)) . '.jpg';
        $ok = imagejpeg($hasil, $folder . $nama_baru, 85);
        imagedestroy($hasil);
        if (!$ok) {
            return [null, 'Foto gagal disimpan di server.'];
        }
        return [$nama_baru, null];
    }

    /** Hapus file foto (dipakai jika penyimpanan transaksi dibatalkan). */
    public static function hapus(?string $nama_file): void
    {
        if ($nama_file !== null && $nama_file !== '') {
            @unlink(dirname(__DIR__) . '/' . self::FOLDER . basename($nama_file));
        }
    }

    /** Alamat gambar untuk <img>; null jika tidak ada foto atau file-nya hilang. */
    public static function url(?string $nama_file, string $base = ''): ?string
    {
        if ($nama_file === null || $nama_file === '') {
            return null;
        }
        $nama_file = basename($nama_file);   // cegah path seperti "../../x"
        if (!is_file(dirname(__DIR__) . '/' . self::FOLDER . $nama_file)) {
            return null;
        }
        return $base . self::FOLDER . $nama_file;
    }
}