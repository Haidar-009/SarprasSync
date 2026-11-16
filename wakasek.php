<?php
    session_start();
    include 'koneksi.php';
    require_once __DIR__ . '/layout.php';

    // Hanya untuk role wakasek
    requireRole(['wakasek']);

    $nama_user = $_SESSION['nama_lengkap'] ?? $_SESSION['username'];

    /**
     * Class WakasekManager (read-only)
     * Ringkasan dan laporan untuk pengawas.
     */
    class WakasekManager {
        private $db;

        public function __construct($koneksi) {
            $this->db = $koneksi;
        }

        // Ambil 1 angka dari query, default 0
        private function getScalar($sql) {
            $hasil = $this->db->query($sql);
            $baris = $hasil ? $hasil->fetch_row() : null;
            return ($baris && $baris[0]) ? (int)$baris[0] : 0;
        }

        // Total stok yang tersedia di gudang
        public function getTotalKondisiBaik() {
            return $this->getScalar("SELECT SUM(Stok) FROM barang");
        }

        // Barang yang SEDANG dipinjam = total Peminjaman dikurangi total Pengembalian
        public function getTotalDipinjam() {
            $pinjam  = JenisTransaksi::PEMINJAMAN;
            $kembali = JenisTransaksi::PENGEMBALIAN;
            return $this->getScalar("SELECT GREATEST(0, SUM(CASE WHEN p.Jenis_transaksi = '$pinjam' THEN d.Kuantitas ELSE -d.Kuantitas END))
                    FROM detail_pendataan d
                    JOIN pendataan_barang p ON d.Id_Transaksi = p.Id_Transaksi
                    WHERE p.Jenis_transaksi IN ('$pinjam', '$kembali')");
        }

        // Total barang keluar (rusak / afkir)
        public function getTotalRusak() {
            $keluar = JenisTransaksi::BARANG_KELUAR;
            return $this->getScalar("SELECT SUM(d.Kuantitas)
                    FROM detail_pendataan d
                    JOIN pendataan_barang p ON d.Id_Transaksi = p.Id_Transaksi
                    WHERE p.Jenis_transaksi = '$keluar'");
        }

        public function getLaporanTransaksi() {
            $data = [];
            $hasil = $this->db->query("SELECT b.Nama_barang, b.Jenis_barang, p.Tanggal, d.Kuantitas, p.Jenis_transaksi, p.Nama_penerima
                    FROM detail_pendataan d
                    JOIN pendataan_barang p ON d.Id_Transaksi = p.Id_Transaksi
                    JOIN barang b ON d.id_barang = b.id_barang
                    ORDER BY p.Tanggal DESC");
            while ($hasil && $baris = $hasil->fetch_assoc()) {
                $data[] = $baris;
            }
            return $data;
        }

        public function getLaporanBarang() {
            return (new BarangManager($this->db))->getAllBarang();
        }
    }

    $wakasek        = new WakasekManager($koneksi);
    $total_baik     = $wakasek->getTotalKondisiBaik();
    $total_dipinjam = $wakasek->getTotalDipinjam();
    $total_rusak    = $wakasek->getTotalRusak();
    $lap_transaksi  = $wakasek->getLaporanTransaksi();
    $lap_barang     = $wakasek->getLaporanBarang();

    // Sidebar wakasek memakai tombol tab, bukan tautan antarhalaman
    $menu_wakasek = [
        ['key' => 'dashboard', 'tab' => 'dashboard', 'ikon' => 'bxs-dashboard', 'label' => 'Utama', 'judul' => 'Ringkasan & histori transaksi'],
        ['key' => 'laporan',   'tab' => 'laporan',   'ikon' => 'bx-file',       'label' => 'Stok',  'judul' => 'Laporan stok barang'],
    ];

    layoutStart([
        'judul'     => 'Dashboard Pengawas',
        'aktif'     => 'dashboard',
        'base'      => '',
        'menu'      => $menu_wakasek,
        'ikon_logo' => 'bx-check-shield',
    ]);

    $kanan_header = renderProfilUser($koneksi, '', 'Wakasek');   // foto + nama + tombol settings (lihat helpers.php)
    echo renderHeader('Dashboard Pengawasan', 'Sistem pemantauan aset & inventaris sekolah.', $kanan_header);
?>

            <!-- TAB 1: DASHBOARD UTAMA (TRANSAKSI) -->
            <div id="dashboard" class="tab-content active">
                <div class="stat-grid">
                    <div class="stat-card masuk">
                        <i class='bx bx-check-circle'></i>
                        <div class="angka"><?php echo e($total_baik); ?><small>unit</small></div>
                        <div class="label">Kondisi Baik (Tersedia)</div>
                        <div class="petunjuk">Total stok di gudang saat ini</div>
                    </div>
                    <div class="stat-card menipis">
                        <i class='bx bx-time-five'></i>
                        <div class="angka"><?php echo e($total_dipinjam); ?><small>unit</small></div>
                        <div class="label">Sedang Dipinjam</div>
                        <div class="petunjuk">Peminjaman dikurangi pengembalian</div>
                    </div>
                    <div class="stat-card rusak">
                        <i class='bx bx-error-circle'></i>
                        <div class="angka"><?php echo e($total_rusak); ?><small>unit</small></div>
                        <div class="label">Keluar / Rusak</div>
                        <div class="petunjuk">Barang keluar (rusak / afkir)</div>
                    </div>
                </div>

                <div class="section-header">
                    <h3>Laporan Histori Transaksi</h3>
                    <button type="button" class="btn btn-small no-print" onclick="window.print()"><i class='bx bx-printer'></i> Cetak Laporan</button>
                </div>

                <div class="table-container">
                    <table class="tabel-laporan">
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>Nama Barang</th>
                                <th>Jenis Transaksi</th>
                                <th>Jml</th>
                                <th>Penerima / Peminjam</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($lap_transaksi)): ?>
                            <tr><td colspan="5"><?php echo renderEmptyState('bx-receipt', 'Belum ada histori transaksi', 'Riwayat akan tampil di sini setelah petugas mencatat transaksi.'); ?></td></tr>
                            <?php else: foreach ($lap_transaksi as $transaksi): ?>
                            <tr>
                                <td><?php echo e(formatTanggal($transaksi['Tanggal'])); ?></td>
                                <td><strong><?php echo e($transaksi['Nama_barang']); ?></strong><br><small style="color: var(--ink-faint);"><?php echo e($transaksi['Jenis_barang']); ?></small></td>
                                <td><span class="badge <?php echo JenisTransaksi::kelasBadge($transaksi['Jenis_transaksi']); ?>"><?php echo e($transaksi['Jenis_transaksi']); ?></span></td>
                                <td><?php echo e($transaksi['Kuantitas']); ?></td>
                                <td><?php echo e($transaksi['Nama_penerima']); ?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- TAB 2: LAPORAN STOK BARANG -->
            <div id="laporan" class="tab-content">
                <div class="section-header">
                    <h3>Laporan Stok Barang Tersedia</h3>
                    <button type="button" class="btn btn-small no-print" onclick="window.print()"><i class='bx bx-printer'></i> Cetak Laporan</button>
                </div>

                <div class="table-container" id="area-laporan-barang">
                    <table class="tabel-laporan">
                        <thead>
                            <tr>
                                <th>Kode Barang</th>
                                <th>Nama Aset</th>
                                <th>Kategori</th>
                                <th>Sumber Dana</th>
                                <th>Stok Tersedia</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($lap_barang)): ?>
                            <tr><td colspan="5"><?php echo renderEmptyState('bx-package', 'Tidak ada data barang', 'Barang akan tampil di sini setelah didaftarkan oleh petugas.'); ?></td></tr>
                            <?php else: foreach ($lap_barang as $barang): ?>
                            <tr>
                                <td><span class="kode"><?php echo e($barang['id_barang']); ?></span></td>
                                <td><strong><?php echo e($barang['Nama_barang']); ?></strong></td>
                                <td><?php echo e($barang['Jenis_barang']); ?></td>
                                <td><?php echo e($barang['Sumber_dana']); ?></td>
                                <td><span class="badge <?php echo kelasStok((int)$barang['Stok']); ?>"><?php echo e($barang['Stok']); ?> Unit</span></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

<?php
    // Pindah tab (tombol sidebar bertanda data-tab)
    $script_halaman = <<<'JS'
        var tombolTab = document.querySelectorAll('.nav-item[data-tab]');
        function bukaTab(namaTab) {
            document.querySelectorAll('.tab-content').forEach(function (t) {
                t.classList.toggle('active', t.id === namaTab);
            });
            tombolTab.forEach(function (b) {
                var aktif = b.getAttribute('data-tab') === namaTab;
                b.classList.toggle('active', aktif);
                if (aktif) b.setAttribute('aria-current', 'page'); else b.removeAttribute('aria-current');
            });
        }
        tombolTab.forEach(function (b) {
            b.addEventListener('click', function () { bukaTab(b.getAttribute('data-tab')); });
        });
JS;
    layoutEnd(['base' => '', 'script' => $script_halaman]);
?>
