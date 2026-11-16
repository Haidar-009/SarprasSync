<?php
    session_start();
    include '../koneksi.php';
    require_once __DIR__ . '/../layout.php';

    requireLogin('../');

    $barangManager = new BarangManager($koneksi);

    // Hanya admin yang boleh menimpa stok langsung. Petugas hanya boleh menambah.
    $boleh_ubah_stok = isAdmin();

    /**
     * LOGIKA 1: PROSES SIMPAN (tombol "Simpan Perubahan Data" pada form edit)
     */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_update'])) {
        $id_barang   = (string)($_POST['id_barang'] ?? '');
        $nama        = trim((string)($_POST['nama_barang'] ?? ''));
        $kategori    = (string)($_POST['jenis_barang'] ?? '');
        $sumber_dana = (string)($_POST['sumber_dana'] ?? '');

        if (!csrfOk()) {
            setFlash('err', 'Sesi formulir tidak valid. Silakan muat ulang halaman.');
        } elseif ($nama === '' || !BarangManager::isKategoriValid($kategori) || !BarangManager::isSumberDanaValid($sumber_dana)) {
            setFlash('err', 'Nama, kategori, dan sumber dana wajib diisi dengan benar.');
        } elseif ($barangManager->getBarangById($id_barang) === null) {
            setFlash('err', 'Barang tidak ditemukan.');
        } else {
            $stok_baru = ($boleh_ubah_stok && isset($_POST['stok'])) ? (int)$_POST['stok'] : null;
            $tambah    = (int)($_POST['tambah_stok'] ?? 0);

            try {
                $berhasil = $barangManager->updateBarang($id_barang, $nama, $kategori, $sumber_dana, $stok_baru, $tambah);
            } catch (Throwable $ex) {
                error_log('[update.php] ' . $ex->getMessage());
                $berhasil = false;
            }

            if ($berhasil) {
                setFlash('ok', 'Data barang berhasil diperbarui.');
                header('Location: update.php');
                exit();
            }
            setFlash('err', 'Gagal memperbarui data. Silakan coba lagi.');
        }
        // Gagal: kembali ke form edit barang yang sama
        header('Location: update.php?id=' . urlencode($id_barang));
        exit();
    }

    /**
     * LOGIKA 2: MODE FORM EDIT (ada parameter ?id=...)
     * LOGIKA 3: MODE TABEL (default)
     */
    $mode_edit = false;
    $data_edit = null;
    $daftar_barang = [];

    if (isset($_GET['id'])) {
        $data_edit = $barangManager->getBarangById((string)$_GET['id']);
        if ($data_edit === null) {
            setFlash('err', 'Data barang tidak ditemukan.');
            header('Location: update.php');
            exit();
        }
        $mode_edit = true;
    } else {
        $daftar_barang = $barangManager->getAllBarang();
    }

    layoutStart(['judul' => 'Ubah Barang', 'aktif' => 'ubah', 'base' => '../']);
?>

            <?php if ($mode_edit): ?>
                <a href="update.php" class="btn btn-soft btn-small btn-back"><i class='bx bx-left-arrow-alt'></i> Kembali ke Daftar</a>
                <?php echo renderHeader('Edit Data Aset', 'Perbarui informasi detail barang pada sistem.'); ?>

                <div class="form-container">
                    <form action="" method="POST" data-loading>
                        <?php echo csrfField(); ?>

                        <div class="form-group">
                            <label for="id_barang">ID Barang (tidak dapat diubah)</label>
                            <input type="text" id="id_barang" name="id_barang" class="form-control kode" value="<?php echo e($data_edit['id_barang']); ?>" readonly>
                        </div>

                        <div class="form-group">
                            <label for="nama_barang">Nama Barang / Aset</label>
                            <input type="text" id="nama_barang" name="nama_barang" class="form-control" value="<?php echo e($data_edit['Nama_barang']); ?>" required>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label for="jenis_barang">Kategori / Jenis Barang</label>
                                <select id="jenis_barang" name="jenis_barang" class="form-control" required>
                                    <?php foreach (BarangManager::KATEGORI as $nilai => $label): ?>
                                        <option value="<?php echo e($nilai); ?>" <?php echo $data_edit['Jenis_barang'] === $nilai ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="sumber_dana">Sumber Dana</label>
                                <select id="sumber_dana" name="sumber_dana" class="form-control" required>
                                    <?php foreach (BarangManager::SUMBER_DANA as $nilai => $label): ?>
                                        <option value="<?php echo e($nilai); ?>" <?php echo $data_edit['Sumber_dana'] === $nilai ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <?php if ($boleh_ubah_stok): ?>
                            <!-- Admin: stok boleh diubah langsung -->
                            <div class="form-group">
                                <label for="stok">Stok</label>
                                <input type="number" id="stok" name="stok" class="form-control" min="0" value="<?php echo (int)$data_edit['Stok']; ?>" required>
                            </div>
                        <?php else: ?>
                            <!-- Petugas: stok saat ini terkunci, hanya bisa menambah -->
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="stok_sekarang">Stok Saat Ini (tidak dapat diubah)</label>
                                    <input type="number" id="stok_sekarang" class="form-control" value="<?php echo (int)$data_edit['Stok']; ?>" disabled>
                                </div>
                                <div class="form-group">
                                    <label for="tambah_stok">Tambah Stok (misal: pembelian baru)</label>
                                    <input type="number" id="tambah_stok" name="tambah_stok" class="form-control" min="0" value="0" placeholder="Misal: 10">
                                </div>
                            </div>
                            <p class="form-hint">Stok setelah disimpan: <strong id="stok_total"><?php echo (int)$data_edit['Stok']; ?></strong></p>
                        <?php endif; ?>

                        <button type="submit" name="simpan_update" class="btn btn-block">Simpan Perubahan Data</button>
                    </form>
                </div>

            <?php else: ?>
                <?php echo renderHeader('Ubah Data Barang', 'pilih barang yang ingin diperbarui datanya dari tabel di bawah.'); ?>

                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ID Barang</th>
                                <th>Nama Barang</th>
                                <th>Jenis</th>
                                <th>Stok</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($daftar_barang)): ?>
                            <tr>
                                <td colspan="5">
                                    <?php echo renderEmptyState(
                                        'bx-package',
                                        'Belum ada barang yang bisa diubah',
                                        'Tambahkan barang lebih dulu lewat menu Transaksi, jenis "Barang Masuk".',
                                        "<a class='btn btn-small' href='pendataan.php'><i class='bx bx-plus'></i> Catat Barang Masuk</a>"
                                    ); ?>
                                </td>
                            </tr>
                            <?php else: foreach ($daftar_barang as $barang): ?>
                            <tr>
                                <td><strong class="kode"><?php echo e($barang['id_barang']); ?></strong></td>
                                <td><?php echo e($barang['Nama_barang']); ?></td>
                                <td><?php echo e($barang['Jenis_barang']); ?></td>
                                <td><span class="badge <?php echo kelasStok((int)$barang['Stok']); ?>"><?php echo e($barang['Stok']); ?> Unit</span></td>
                                <td>
                                    <a href="update.php?id=<?php echo urlencode($barang['id_barang']); ?>" class="btn btn-small">
                                        <i class='bx bx-edit'></i> Edit Data
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

<?php
    // Hitung otomatis "Stok setelah disimpan" (khusus tampilan petugas)
    $script_halaman = <<<'JS'
        var inpTambah = document.getElementById('tambah_stok');
        if (inpTambah) {
            var stokSekarang = parseInt(document.getElementById('stok_sekarang').value, 10) || 0;
            inpTambah.addEventListener('input', function () {
                var tambah = parseInt(inpTambah.value, 10);
                if (isNaN(tambah) || tambah < 0) tambah = 0;
                document.getElementById('stok_total').textContent = stokSekarang + tambah;
            });
        }
JS;
    layoutEnd(['base' => '../', 'script' => $mode_edit ? $script_halaman : '']);
?>
