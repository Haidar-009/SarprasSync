<?php
    session_start();
    include 'koneksi.php';

    // CLASS Auth: menangani sesi dan proses login
    class Auth {
        // Properti private (enkapsulasi)
        private $db;

        // Constructor: dijalankan otomatis saat object dibuat
        public function __construct($koneksi) {
            $this->db = $koneksi;
        }

        // Jika sudah login, tidak boleh membuka halaman login lagi
        public function checkSession() {
            if (isset($_SESSION['username'])) {
                header("Location: " . $this->getHalamanTujuan());
                exit();
            }
        }

        // Halaman tujuan sesuai role
        public function getHalamanTujuan() {
            return (($_SESSION['role'] ?? '') === 'wakasek') ? 'wakasek.php' : 'index.php';
        }

        // Proses login. Mengembalikan true jika username & password cocok.
        public function processLogin($username, $password) {
            // Keamanan: mencegah SQL Injection
            $username = mysqli_real_escape_string($this->db, trim($username));
            $password = mysqli_real_escape_string($this->db, trim($password));

            $query = "SELECT * FROM user WHERE Username='$username' AND Password='$password'";
            $hasil = mysqli_query($this->db, $query);

            if ($hasil && mysqli_num_rows($hasil) > 0) {
                $data = mysqli_fetch_assoc($hasil);
                $this->setSession($data['Username'], $data['Nama_lengkap'], $data['Role']);
                return true;
            }
            return false;
        }

        // Simpan data user ke session
        private function setSession($username, $nama, $role) {
            $_SESSION['username'] = $username;
            $_SESSION['nama_lengkap'] = $nama;
            $_SESSION['role'] = $role;
        }
    }

    // INSTANSIASI OBJECT
    $auth = new Auth($koneksi);
    $auth->checkSession();

    // Pesan di dalam kartu + username yang tetap terisi setelah gagal login
    $pesan_error = '';
    $pesan_info = isset($_GET['logout']) ? 'Anda telah keluar dari akun.' : '';
    $username_lama = '';

    if (isset($_POST['login'])) {
        $username_lama = trim($_POST['username']);

        if ($auth->processLogin($_POST['username'], $_POST['password'])) {
            header("Location: " . $auth->getHalamanTujuan());
            exit();
        }
        $pesan_error = 'Username atau password salah. Silakan coba lagi.';
        $pesan_info = '';
    }

    function e($teks) { return htmlspecialchars((string)$teks, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Inventaris Sekolah</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <link href="assets/style.css" rel="stylesheet">
    <script src="assets/app.js" defer></script>
</head>
<body class="halaman-login">

    <div class="login-card">
        <form action="" method="POST" data-loading>
            <div class="logo-box"><i class='bx bx-layer'></i></div>
            <h1>Inventaris Sekolah</h1>
            <p class="sub"></p>

            <?php if ($pesan_error !== '') { ?>
                <div class="alert-login" role="alert">
                    <i class='bx bxs-error-circle'></i>
                    <span><?php echo e($pesan_error); ?></span>
                </div>
            <?php } elseif ($pesan_info !== '') { ?>
                <div class="alert-login ok" role="status">
                    <i class='bx bxs-check-circle'></i>
                    <span><?php echo e($pesan_info); ?></span>
                </div>
            <?php } ?>

            <div class="input-box <?php echo $pesan_error !== '' ? 'error' : ''; ?>">
                <input type="text" name="username" placeholder="Username" autocomplete="username"
                       value="<?php echo e($username_lama); ?>"
                       <?php echo $pesan_error === '' ? 'autofocus' : ''; ?> required>
                <i class='bx bxs-user'></i>
            </div>

            <div class="input-box <?php echo $pesan_error !== '' ? 'error' : ''; ?>">
                <input type="password" name="password" placeholder="Password" autocomplete="current-password"
                       <?php echo $pesan_error !== '' ? 'autofocus' : ''; ?> required>
                <i class='bx bxs-lock-alt'></i>
            </div>

            <button type="submit" name="login" class="btn">Masuk</button>
            <p class="kredit">Haidar Saefullah © 2026</p>
        </form>
    </div>

</body>
</html>
