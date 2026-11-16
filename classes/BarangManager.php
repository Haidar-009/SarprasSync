<?php
/**
 * BarangManager — semua operasi database untuk tabel `barang`.
 * Menggantikan tiga class kembar sebelumnya (BarangManager di Read.php dan admin.php,
 * BarangUpdater di Update.php). Seluruh query memakai prepared statement.
 */
class BarangManager
{
    /** Pilihan kategori: nilai di database => label di form. */
    public const KATEGORI = [
        'Elektronik'         => 'Elektronik & Kelistrikan',
        'Furniture'          => 'Furniture & Meubelair',
        'ATK'                => 'ATK / Alat Tulis',
        'Peralatan Olahraga' => 'Peralatan Olahraga',
        'Lainnya'            => 'Lainnya',
    ];

    /** Pilihan sumber dana: nilai di database => label di form. */
    public const SUMBER_DANA = [
        'BOS'    => 'Dana BOS',
        'Hibah'  => 'Hibah / Bantuan',
        'Komite' => 'Dana Komite',
    ];

    /** @var mysqli */
    private $db;

    public function __construct(mysqli $koneksi)
    {
        $this->db = $koneksi;
    }

    public static function isKategoriValid(string $kategori): bool
    {
        return array_key_exists($kategori, self::KATEGORI);
    }

    public static function isSumberDanaValid(string $sumber): bool
    {
        return array_key_exists($sumber, self::SUMBER_DANA);
    }

    /** @return array<int, array<string, mixed>> */
    public function getAllBarang(): array
    {
        $data = [];
        $hasil = $this->db->query('SELECT * FROM barang ORDER BY id_barang ASC');
        if ($hasil) {
            while ($baris = $hasil->fetch_assoc()) {
                $data[] = $baris;
            }
        }
        return $data;
    }

    /** @return array<string, mixed>|null null jika ID tidak ditemukan */
    public function getBarangById(string $id_barang): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM barang WHERE id_barang = ?');
        $stmt->bind_param('s', $id_barang);
        $stmt->execute();
        $baris = $stmt->get_result()->fetch_assoc();
        return $baris ?: null;
    }

    /** Jumlah barang yang stoknya di bawah batas (default 5). */
    public function countStokMenipis(int $batas = 5): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM barang WHERE Stok < ?');
        $stmt->bind_param('i', $batas);
        $stmt->execute();
        $baris = $stmt->get_result()->fetch_row();
        return $baris ? (int)$baris[0] : 0;
    }

    public function isIdBarangAda(string $id_barang): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM barang WHERE id_barang = ?');
        $stmt->bind_param('s', $id_barang);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }

    /**
     * Tambah barang baru ke tabel master dengan ID acak yang unik (BRG + 4 digit).
     * @return string ID barang yang dibuat
     */
    public function addBarang(string $nama, string $kategori, string $sumber_dana, int $stok): string
    {
        $id_barang = generateIdUnik(function ($id) {
            return $this->isIdBarangAda($id);
        }, 'BRG', 4);

        $stmt = $this->db->prepare(
            'INSERT INTO barang (id_barang, Nama_barang, Stok, Jenis_barang, Sumber_dana) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('ssiss', $id_barang, $nama, $stok, $kategori, $sumber_dana);
        if (!$stmt->execute()) {
            throw new RuntimeException('Gagal menyimpan barang baru.');
        }
        return $id_barang;
    }

    /**
     * Ubah data barang.
     * - Admin: $stok_baru diisi -> stok ditimpa langsung.
     * - Petugas: $stok_baru = null -> stok hanya ditambah sebesar $tambah_stok.
     */
    public function updateBarang(
        string $id_barang,
        string $nama,
        string $kategori,
        string $sumber_dana,
        ?int $stok_baru = null,
        int $tambah_stok = 0
    ): bool {
        if ($stok_baru !== null) {
            $stok_baru = max(0, $stok_baru);
            $stmt = $this->db->prepare(
                'UPDATE barang SET Nama_barang = ?, Jenis_barang = ?, Sumber_dana = ?, Stok = ? WHERE id_barang = ?'
            );
            $stmt->bind_param('sssis', $nama, $kategori, $sumber_dana, $stok_baru, $id_barang);
        } else {
            $tambah_stok = max(0, $tambah_stok);
            $stmt = $this->db->prepare(
                'UPDATE barang SET Nama_barang = ?, Jenis_barang = ?, Sumber_dana = ?, Stok = Stok + ? WHERE id_barang = ?'
            );
            $stmt->bind_param('sssis', $nama, $kategori, $sumber_dana, $tambah_stok, $id_barang);
        }
        return $stmt->execute();
    }

    /** Hapus barang. False jika masih terikat data transaksi (foreign key). */
    public function deleteBarang(string $id_barang): bool
    {
        $stmt = $this->db->prepare('DELETE FROM barang WHERE id_barang = ?');
        $stmt->bind_param('s', $id_barang);
        try {
            return $stmt->execute();
        } catch (mysqli_sql_exception $ex) {
            return false;
        }
    }
}
