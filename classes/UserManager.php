<?php
/**
 * UserManager — operasi database untuk tabel `user`.
 * Menggantikan dua class kembar (admin.php dan tambah_user.php).
 */
class UserManager
{
    public const ROLE_VALID = ['petugas', 'wakasek', 'admin'];
    public const PASSWORD_MIN = 6;

    // Pengaturan foto profil
    public const FOTO_MAKS_BYTE = 2097152;           // 2 MB
    public const FOTO_SISI      = 256;               // hasil akhir 256 x 256 piksel
    public const FOTO_FOLDER    = 'uploads/avatar/'; // relatif terhadap folder proyek

    /** @var mysqli */
    private $db;

    public function __construct(mysqli $koneksi)
    {
        $this->db = $koneksi;
    }

    /** @return array<int, array<string, mixed>> */
    public function getAllUser(): array
    {
        $data = [];
        $hasil = $this->db->query('SELECT id_petugas, Username, Nama_lengkap, Role FROM user ORDER BY Role, Username');
        if ($hasil) {
            while ($baris = $hasil->fetch_assoc()) {
                $data[] = $baris;
            }
        }
        return $data;
    }

    public function isUsernameTaken(string $username): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM user WHERE Username = ?');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }

    public function isIdPetugasAda(int $id_petugas): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM user WHERE id_petugas = ?');
        $stmt->bind_param('i', $id_petugas);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }

    /** ID petugas milik username tertentu; 0 jika tidak ditemukan. */
    public function findIdPetugas(string $username): int
    {
        $stmt = $this->db->prepare('SELECT id_petugas FROM user WHERE Username = ?');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $baris = $stmt->get_result()->fetch_assoc();
        return $baris ? (int)$baris['id_petugas'] : 0;
    }

    /* ------------------------------------------------- UBAH & HAPUS USER */

    /** @return array<string, mixed>|null null jika ID tidak ditemukan */
    public function getUserById(int $id_petugas): ?array
    {
        $stmt = $this->db->prepare('SELECT id_petugas, Username, Nama_lengkap, Role FROM user WHERE id_petugas = ?');
        $stmt->bind_param('i', $id_petugas);
        $stmt->execute();
        $baris = $stmt->get_result()->fetch_assoc();
        return $baris ?: null;
    }

    public function countAdmin(): int
    {
        $hasil = $this->db->query("SELECT COUNT(*) FROM user WHERE Role = 'admin'");
        $baris = $hasil ? $hasil->fetch_row() : null;
        return $baris ? (int)$baris[0] : 0;
    }

    /**
     * Ubah data user. Password boleh dikosongkan = password lama tidak diganti.
     * @param string $username_login username admin yang sedang login (untuk perlindungan diri sendiri)
     * @return string|null pesan error, atau null jika berhasil
     */
    public function updateUser(
        int $id_petugas,
        string $username,
        string $nama,
        string $role,
        string $password_baru,
        string $username_login
    ): ?string {
        $username = trim($username);
        $nama = trim($nama);

        $lama = $this->getUserById($id_petugas);
        if ($lama === null) {
            return 'User tidak ditemukan.';
        }
        if ($username === '' || $nama === '') {
            return 'Username dan nama lengkap wajib diisi.';
        }
        if ($password_baru !== '' && strlen($password_baru) < self::PASSWORD_MIN) {
            return 'Password baru minimal ' . self::PASSWORD_MIN . ' karakter (kosongkan jika tidak diganti).';
        }
        if (!in_array($role, self::ROLE_VALID, true)) {
            return 'Role tidak valid.';
        }

        // Username harus unik (selain milik user ini sendiri)
        $stmt = $this->db->prepare('SELECT 1 FROM user WHERE Username = ? AND id_petugas <> ?');
        $stmt->bind_param('si', $username, $id_petugas);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            return 'Username sudah dipakai user lain.';
        }

        // Jangan sampai sistem tanpa admin / admin menurunkan role dirinya sendiri
        if ($lama['Role'] === 'admin' && $role !== 'admin') {
            if ($lama['Username'] === $username_login) {
                return 'Anda tidak bisa menurunkan role akun Anda sendiri.';
            }
            if ($this->countAdmin() <= 1) {
                return 'Tidak bisa menurunkan role: ini admin terakhir.';
            }
        }

        try {
            if ($password_baru !== '') {
                // Sama seperti addUser: masih teks biasa sampai login.php memakai password_verify()
                $stmt = $this->db->prepare(
                    'UPDATE user SET Username = ?, Nama_lengkap = ?, Role = ?, Password = ? WHERE id_petugas = ?'
                );
                $stmt->bind_param('ssssi', $username, $nama, $role, $password_baru, $id_petugas);
            } else {
                $stmt = $this->db->prepare(
                    'UPDATE user SET Username = ?, Nama_lengkap = ?, Role = ? WHERE id_petugas = ?'
                );
                $stmt->bind_param('sssi', $username, $nama, $role, $id_petugas);
            }
            return $stmt->execute() ? null : 'Gagal menyimpan perubahan user.';
        } catch (mysqli_sql_exception $ex) {
            return 'Gagal menyimpan perubahan user.';
        }
    }

    /**
     * Hapus user. Ditolak untuk akun sendiri, admin terakhir, dan user yang
     * sudah punya data transaksi (foreign key).
     * @return string|null pesan error, atau null jika berhasil
     */
    public function deleteUser(int $id_petugas, string $username_login): ?string
    {
        $user = $this->getUserById($id_petugas);
        if ($user === null) {
            return 'User tidak ditemukan.';
        }
        if ($user['Username'] === $username_login) {
            return 'Anda tidak bisa menghapus akun yang sedang Anda pakai.';
        }
        if ($user['Role'] === 'admin' && $this->countAdmin() <= 1) {
            return 'Tidak bisa menghapus admin terakhir.';
        }

        $foto = $this->getFoto($user['Username']);

        $stmt = $this->db->prepare('DELETE FROM user WHERE id_petugas = ?');
        $stmt->bind_param('i', $id_petugas);
        try {
            if (!$stmt->execute()) {
                return 'Gagal menghapus user.';
            }
        } catch (mysqli_sql_exception $ex) {
            return 'User tidak bisa dihapus karena sudah tercatat di data transaksi.';
        }

        if ($foto !== null) {
            @unlink(dirname(__DIR__) . '/' . self::FOTO_FOLDER . basename($foto));
        }
        return null;
    }

    /* ------------------------------------------------------ FOTO PROFIL */

    /** Nama file foto milik username; null jika belum punya. */
    public function getFoto(string $username): ?string
    {
        $stmt = $this->db->prepare('SELECT Foto FROM user WHERE Username = ?');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $baris = $stmt->get_result()->fetch_assoc();
        return ($baris && !empty($baris['Foto'])) ? $baris['Foto'] : null;
    }

    /** Catat nama file foto di database; null = kembali ke avatar default. */
    public function updateFoto(string $username, ?string $nama_file): bool
    {
        $stmt = $this->db->prepare('UPDATE user SET Foto = ? WHERE Username = ?');
        $stmt->bind_param('ss', $nama_file, $username);
        return $stmt->execute();
    }

    /** Alamat gambar untuk <img>; null jika belum punya foto atau file-nya hilang. */
    public static function urlFoto(?string $nama_file, string $base = ''): ?string
    {
        if ($nama_file === null) {
            return null;
        }
        $nama_file = basename($nama_file);   // basename() mencegah path seperti "../../x"
        if (!is_file(dirname(__DIR__) . '/' . self::FOTO_FOLDER . $nama_file)) {
            return null;
        }
        return $base . self::FOTO_FOLDER . $nama_file;
    }

    /**
     * Simpan foto baru (menggantikan yang lama).
     * @param array $berkas isi $_FILES['foto']
     * @return string|null pesan error, atau null jika berhasil
     */
    public function simpanFoto(string $username, array $berkas): ?string
    {
        // 1. Cek hasil upload
        $kode = $berkas['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($kode === UPLOAD_ERR_NO_FILE) {
            return 'Pilih foto terlebih dahulu.';
        }
        if ($kode !== UPLOAD_ERR_OK || $berkas['size'] > self::FOTO_MAKS_BYTE) {
            return 'Foto gagal diunggah atau ukurannya lebih dari 2 MB.';
        }
        if (!extension_loaded('gd')) {
            return 'Ekstensi GD belum aktif di PHP.';
        }

        // 2. Pastikan ISI file benar-benar gambar JPG/PNG/WebP (bukan sekadar namanya)
        $info = @getimagesize($berkas['tmp_name']);
        if ($info === false || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return 'Format foto harus JPG, PNG, atau WebP.';
        }
        if ($info[0] * $info[1] > 25000000) {   // cegah gambar raksasa menghabiskan memori
            return 'Dimensi foto terlalu besar.';
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
            return 'Foto tidak bisa dibaca. Coba gunakan file lain.';
        }

        // 4. Pangkas persegi dari tengah, lalu kecilkan ke 256 x 256 (latar putih untuk PNG transparan)
        $sisi  = min($info[0], $info[1]);
        $hasil = imagecreatetruecolor(self::FOTO_SISI, self::FOTO_SISI);
        imagefill($hasil, 0, 0, imagecolorallocate($hasil, 255, 255, 255));
        imagecopyresampled(
            $hasil, $asal, 0, 0,
            intdiv($info[0] - $sisi, 2), intdiv($info[1] - $sisi, 2),
            self::FOTO_SISI, self::FOTO_SISI, $sisi, $sisi
        );

        // 5. Simpan sebagai JPEG dengan nama acak
        $folder = dirname(__DIR__) . '/' . self::FOTO_FOLDER;
        if (!is_dir($folder) && !@mkdir($folder, 0755, true)) {
            return 'Folder penyimpanan foto tidak bisa dibuat.';
        }
        $nama_baru = 'u_' . bin2hex(random_bytes(8)) . '.jpg';
        if (!imagejpeg($hasil, $folder . $nama_baru, 85)) {
            return 'Foto gagal disimpan di server.';
        }

        // 6. Catat di database, lalu hapus foto lama
        $lama = $this->getFoto($username);
        if (!$this->updateFoto($username, $nama_baru)) {
            unlink($folder . $nama_baru);   // batal: jangan tinggalkan file yatim
            return 'Gagal memperbarui data foto.';
        }
        if ($lama !== null) {
            @unlink($folder . basename($lama));
        }
        return null;
    }

    /**
     * Hapus foto dan kembali ke avatar default.
     * @return string|null pesan error, atau null jika berhasil
     */
    public function hapusFoto(string $username): ?string
    {
        $lama = $this->getFoto($username);
        if ($lama === null) {
            return 'Anda belum memiliki foto profil.';
        }
        if (!$this->updateFoto($username, null)) {
            return 'Gagal menghapus foto profil.';
        }
        @unlink(dirname(__DIR__) . '/' . self::FOTO_FOLDER . basename($lama));
        return null;
    }

    /**
     * Validasi input user baru (murni, tanpa database).
     * @return string|null pesan error, atau null jika valid
     */
    public static function validateUser(string $username, string $nama, string $password, string $role): ?string
    {
        if (trim($username) === '' || trim($nama) === '' || strlen($password) < self::PASSWORD_MIN) {
            return 'Semua kolom wajib diisi dan password minimal ' . self::PASSWORD_MIN . ' karakter.';
        }
        if (!in_array($role, self::ROLE_VALID, true)) {
            return 'Role tidak valid.';
        }
        return null;
    }

    /**
     * Tambah user baru dengan ID petugas acak 9 digit yang unik.
     * @return string|null pesan error, atau null jika berhasil
     */
    public function addUser(string $username, string $nama, string $password, string $role): ?string
    {
        $username = trim($username);
        $nama = trim($nama);

        $error = self::validateUser($username, $nama, $password, $role);
        if ($error !== null) {
            return $error;
        }
        if ($this->isUsernameTaken($username)) {
            return 'Username sudah dipakai.';
        }

        $id_petugas = (int)generateIdUnik(function ($id) {
            return $this->isIdPetugasAda((int)$id);
        }, '', 9);

        // TODO (putaran berikutnya): simpan sebagai password_hash() dan ubah login.php
        // memakai password_verify(). Saat ini login.php masih membandingkan teks biasa,
        // jadi di sini password sengaja belum di-hash agar user baru tetap bisa login.
        $stmt = $this->db->prepare(
            'INSERT INTO user (id_petugas, Username, Password, Nama_lengkap, Role) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('issss', $id_petugas, $username, $password, $nama, $role);
        return $stmt->execute() ? null : 'Gagal menyimpan user.';
    }
}