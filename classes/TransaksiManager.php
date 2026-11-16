<?php
/** Kesalahan yang pesannya boleh ditampilkan langsung ke pengguna. */
class TransaksiException extends RuntimeException
{
}

/**
 * TransaksiManager — logika pencatatan transaksi barang (Peminjaman, Pengembalian,
 * Barang Masuk, Barang Keluar). Seluruh proses simpan dibungkus transaksi database:
 * jika salah satu langkah gagal, semuanya dibatalkan (rollback).
 */
class TransaksiManager
{
    /** @var mysqli */
    private $db;
    /** @var BarangManager */
    private $barang;

    public function __construct(mysqli $koneksi)
    {
        $this->db = $koneksi;
        $this->barang = new BarangManager($koneksi);
    }

    /**
     * Validasi dasar input form (murni, tanpa database — mudah diuji).
     * @return string|null pesan error, atau null jika valid
     */
    public static function validateInput(
        string $jenis,
        int $kuantitas,
        string $nama_peminjam,
        string $id_barang,
        string $nama_barang_baru,
        string $kategori,
        string $sumber_dana
    ): ?string {
        if (!JenisTransaksi::isValid($jenis)) {
            return 'Jenis transaksi tidak valid.';
        }
        if ($kuantitas < 1) {
            return 'Kuantitas harus berupa angka bulat minimal 1.';
        }
        if (JenisTransaksi::isButuhPeminjam($jenis) && trim($nama_peminjam) === '') {
            return 'Nama peminjam wajib diisi.';
        }
        if ($jenis === JenisTransaksi::BARANG_MASUK) {
            if (trim($nama_barang_baru) === '') {
                return 'Nama barang wajib diisi.';
            }
            if (!BarangManager::isKategoriValid($kategori)) {
                return 'Kategori barang belum dipilih.';
            }
            if (!BarangManager::isSumberDanaValid($sumber_dana)) {
                return 'Sumber dana belum dipilih.';
            }
        } elseif ($id_barang === '') {
            return 'Barang belum dipilih.';
        }
        return null;
    }

    /** Apakah stok cukup untuk dikurangi sebanyak $kuantitas? */
    public static function isStokCukup(int $stok, int $kuantitas): bool
    {
        return $kuantitas >= 1 && $stok >= $kuantitas;
    }

    /**
     * Validasi tambahan fitur foto (murni, tanpa database):
     * alasan wajib untuk Barang Keluar, foto wajib untuk jenis tertentu.
     * @return string|null pesan error, atau null jika valid
     */
    public static function validateTambahan(string $jenis, string $alasan, bool $adaFoto): ?string
    {
        if ($jenis === JenisTransaksi::BARANG_KELUAR && !JenisTransaksi::isAlasanKeluarValid($alasan)) {
            return 'Alasan barang keluar belum dipilih (Rusak / Afkir / Hilang).';
        }
        if (JenisTransaksi::isFotoWajib($jenis) && !$adaFoto) {
            return JenisTransaksi::pesanFotoWajib($jenis);
        }
        return null;
    }

    /** @return array<int, array<string, mixed>> */
    public function getDaftarBarang(): array
    {
        return $this->barang->getAllBarang();
    }

    /**
     * Daftar pinjaman yang belum dikembalikan, dikelompokkan per peminjam.
     * Sisa = total Peminjaman − total Pengembalian per peminjam & barang.
     * @return array<string, array{nama: string, barang: array}>
     */
    public function getPinjamanAktif(): array
    {
        $aktif = [];
        $sql = "SELECT MIN(TRIM(p.Nama_penerima)) AS nama, d.id_barang, b.Nama_barang,
                       SUM(CASE WHEN p.Jenis_transaksi = ? THEN d.Kuantitas ELSE -d.Kuantitas END) AS sisa
                FROM pendataan_barang p
                JOIN detail_pendataan d ON d.Id_Transaksi = p.Id_Transaksi
                JOIN barang b ON b.id_barang = d.id_barang
                WHERE p.Jenis_transaksi IN (?, ?)
                GROUP BY LOWER(TRIM(p.Nama_penerima)), d.id_barang, b.Nama_barang
                HAVING sisa > 0
                ORDER BY nama";
        $pinjam = JenisTransaksi::PEMINJAMAN;
        $kembali = JenisTransaksi::PENGEMBALIAN;
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('sss', $pinjam, $pinjam, $kembali);
        $stmt->execute();
        $hasil = $stmt->get_result();

        while ($baris = $hasil->fetch_assoc()) {
            $kunci = strtolower(trim($baris['nama']));
            if (!isset($aktif[$kunci])) {
                $aktif[$kunci] = ['nama' => $baris['nama'], 'barang' => []];
            }
            $aktif[$kunci]['barang'][] = [
                'id'   => $baris['id_barang'],
                'nama' => $baris['Nama_barang'],
                'sisa' => (int)$baris['sisa'],
                'foto_awal' => $this->getFotoAwalPinjaman($baris['nama'], $baris['id_barang']),
            ];
        }
        return $aktif;
    }

    /**
     * Foto kondisi awal = foto pada Peminjaman TERAKHIR seorang peminjam untuk satu barang.
     * Foto ini hanya dibaca, tidak pernah ditimpa oleh transaksi Pengembalian (terkunci).
     * @return string|null nama file; null jika peminjaman lama belum punya foto
     */
    public function getFotoAwalPinjaman(string $nama_peminjam, string $id_barang): ?string
    {
        $sql = "SELECT d.Foto
                FROM pendataan_barang p
                JOIN detail_pendataan d ON d.Id_Transaksi = p.Id_Transaksi
                WHERE LOWER(TRIM(p.Nama_penerima)) = LOWER(TRIM(?))
                  AND d.id_barang = ?
                  AND p.Jenis_transaksi = ?
                  AND d.Foto IS NOT NULL
                ORDER BY p.Tanggal DESC
                LIMIT 1";
        $pinjam = JenisTransaksi::PEMINJAMAN;
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('sss', $nama_peminjam, $id_barang, $pinjam);
        $stmt->execute();
        $baris = $stmt->get_result()->fetch_assoc();
        return $baris ? $baris['Foto'] : null;
    }

    /** Sisa pinjaman seorang peminjam untuk satu barang. */
    public function getSisaPinjaman(string $nama_peminjam, string $id_barang): int
    {
        $sql = "SELECT COALESCE(SUM(CASE WHEN p.Jenis_transaksi = ? THEN d.Kuantitas ELSE -d.Kuantitas END), 0) AS sisa
                FROM pendataan_barang p
                JOIN detail_pendataan d ON d.Id_Transaksi = p.Id_Transaksi
                WHERE LOWER(TRIM(p.Nama_penerima)) = LOWER(TRIM(?))
                  AND d.id_barang = ?
                  AND p.Jenis_transaksi IN (?, ?)";
        $pinjam = JenisTransaksi::PEMINJAMAN;
        $kembali = JenisTransaksi::PENGEMBALIAN;
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('sssss', $pinjam, $nama_peminjam, $id_barang, $pinjam, $kembali);
        $stmt->execute();
        $baris = $stmt->get_result()->fetch_assoc();
        return $baris ? (int)$baris['sisa'] : 0;
    }

    /** Batas jumlah barang dalam satu transaksi. */
    public const MAKS_BARANG = 20;

    /**
     * Simpan satu transaksi berisi SATU ATAU BANYAK barang (induk + detail + perubahan stok)
     * secara atomik: jika satu barang gagal, seluruh transaksi dibatalkan.
     *
     * @param int   $id_petugas petugas yang mencatat
     * @param array $input      isi $_POST: jenis_transaksi, nama_penerima, items[i][...]
     * @param array $berkasList foto per barang; kunci = indeks items (lihat FotoManager::rapikan)
     * @return string|null pesan error untuk pengguna, atau null jika berhasil
     */
    public function addTransaksi(int $id_petugas, array $input, array $berkasList = []): ?string
    {
        $jenis         = (string)($input['jenis_transaksi'] ?? '');
        $nama_peminjam = trim((string)($input['nama_penerima'] ?? ''));
        $items         = $input['items'] ?? [];

        if (!JenisTransaksi::isValid($jenis)) {
            return 'Jenis transaksi tidak valid.';
        }
        if (!is_array($items) || count($items) < 1) {
            return 'Tambahkan minimal satu barang.';
        }
        if (count($items) > self::MAKS_BARANG) {
            return 'Maksimal ' . self::MAKS_BARANG . ' barang dalam satu transaksi.';
        }

        // 1. Validasi setiap barang (memakai aturan validateInput & validateTambahan yang sama)
        $baris   = [];
        $dipakai = [];
        $no      = 0;
        foreach ($items as $idx => $it) {
            $no++;
            $awalan = count($items) > 1 ? "Barang ke-$no: " : '';
            if (!is_array($it)) {
                return $awalan . 'Data barang tidak valid.';
            }
            $kuantitas = (int)($it['kuantitas'] ?? 0);
            $id_barang = (string)($it['id_barang'] ?? '');
            $nama_baru = trim((string)($it['nama_barang'] ?? ''));
            $kategori  = (string)($it['kategori'] ?? '');
            $sumber    = (string)($it['sumber_dana'] ?? '');
            $alasan    = (string)($it['alasan'] ?? '');
            $berkas    = (isset($berkasList[$idx]) && is_array($berkasList[$idx])) ? $berkasList[$idx] : [];
            $adaFoto   = FotoManager::ada($berkas);

            $error = self::validateInput($jenis, $kuantitas, $nama_peminjam, $id_barang, $nama_baru, $kategori, $sumber)
                  ?? self::validateTambahan($jenis, $alasan, $adaFoto);
            if ($error !== null) {
                return $awalan . $error;
            }
            // Barang yang sama tidak boleh muncul dua kali dalam satu transaksi
            if ($jenis !== JenisTransaksi::BARANG_MASUK) {
                if (isset($dipakai[$id_barang])) {
                    return $awalan . 'Barang ini sudah ada di daftar. Gabungkan jumlahnya dalam satu baris.';
                }
                $dipakai[$id_barang] = true;
            }

            $baris[] = [
                'awalan'    => $awalan,
                'id_barang' => $id_barang,
                'kuantitas' => $kuantitas,
                'nama_baru' => $nama_baru,
                'kategori'  => $kategori,
                'sumber'    => $sumber,
                'alasan'    => ($jenis === JenisTransaksi::BARANG_KELUAR) ? $alasan : null,
                'berkas'    => $berkas,
                'ada_foto'  => $adaFoto,
                'foto'      => null,
            ];
        }

        // 2. Simpan semua foto SEBELUM transaksi database dimulai
        $tersimpan = [];
        foreach ($baris as $k => $b) {
            if (!$b['ada_foto']) {
                continue;
            }
            [$nama_file, $error] = FotoManager::simpan($b['berkas']);
            if ($error !== null) {
                foreach ($tersimpan as $f) {
                    FotoManager::hapus($f);
                }
                return $b['awalan'] . $error;
            }
            $baris[$k]['foto'] = $nama_file;
            $tersimpan[] = $nama_file;
        }

        // 3. Transaksi database: induk satu kali, detail satu baris per barang
        $this->db->begin_transaction();
        try {
            // Cek stok / sisa pinjaman. Barang dikunci berurutan menurut ID agar dua petugas
            // yang menyimpan bersamaan tidak saling menunggu (deadlock).
            if ($jenis !== JenisTransaksi::BARANG_MASUK) {
                $urut = array_keys($baris);
                usort($urut, function ($x, $y) use ($baris) {
                    return strcmp($baris[$x]['id_barang'], $baris[$y]['id_barang']);
                });
                foreach ($urut as $k) {
                    $b = $baris[$k];
                    $stok = $this->getStokTerkunci($b['id_barang']);
                    if ($stok === null) {
                        throw new TransaksiException($b['awalan'] . 'Barang tidak ditemukan.');
                    }
                    if (JenisTransaksi::isMengurangiStok($jenis) && !self::isStokCukup($stok, $b['kuantitas'])) {
                        throw new TransaksiException($b['awalan'] . "Stok tidak cukup (tersedia: $stok unit).");
                    }
                    if ($jenis === JenisTransaksi::PENGEMBALIAN) {
                        $sisa = $this->getSisaPinjaman($nama_peminjam, $b['id_barang']);
                        if ($sisa <= 0) {
                            throw new TransaksiException($b['awalan'] . 'Peminjam tersebut tidak sedang meminjam barang ini.');
                        }
                        if ($b['kuantitas'] > $sisa) {
                            throw new TransaksiException($b['awalan'] . "Jumlah pengembalian melebihi jumlah yang dipinjam (sisa pinjaman: $sisa).");
                        }
                    }
                }
            }

            $nama_penerima = JenisTransaksi::isButuhPeminjam($jenis) ? $nama_peminjam : '-';
            $id_transaksi = generateIdUnik(function ($id) {
                return $this->isIdTransaksiAda($id);
            }, 'TRX', 5);
            $tanggal = date('Y-m-d H:i:s');

            $stmt = $this->db->prepare(
                'INSERT INTO pendataan_barang (Id_Transaksi, Id_petugas, Tanggal, Nama_penerima, Jenis_transaksi) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('sisss', $id_transaksi, $id_petugas, $tanggal, $nama_penerima, $jenis);
            $this->pastikanBerhasil($stmt->execute());

            $stmt_detail = $this->db->prepare(
                'INSERT INTO detail_pendataan (Id_detail, Id_Transaksi, id_barang, Kuantitas, Foto, Alasan) VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($baris as $b) {
                $id_barang = $b['id_barang'];
                if ($jenis === JenisTransaksi::BARANG_MASUK) {
                    // Barang Masuk: daftarkan barang baru, stok awal diisi oleh addBarang()
                    $id_barang = $this->barang->addBarang($b['nama_baru'], $b['kategori'], $b['sumber'], $b['kuantitas']);
                }
                // ID detail dibuat SETELAH detail sebelumnya tersimpan, jadi tidak pernah kembar
                $id_detail = generateIdUnik(function ($id) {
                    return $this->isIdDetailAda($id);
                }, 'DET', 5);
                $kuantitas = $b['kuantitas'];
                $foto      = $b['foto'];
                $alasan_db = $b['alasan'];
                $stmt_detail->bind_param('sssiss', $id_detail, $id_transaksi, $id_barang, $kuantitas, $foto, $alasan_db);
                $this->pastikanBerhasil($stmt_detail->execute());

                if (JenisTransaksi::isMengurangiStok($jenis)) {
                    $this->ubahStok($id_barang, -$kuantitas);
                } elseif (JenisTransaksi::isMenambahStok($jenis)) {
                    $this->ubahStok($id_barang, $kuantitas);
                }
            }

            $this->db->commit();
            return null;
        } catch (TransaksiException $ex) {
            $this->db->rollback();
            foreach ($tersimpan as $f) {
                FotoManager::hapus($f);   // jangan tinggalkan file yatim
            }
            return $ex->getMessage();
        } catch (Throwable $ex) {
            $this->db->rollback();
            foreach ($tersimpan as $f) {
                FotoManager::hapus($f);
            }
            error_log('[TransaksiManager] ' . $ex->getMessage());
            return 'Terjadi kesalahan saat menyimpan transaksi. Silakan coba lagi.';
        }
    }

    /** Baca stok dan kunci barisnya sampai transaksi selesai (mencegah dua petugas menimpa satu sama lain). */
    private function getStokTerkunci(string $id_barang): ?int
    {
        $stmt = $this->db->prepare('SELECT Stok FROM barang WHERE id_barang = ? FOR UPDATE');
        $stmt->bind_param('s', $id_barang);
        $stmt->execute();
        $baris = $stmt->get_result()->fetch_assoc();
        return $baris ? (int)$baris['Stok'] : null;
    }

    private function ubahStok(string $id_barang, int $selisih): void
    {
        $stmt = $this->db->prepare('UPDATE barang SET Stok = Stok + ? WHERE id_barang = ?');
        $stmt->bind_param('is', $selisih, $id_barang);
        $this->pastikanBerhasil($stmt->execute());
    }

    private function isIdTransaksiAda(string $id): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM pendataan_barang WHERE Id_Transaksi = ?');
        $stmt->bind_param('s', $id);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }

    private function isIdDetailAda(string $id): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM detail_pendataan WHERE Id_detail = ?');
        $stmt->bind_param('s', $id);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }

    private function pastikanBerhasil(bool $hasil): void
    {
        if (!$hasil) {
            throw new RuntimeException('Query gagal: ' . $this->db->error);
        }
    }
}