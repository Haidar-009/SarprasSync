<?php
    session_start();
    include '../koneksi.php';
    require_once __DIR__ . '/../layout.php';

    // Hanya admin
    requireRole(['admin'], '../');

    $userManager = new UserManager($koneksi);
    $isian_lama  = ['username' => '', 'nama_lengkap' => '', 'role' => 'petugas'];

    // Username admin yang sedang login (dipakai agar tidak menghapus/menurunkan diri sendiri).
    // Sesuaikan kunci session ini dengan yang dipakai login.php jika berbeda.
    $username_login = (string)($_SESSION['username'] ?? $_SESSION['Username'] ?? '');

    // Mode edit: tambah_user.php?edit=<id_petugas>
    $user_edit = isset($_GET['edit']) ? $userManager->getUserById((int)$_GET['edit']) : null;
    if ($user_edit !== null) {
        $isian_lama = [
            'username'     => $user_edit['Username'],
            'nama_lengkap' => $user_edit['Nama_lengkap'],
            'role'         => $user_edit['Role'],
        ];
    }

    // Hapus user
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hapus_user'])) {
        if (!csrfOk()) {
            setFlash('err', 'Sesi formulir tidak valid. Silakan muat ulang halaman.');
        } else {
            $error = $userManager->deleteUser((int)$_POST['id_petugas'], $username_login);
            setFlash($error === null ? 'ok' : 'err', $error ?? 'User berhasil dihapus.');
        }
        header('Location: tambah_user.php');
        exit();
    }

    // Simpan perubahan user
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
        if (!csrfOk()) {
            setFlash('err', 'Sesi formulir tidak valid. Silakan muat ulang halaman.');
        } else {
            $id_edit = (int)$_POST['id_petugas'];
            $error = $userManager->updateUser(
                $id_edit,
                (string)$_POST['username'],
                (string)$_POST['nama_lengkap'],
                (string)$_POST['role'],
                (string)($_POST['password'] ?? ''),
                $username_login
            );
            if ($error === null) {
                setFlash('ok', 'Data user berhasil diperbarui.');
                header('Location: tambah_user.php');
                exit();
            }
            setFlash('err', $error);
            $user_edit  = $userManager->getUserById($id_edit);
            $isian_lama = [
                'username'     => (string)$_POST['username'],
                'nama_lengkap' => (string)$_POST['nama_lengkap'],
                'role'         => (string)$_POST['role'],
            ];
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tambah_user'])) {
        if (!csrfOk()) {
            setFlash('err', 'Sesi formulir tidak valid. Silakan muat ulang halaman.');
        } else {
            $error = $userManager->addUser(
                (string)$_POST['username'],
                (string)$_POST['nama_lengkap'],
                (string)$_POST['password'],
                (string)$_POST['role']
            );

            if ($error === null) {
                setFlash('ok', 'User berhasil ditambahkan.');
                header('Location: tambah_user.php');   // cegah kirim ulang form saat refresh
                exit();
            }
            setFlash('err', $error);
            // Isian dipertahankan (kecuali password) supaya tidak mengetik ulang
            $isian_lama = [
                'username'     => (string)$_POST['username'],
                'nama_lengkap' => (string)$_POST['nama_lengkap'],
                'role'         => (string)$_POST['role'],
            ];
        }
    }

    $daftar_user = $userManager->getAllUser();

    layoutStart(['judul' => 'Tambah User', 'aktif' => 'user', 'base' => '../']);
    echo renderHeader($user_edit ? 'Edit User' : 'Tambah User', $user_edit ? 'Ubah data akun. Kosongkan password jika tidak ingin menggantinya.' : 'Buat akun baru untuk petugas, wakasek, atau admin.');
?>

            <form method="POST" class="form-card" data-loading>
                <?php echo csrfField(); ?>
                <?php if ($user_edit): ?>
                    <input type="hidden" name="id_petugas" value="<?php echo (int)$user_edit['id_petugas']; ?>">
                <?php endif; ?>
                <div>
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" value="<?php echo e($isian_lama['username']); ?>" autocomplete="off" required>
                </div>
                <div>
                    <label for="nama_lengkap">Nama Lengkap</label>
                    <input type="text" id="nama_lengkap" name="nama_lengkap" value="<?php echo e($isian_lama['nama_lengkap']); ?>" required>
                </div>
                <div>
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" minlength="<?php echo UserManager::PASSWORD_MIN; ?>" autocomplete="new-password" <?php echo $user_edit ? 'placeholder="Kosongkan jika tidak diganti"' : 'required'; ?>>
                </div>
                <div>
                    <label for="role">Role</label>
                    <select id="role" name="role">
                        <?php foreach (UserManager::ROLE_VALID as $peran): ?>
                            <option value="<?php echo e($peran); ?>" <?php echo $isian_lama['role'] === $peran ? 'selected' : ''; ?>><?php echo e(ucfirst($peran)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($user_edit): ?>
                    <button type="submit" name="update_user" class="btn"><i class='bx bx-save'></i> Simpan Perubahan</button>
                    <a href="tambah_user.php" class="btn btn-ghost">Batal</a>
                <?php else: ?>
                    <button type="submit" name="tambah_user" class="btn"><i class='bx bx-plus'></i> Simpan User</button>
                <?php endif; ?>
            </form>

            <div class="section-header">
                <h3>Daftar User</h3>
                <span class="count"><?php echo count($daftar_user); ?> akun</span>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr><th>No</th><th>Username</th><th>Nama Lengkap</th><th>Role</th><th>Aksi</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($daftar_user)): ?>
                        <tr><td colspan="5"><?php echo renderEmptyState('bx-user', 'Belum ada user', 'Gunakan formulir di atas untuk membuat akun pertama.'); ?></td></tr>
                        <?php else: $no = 1; foreach ($daftar_user as $user): ?>
                        <tr>
                            <td><?php echo $no++; ?></td>
                            <td><strong class="kode"><?php echo e($user['Username']); ?></strong></td>
                            <td><?php echo e($user['Nama_lengkap']); ?></td>
                            <td><span class="badge <?php echo $user['Role'] === 'admin' ? 'badge-red' : ($user['Role'] === 'wakasek' ? 'badge-blue' : 'badge-green'); ?>"><?php echo e($user['Role']); ?></span></td>
                            <td>
                                <div style="display:flex;gap:8px;align-items:center;">
                                    <a href="tambah_user.php?edit=<?php echo (int)$user['id_petugas']; ?>" class="btn btn-ghost" style="padding:6px 12px;"><i class='bx bx-edit'></i> Edit</a>
                                    <?php if ($user['Username'] !== $username_login): ?>
                                    <form method="POST" style="margin:0;" onsubmit="return confirm('Hapus user <?php echo e($user['Username']); ?>? Tindakan ini tidak bisa dibatalkan.');">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="id_petugas" value="<?php echo (int)$user['id_petugas']; ?>">
                                        <button type="submit" name="hapus_user" class="btn btn-ghost" style="padding:6px 12px;color:var(--bad);"><i class='bx bx-trash'></i> Hapus</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

<?php layoutEnd(['base' => '../']); ?>