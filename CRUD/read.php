<?php
    session_start();
    include '../koneksi.php';
    require_once __DIR__ . '/../layout.php';

    requireLogin('../');

    $barangManager = new BarangManager($koneksi);

    // HAPUS BARANG (hanya admin; dicek di server, bukan sekadar menyembunyikan tombol)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hapus'])) {
        if (!isAdmin() || !csrfOk()) {
            setFlash('err', 'Akses ditolak.');
        } elseif ($barangManager->deleteBarang((string)$_POST['hapus'])) {
            setFlash('ok', 'Data barang berhasil dihapus.');
        } else {
            setFlash('err', 'Gagal menghapus. Pastikan barang ini tidak terikat dengan data transaksi.');
        }
        header('Location: read.php');
        exit();
    }

    $daftar_barang = $barangManager->getAllBarang();
    $jumlah_kolom  = isAdmin() ? 7 : 6;

    layoutStart(['judul' => 'Data Barang', 'aktif' => 'barang', 'base' => '../']);
    echo renderHeader('Data Master Barang', count($daftar_barang) . ' jenis barang terdaftar di gudang.');
?>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>ID Barang</th>
                            <th>Nama Barang</th>
                            <th>Jenis Barang</th>
                            <th>Sumber Dana</th>
                            <th>Stok</th>
                            <?php if (isAdmin()): ?><th>Aksi</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($daftar_barang)): ?>
                        <tr>
                            <td colspan="<?php echo $jumlah_kolom; ?>">
                                <?php echo renderEmptyState(
                                    'bx-package',
                                    'Belum ada data barang',
                                    'Barang pertama dicatat lewat menu Transaksi, dengan jenis "Barang Masuk".',
                                    "<a class='btn btn-small' href='pendataan.php'><i class='bx bx-plus'></i> Catat Barang Masuk</a>"
                                ); ?>
                            </td>
                        </tr>
                        <?php else: $no = 1; foreach ($daftar_barang as $barang): ?>
                        <tr>
                            <td><?php echo $no++; ?></td>
                            <td><strong class="kode"><?php echo e($barang['id_barang']); ?></strong></td>
                            <td><?php echo e($barang['Nama_barang']); ?></td>
                            <td><?php echo e($barang['Jenis_barang']); ?></td>
                            <td><?php echo e($barang['Sumber_dana']); ?></td>
                            <td><span class="badge <?php echo kelasStok((int)$barang['Stok']); ?>"><?php echo e($barang['Stok']); ?> Unit</span></td>
                            <?php if (isAdmin()): ?>
                            <td>
                                <form method="POST" onsubmit="return confirm('Hapus barang ini?')">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="hapus" value="<?php echo e($barang['id_barang']); ?>">
                                    <button type="submit" class="btn btn-danger"><i class='bx bx-trash'></i> Hapus</button>
                                </form>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

<?php layoutEnd(['base' => '../']); ?>
