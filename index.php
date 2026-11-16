<?php
    session_start();
    date_default_timezone_set('Asia/Jakarta');   // samakan dengan pendataan.php agar batas bulan akurat
    include 'koneksi.php';
    require_once __DIR__ . '/layout.php';

    // Harus login
    requireLogin();

    $nama_user = $_SESSION['nama_lengkap'] ?? $_SESSION['username'];

    // CLASS OOP

    /**
     * TransaksiFilter
     * Membungkus (enkapsulasi) parameter pencarian, filter, dan limit.
     */
    class TransaksiFilter {
        private $q;
        private $jenis;
        private $kategori;
        private $limit;

        public function __construct(array $input) {
            $this->q        = trim($input['q'] ?? '');
            $this->jenis    = $input['jenis'] ?? '';
            $this->kategori = $input['kategori'] ?? '';
            $this->limit    = max(10, min(200, (int)($input['limit'] ?? 10)));
        }

        public function getQ()        { return $this->q; }
        public function getJenis()    { return $this->jenis; }
        public function getKategori() { return $this->kategori; }
        public function getLimit()    { return $this->limit; }

        // Apakah ada pencarian/filter yang sedang dipakai?
        public function isAktif() {
            return $this->q !== '' || $this->jenis !== '' || $this->kategori !== '';
        }

        // Parameter URL (hanya yang terisi)
        public function toArray() {
            return array_filter([
                'q' => $this->q, 'jenis' => $this->jenis, 'kategori' => $this->kategori
            ], 'strlen');
        }
    }


    /**
     * DashboardManager
     * Semua query database untuk dashboard.
     */
    class DashboardManager {
        private $db;

        public function __construct($koneksi) {
            $this->db = $koneksi;
        }

        // Ambil 1 angka dari query (SUM / COUNT), default 0
        private function getScalar($sql) {
            $hasil = $this->db->query($sql);
            $baris = $hasil ? $hasil->fetch_row() : null;
            return ($baris && $baris[0]) ? $baris[0] : 0;
        }

        // Total kuantitas berdasarkan kelompok jenis transaksi (daftar berasal dari konstanta, bukan input)
        private function getTotalJenis(array $daftar_jenis) {
            $in = "'" . implode("','", $daftar_jenis) . "'";
            return $this->getScalar("SELECT SUM(d.Kuantitas)
                FROM detail_pendataan d
                JOIN pendataan_barang p ON d.Id_Transaksi = p.Id_Transaksi
                WHERE p.Jenis_transaksi IN ($in)");
        }

        public function getTotalStok()   { return $this->getScalar("SELECT SUM(Stok) FROM barang"); }
        public function getTotalMasuk()  { return $this->getTotalJenis(JenisTransaksi::KELOMPOK_MASUK); }
        public function getTotalKeluar() { return $this->getTotalJenis(JenisTransaksi::KELOMPOK_KELUAR); }
        public function getTotalRestok() { return (int)$this->getScalar("SELECT COUNT(*) FROM barang WHERE Stok < 5"); }

        // Daftar kategori untuk dropdown filter
        public function getKategori() {
            $hasil_akhir = [];
            $hasil = $this->db->query("SELECT DISTINCT Jenis_barang FROM barang ORDER BY Jenis_barang");
            while ($hasil && $baris = $hasil->fetch_row()) $hasil_akhir[] = $baris[0];
            return $hasil_akhir;
        }

        // Susun WHERE + parameter (prepared statement)
        private function buildWhere(TransaksiFilter $filter, &$tipe, &$parameter) {
            $kondisi = [];
            if ($filter->getQ() !== '') {
                $kondisi[]   = "b.Nama_barang LIKE ?";
                $tipe       .= 's';
                $parameter[] = '%' . addcslashes($filter->getQ(), '%_\\') . '%';
            }
            if ($filter->getJenis() === 'masuk' || $filter->getJenis() === 'keluar') {
                $daftar = ($filter->getJenis() === 'masuk') ? JenisTransaksi::KELOMPOK_MASUK : JenisTransaksi::KELOMPOK_KELUAR;
                $kondisi[] = "p.Jenis_transaksi IN (" . implode(',', array_fill(0, count($daftar), '?')) . ")";
                foreach ($daftar as $jenis) { $tipe .= 's'; $parameter[] = $jenis; }
            }
            if ($filter->getKategori() !== '') {
                $kondisi[]   = "b.Jenis_barang = ?";
                $tipe       .= 's';
                $parameter[] = $filter->getKategori();
            }
            return $kondisi ? 'WHERE ' . implode(' AND ', $kondisi) : '';
        }

        private function execute($sql, $tipe, $parameter) {
            $stmt = $this->db->prepare($sql);
            if (!$stmt) return false;
            if ($parameter) $stmt->bind_param($tipe, ...$parameter);
            $stmt->execute();
            return $stmt->get_result();
        }

        // Riwayat transaksi. $semua = true -> tanpa LIMIT (dipakai ekspor)
        public function getTransaksi(TransaksiFilter $filter, $semua = false) {
            $tipe = ''; $parameter = [];
            $where = $this->buildWhere($filter, $tipe, $parameter);
            $sql = "SELECT b.Nama_barang, b.Jenis_barang, b.Stok, p.Tanggal, d.Kuantitas, p.Jenis_transaksi
                    FROM detail_pendataan d
                    JOIN pendataan_barang p ON d.Id_Transaksi = p.Id_Transaksi
                    JOIN barang b ON d.id_barang = b.Id_barang
                    $where
                    ORDER BY p.Tanggal DESC, p.Id_Transaksi DESC";
            if (!$semua) $sql .= " LIMIT " . (int)$filter->getLimit();

            $data = [];
            $hasil = $this->execute($sql, $tipe, $parameter);
            while ($hasil && $baris = $hasil->fetch_assoc()) $data[] = $baris;
            return $data;
        }

        public function countTransaksi(TransaksiFilter $filter) {
            $tipe = ''; $parameter = [];
            $where = $this->buildWhere($filter, $tipe, $parameter);
            $hasil = $this->execute("SELECT COUNT(*) FROM detail_pendataan d
                    JOIN pendataan_barang p ON d.Id_Transaksi = p.Id_Transaksi
                    JOIN barang b ON d.id_barang = b.Id_barang $where", $tipe, $parameter);
            $baris = $hasil ? $hasil->fetch_row() : null;
            return $baris ? (int)$baris[0] : 0;
        }

        /**
         * Data grafik: total per bulan untuk $jumlah_bulan terakhir (termasuk bulan ini).
         *  - masuk    = KELOMPOK_MASUK (Barang Masuk + Pengembalian)
         *  - dipinjam = transaksi Peminjaman saja, dalam bulan itu
         * Bulan tanpa transaksi tetap muncul dengan nilai 0 agar garis tidak putus.
         */
        public function getTrenBulanan($jumlah_bulan = 12) {
            $jumlah_bulan = max(1, (int)$jumlah_bulan);

            // Kerangka: satu entri per bulan, dari yang terlama ke bulan ini
            $kursor = new DateTime('first day of this month');
            $kursor->modify('-' . ($jumlah_bulan - 1) . ' months');
            $awal  = $kursor->format('Y-m-d');
            $tren  = [];
            for ($i = 0; $i < $jumlah_bulan; $i++) {
                $n = (int)$kursor->format('n');
                $tren[$kursor->format('Y-m')] = [
                    'kunci'    => $kursor->format('Y-m'),
                    'nama'     => BULAN_PANJANG[$n],
                    'singkat'  => BULAN_SINGKAT[$n],
                    'tahun'    => (int)$kursor->format('Y'),
                    'masuk'    => 0,
                    'dipinjam' => 0,
                ];
                $kursor->modify('+1 month');
            }

            // Satu query untuk seluruh rentang (prepared statement)
            $daftar_masuk = JenisTransaksi::KELOMPOK_MASUK;
            $in = implode(',', array_fill(0, count($daftar_masuk), '?'));
            $sql = "SELECT DATE_FORMAT(p.Tanggal, '%Y-%m') AS bulan,
                           SUM(CASE WHEN p.Jenis_transaksi IN ($in) THEN d.Kuantitas ELSE 0 END) AS masuk,
                           SUM(CASE WHEN p.Jenis_transaksi = ? THEN d.Kuantitas ELSE 0 END) AS dipinjam
                    FROM detail_pendataan d
                    JOIN pendataan_barang p ON d.Id_Transaksi = p.Id_Transaksi
                    WHERE p.Tanggal >= ?
                    GROUP BY bulan";
            $tipe      = str_repeat('s', count($daftar_masuk)) . 'ss';
            $parameter = array_merge($daftar_masuk, [JenisTransaksi::PEMINJAMAN, $awal]);

            $hasil = $this->execute($sql, $tipe, $parameter);
            while ($hasil && $baris = $hasil->fetch_assoc()) {
                if (!isset($tren[$baris['bulan']])) continue;   // abaikan data di luar rentang (mis. tanggal masa depan)
                $tren[$baris['bulan']]['masuk']    = (int)$baris['masuk'];
                $tren[$baris['bulan']]['dipinjam'] = (int)$baris['dipinjam'];
            }
            return array_values($tren);
        }
    }


    /**
     * LaporanExporter (abstract class)
     * Kerangka laporan; tiap format (PDF/Word/Excel) mengisi sendHeader() sendiri.
     */
    abstract class LaporanExporter {
        protected $manager;
        protected $filter;
        protected $nama_user;

        public function __construct(DashboardManager $manager, TransaksiFilter $filter, $nama_user) {
            $this->manager   = $manager;
            $this->filter    = $filter;
            $this->nama_user = $nama_user;
        }

        // Wajib dibuat oleh class turunan
        abstract protected function sendHeader($nama_file);

        // Bisa di-override (dipakai PDF untuk auto print)
        protected function getScriptTambahan() { return ''; }

        public function send() {
            $this->sendHeader('laporan-inventaris-' . date('Ymd'));
            echo $this->renderHtml();
        }

        protected function renderHtml() {
            $data = $this->manager->getTransaksi($this->filter, true);
            ob_start();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Histori Transaksi</title>
    <style>
        @page { size: landscape; margin: 12mm; }
        body { font-family: Arial, sans-serif; font-size: 13px; color: #222; }
        h2 { margin: 0; padding-bottom: 10px; font-size: 24px; border-bottom: 2px solid #111; }
        .filter { margin: 8px 0 0; font-size: 12px; color: #666; }
        table { border-collapse: collapse; width: 100%; margin-top: 18px; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th, td { border: 1px solid #ddd; padding: 12px 10px; text-align: left; }
        th { font-weight: 600; color: #555; }
        td b { display: block; }
        td small { display: block; margin-top: 2px; font-size: 11px; color: #777; }
        .masuk { color: #1f8a52; }
        .pinjam { color: #a96a0a; }
        .keluar { color: #c23b2e; }
    </style>
</head>
<body>
    <h2>Laporan Histori Transaksi</h2>
    <?php if ($this->filter->isAktif()) echo '<p class="filter">Filter: ' . e(http_build_query($this->filter->toArray(), '', ', ')) . '</p>'; ?>
    <table>
        <thead>
            <tr><th>Tanggal</th><th>Nama Barang</th><th>Jenis Transaksi</th><th>Jml</th></tr>
        </thead>
        <tbody>
        <?php if (empty($data)): ?>
            <tr><td colspan="4">Tidak ada data.</td></tr>
        <?php else: foreach ($data as $baris):
            if (in_array($baris['Jenis_transaksi'], JenisTransaksi::KELOMPOK_MASUK, true)) $warna = 'masuk';
            elseif ($baris['Jenis_transaksi'] === JenisTransaksi::PEMINJAMAN)              $warna = 'pinjam';
            else                                                                           $warna = 'keluar';
        ?>
            <tr>
                <td><?php echo e(formatTanggal($baris['Tanggal'])); ?></td>
                <td><b><?php echo e($baris['Nama_barang']); ?></b><small><?php echo e($baris['Jenis_barang']); ?></small></td>
                <td class="<?php echo $warna; ?>"><?php echo e($baris['Jenis_transaksi']); ?></td>
                <td><?php echo e($baris['Kuantitas']); ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    <?php echo $this->getScriptTambahan(); ?>
</body>
</html>
<?php
            return ob_get_clean();
        }
    }

    // Turunan (inheritance + polymorphism): tiap format punya header sendiri
    class ExcelExporter extends LaporanExporter {
        protected function sendHeader($nama_file) {
            header('Content-Type: application/vnd.ms-excel; charset=utf-8');
            header("Content-Disposition: attachment; filename=$nama_file.xls");
        }
    }

    class WordExporter extends LaporanExporter {
        protected function sendHeader($nama_file) {
            header('Content-Type: application/msword; charset=utf-8');
            header("Content-Disposition: attachment; filename=$nama_file.doc");
        }
    }

    class PdfExporter extends LaporanExporter {
        protected function sendHeader($nama_file) {
            header('Content-Type: text/html; charset=utf-8');   // dibuka di tab baru, lalu print -> Save as PDF
        }
        protected function getScriptTambahan() {
            return '<script>window.onload = function () { window.print(); };</script>';
        }
    }

    // Factory: memilih class exporter sesuai format
    class ExporterFactory {
        public static function create($format, DashboardManager $manager, TransaksiFilter $filter, $nama_user) {
            switch ($format) {
                case 'excel': return new ExcelExporter($manager, $filter, $nama_user);
                case 'word':  return new WordExporter($manager, $filter, $nama_user);
                default:      return new PdfExporter($manager, $filter, $nama_user);
            }
        }
    }


    // EKSEKUSI
    $dashboard = new DashboardManager($koneksi);
    $filter    = new TransaksiFilter($_GET);

    // Ekspor laporan: index.php?export=pdf|word|excel
    if (isset($_GET['export'])) {
        ExporterFactory::create($_GET['export'], $dashboard, $filter, $nama_user)->send();
        exit();
    }

    // Variabel untuk tampilan
    $q                  = $filter->getQ();
    $jenis              = $filter->getJenis();
    $kategori           = $filter->getKategori();
    $limit              = $filter->getLimit();
    $filter_aktif       = $filter->isAktif();
    $parameter_url      = $filter->toArray();
    $query_string       = http_build_query($parameter_url);

    $tren               = $dashboard->getTrenBulanan(12);   // grafik: 12 bulan, tombol 3/6/12 memotong di browser
    $daftar_kategori    = $dashboard->getKategori();
    $transaksi_terakhir = $dashboard->getTransaksi($filter);
    $total_transaksi    = $dashboard->countTransaksi($filter);

    layoutStart(['judul' => 'Beranda', 'aktif' => 'beranda', 'base' => '']);

    $kanan_header = "<div class='header-right'>"
        . "<i class='bx bx-search' id='btnCari' role='button' tabindex='0' title='Cari transaksi' aria-label='Cari transaksi'></i>"
        . renderProfilUser($koneksi)   // foto + nama + tombol settings (lihat helpers.php)
        . "</div>";
    echo renderHeader('Ringkasan', 'pergerakan barang gudang sekolah.', $kanan_header);
?>

            <!-- Panel pencarian + filter -->
            <form class="search-panel <?php echo $filter_aktif ? 'open' : ''; ?>" id="panelCari" method="get" action="index.php#transaksi">
                <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Cari nama barang..." autocomplete="off" aria-label="Cari nama barang">
                <select name="jenis" aria-label="Jenis transaksi">
                    <option value="">Semua transaksi</option>
                    <option value="masuk"  <?php echo $jenis === 'masuk'  ? 'selected' : ''; ?>>Barang masuk</option>
                    <option value="keluar" <?php echo $jenis === 'keluar' ? 'selected' : ''; ?>>Barang keluar</option>
                </select>
                <select name="kategori" aria-label="Kategori barang">
                    <option value="">Semua kategori</option>
                    <?php foreach ($daftar_kategori as $nama_kategori): ?>
                        <option value="<?php echo e($nama_kategori); ?>" <?php echo $kategori === $nama_kategori ? 'selected' : ''; ?>><?php echo e($nama_kategori); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit">Cari</button>
                <?php if ($filter_aktif): ?><a class="reset" href="index.php#transaksi">Reset</a><?php endif; ?>
            </form>

            <!-- Bagian atas: ekspor laporan + grafik tren (tinggi kartu disamakan lewat CSS) -->
            <div class="top-grid">
                <div>
                    <div class="section-header"><h3>Ekspor Laporan</h3></div>
                    <div class="export-card">
                        <?php if ($filter_aktif): ?><span class="badge badge-dark">Filter aktif</span><?php endif; ?>
                        <div>
                            <h2><i class='bx bxs-file-export'></i> Unduh Laporan</h2>
                            <p>Stok &amp; transaksi barang<?php echo $filter_aktif ? ' (sesuai filter aktif)' : ''; ?></p>
                        </div>
                        <a class="btn" href="?export=pdf&amp;<?php echo e($query_string); ?>" target="_blank" rel="noopener"><i class='bx bx-printer'></i> Cetak Laporan</a>
                    </div>
                </div>

                <div>
                    <div class="section-header"><h3>Grafik pergerakan barang</h3></div>
                    <section class="tren-card" aria-label="Grafik tren barang masuk dan dipinjam">
                        <div class="tren-head">
                            <p id="subJudulTren">Memuat data…</p>
                            <div class="seg" role="group" aria-label="Rentang waktu grafik">
                                <button type="button" data-rentang="3" aria-pressed="false">3 bulan</button>
                                <button type="button" data-rentang="6" aria-pressed="true">6 bulan</button>
                                <button type="button" data-rentang="12" aria-pressed="false">12 bulan</button>
                            </div>
                        </div>

                        <div id="areaGrafik" class="tren-area" role="img" aria-labelledby="ringkasTren"></div>

                        <div class="tren-foot">
                            <div class="tren-legenda" aria-hidden="true">
                                <span title="Termasuk pengembalian"><i class="garis"></i>Barang masuk</span>
                                <span><i class="garis pinjam"></i>Barang dipinjam</span>
                            </div>
                            <details class="tren-data">
                                <summary><span class="s-buka">Lihat data</span><span class="s-tutup">Tutup data</span></summary>
                                <div class="tren-tabel-wrap">
                                    <table class="tren-tabel">
                                        <thead><tr><th>Bulan</th><th>Barang masuk</th><th>Barang dipinjam</th></tr></thead>
                                        <tbody id="isiTabelTren"></tbody>
                                    </table>
                                </div>
                            </details>
                        </div>

                        <p class="tren-sr" id="ringkasTren"></p>
                        <script type="application/json" id="dataTren"><?php echo json_encode($tren, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?></script>
                    </section>
                </div>
            </div>

            <!-- Transaksi terakhir -->
            <div id="transaksi">
                <div class="section-header">
                    <h3>Transaksi Terakhir</h3>
                    <span class="count"><?php echo count($transaksi_terakhir); ?> dari <?php echo $total_transaksi; ?> transaksi</span>
                </div>

                <?php if (empty($transaksi_terakhir)): ?>
                    <?php
                        if ($filter_aktif) {
                            echo renderEmptyState('bx-search-alt', 'Tidak ada transaksi yang cocok', 'Coba kata kunci lain atau hapus filter yang sedang dipakai.',
                                "<a class='btn btn-soft btn-small' href='index.php#transaksi'>Reset pencarian</a>");
                        } else {
                            echo renderEmptyState('bx-receipt', 'Belum ada riwayat transaksi', 'Transaksi yang dicatat akan muncul di sini.',
                                "<a class='btn btn-small' href='CRUD/pendataan.php'><i class='bx bx-plus'></i> Catat transaksi pertama</a>");
                        }
                    ?>
                <?php else: ?>
                    <div class="list-header">
                        <span>Nama Barang</span>
                        <span>Jenis transaksi</span>
                        <span>Kuantitas</span>
                        <span>Tgl Transaksi</span>
                    </div>

                    <?php foreach ($transaksi_terakhir as $baris):
                        $ikon = in_array($baris['Jenis_transaksi'], JenisTransaksi::KELOMPOK_MASUK, true) ? 'bx-down-arrow-alt' : 'bx-up-arrow-alt';
                    ?>
                    <div class="list-item">
                        <div class="item-name">
                            <div class="item-icon"><i class='bx <?php echo $ikon; ?>'></i></div>
                            <div class="item-name-text">
                                <strong><?php echo e($baris['Nama_barang']); ?></strong>
                                <span><?php echo e($baris['Jenis_barang']); ?></span>
                            </div>
                        </div>
                        <div class="item-val" data-label="Transaksi">
                            <span class="badge <?php echo JenisTransaksi::kelasBadge($baris['Jenis_transaksi']); ?>"><?php echo e($baris['Jenis_transaksi']); ?></span>
                        </div>
                        <div class="item-val" data-label="Kuantitas"><?php echo e($baris['Kuantitas']); ?></div>
                        <div class="item-val tanggal" data-label="Tanggal"><?php echo e(formatTanggal($baris['Tanggal'])); ?></div>
                    </div>
                    <?php endforeach; ?>

                    <?php if ($total_transaksi > count($transaksi_terakhir)): ?>
                        <a class="load-more" href="?<?php echo e(http_build_query($parameter_url + ['limit' => $limit + 10])); ?>#transaksi">Muat lebih banyak</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

<?php
    // Ikon search membuka/menutup panel pencarian
    $script_halaman = <<<'JS'
        var panel = document.getElementById('panelCari');
        var tombolCari = document.getElementById('btnCari');
        function bukaTutupCari() {
            panel.classList.toggle('open');
            if (panel.classList.contains('open')) panel.querySelector('input').focus();
        }
        tombolCari.addEventListener('click', bukaTutupCari);
        tombolCari.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); bukaTutupCari(); }
        });
JS;

    // Grafik tren (SVG murni, tanpa library)
    $script_grafik = <<<'JS'
        (function () {
            var dataEl = document.getElementById('dataTren');
            var area   = document.getElementById('areaGrafik');
            if (!dataEl || !area) return;

            var semua   = JSON.parse(dataEl.textContent);   // 12 bulan, terlama -> terbaru
            var rentang = 6;
            var fmt     = function (n) { return Number(n).toLocaleString('id-ID'); };
            var elSub   = document.getElementById('subJudulTren');
            var elRingk = document.getElementById('ringkasTren');
            var elTabel = document.getElementById('isiTabelTren');

            // Langkah sumbu-Y yang "bulat" (1, 2, 5, 10, 20, 50, ...) untuk 3 interval
            function langkahBulat(maks) {
                var kasar   = Math.max(maks, 3) / 3;
                var pangkat = Math.pow(10, Math.floor(Math.log10(kasar)));
                var f = kasar / pangkat;
                return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10) * pangkat;
            }

            function teksRentang(data) {
                var a = data[0], b = data[data.length - 1];
                return a.tahun === b.tahun
                    ? a.nama + ' – ' + b.nama + ' ' + b.tahun
                    : a.singkat + ' ' + a.tahun + ' – ' + b.singkat + ' ' + b.tahun;
            }

            function gambar() {
                var data = semua.slice(-rentang);
                var n    = data.length;

                var maks = 0;
                data.forEach(function (d) { maks = Math.max(maks, d.masuk, d.dipinjam); });
                var langkah = langkahBulat(maks);
                var puncak  = Math.ceil(maks / langkah) * langkah || langkah * 3;

                // Ukuran ringkas: tingginya menyesuaikan kartu Ekspor Laporan
                var W = Math.max(area.clientWidth, 240);
                var H = W < 420 ? 112 : 98;
                var kiri = 12 + fmt(puncak).length * 6, kanan = 8, atas = 8, bawah = 18, pad = 10;
                var lebar = W - kiri - kanan, tinggi = H - atas - bawah;

                var x = function (i) { return kiri + pad + (n === 1 ? (lebar - 2 * pad) / 2 : i * (lebar - 2 * pad) / (n - 1)); };
                var y = function (v) { return atas + tinggi - (v / puncak) * tinggi; };

                var s = '<svg class="tren-svg" viewBox="0 0 ' + W + ' ' + H + '" width="' + W + '" height="' + H + '" aria-hidden="true" focusable="false">';

                // Garis bantu + label sumbu-Y
                for (var v = 0; v <= puncak; v += langkah) {
                    s += '<line class="' + (v === 0 ? 'sumbu' : 'grid') + '" stroke="#e7e4dc" x1="' + kiri + '" x2="' + (W - kanan) + '" y1="' + y(v) + '" y2="' + y(v) + '"/>';
                    s += '<text font-size="10" fill="#8b91a0" x="' + (kiri - 7) + '" y="' + (y(v) + 3.5) + '" text-anchor="end">' + fmt(v) + '</text>';
                }

                // Label sumbu-X (dijarangkan bila sempit)
                var jarak  = n > 1 ? (lebar - 2 * pad) / (n - 1) : lebar;
                var lompat = Math.max(1, Math.ceil(30 / jarak));
                data.forEach(function (d, i) {
                    if ((n - 1 - i) % lompat !== 0) return;
                    s += '<text font-size="10" fill="#8b91a0" x="' + x(i) + '" y="' + (H - 4) + '" text-anchor="middle">' + d.singkat + '</text>';
                });

                // Garis + titik. Atribut fill/stroke dipasang juga di sini sebagai cadangan
                // bila CSS belum termuat, agar garis tidak pernah terisi hitam.
                function garis(kunci, kelasGaris, kelasTitik, warna, putus, namaSeri) {
                    var pts = data.map(function (d, i) { return x(i) + ',' + y(d[kunci]); }).join(' ');
                    var out = '<polyline class="' + kelasGaris + '" fill="none" stroke="' + warna + '" stroke-width="2"' +
                              (putus ? ' stroke-dasharray="5 4"' : '') + ' points="' + pts + '"/>';
                    data.forEach(function (d, i) {
                        out += '<circle class="' + kelasTitik + '" fill="#fff" stroke="' + warna + '" stroke-width="1.6" cx="' + x(i) + '" cy="' + y(d[kunci]) + '" r="3"><title>' +
                               d.nama + ' ' + d.tahun + ': ' + fmt(d[kunci]) + ' unit ' + namaSeri + '</title></circle>';
                    });
                    return out;
                }
                s += garis('masuk',    'g-masuk',  't-masuk',  '#1f8a52', false, 'masuk');
                s += garis('dipinjam', 'g-pinjam', 't-pinjam', '#a96a0a', true,  'dipinjam');
                s += '</svg>';
                area.innerHTML = s;

                // Subjudul, ringkasan (dibaca pembaca layar), dan tabel data
                var rentangTeks = teksRentang(data);
                var totMasuk = 0, totPinjam = 0, bulanPuncak = null;
                data.forEach(function (d) {
                    totMasuk += d.masuk; totPinjam += d.dipinjam;
                    if (d.masuk > 0 && (!bulanPuncak || d.masuk > bulanPuncak.masuk)) bulanPuncak = d;
                });

                elSub.textContent = rentangTeks + ' · satuan unit';
                if (totMasuk === 0 && totPinjam === 0) {
                    elRingk.textContent = 'Belum ada barang masuk atau peminjaman pada ' + rentangTeks + '.';
                } else {
                    elRingk.textContent = 'Total ' + fmt(totMasuk) + ' unit masuk dan ' + fmt(totPinjam) +
                        ' unit dipinjam pada ' + rentangTeks +
                        (bulanPuncak ? '; puncak penerimaan terjadi pada ' + bulanPuncak.nama + ' (' + fmt(bulanPuncak.masuk) + ' unit).' : '.');
                }

                elTabel.innerHTML = data.map(function (d) {
                    return '<tr><td>' + d.nama + ' ' + d.tahun + '</td><td class="angka">' + fmt(d.masuk) + '</td><td class="angka">' + fmt(d.dipinjam) + '</td></tr>';
                }).join('') +
                '<tr class="total"><td>Total</td><td class="angka">' + fmt(totMasuk) + '</td><td class="angka">' + fmt(totPinjam) + '</td></tr>';
            }

            // Tombol 3 / 6 / 12 bulan
            var tombol = document.querySelectorAll('.seg [data-rentang]');
            tombol.forEach(function (t) {
                t.addEventListener('click', function () {
                    rentang = parseInt(t.getAttribute('data-rentang'), 10);
                    tombol.forEach(function (o) { o.setAttribute('aria-pressed', o === t ? 'true' : 'false'); });
                    gambar();
                });
            });

            // Gambar ulang saat lebar kontainer berubah (rotasi HP, resize jendela)
            var lebarTerakhir = 0;
            function cekLebar() {
                var w = area.clientWidth;
                if (Math.abs(w - lebarTerakhir) < 2) return;
                lebarTerakhir = w;
                gambar();
            }
            if (window.ResizeObserver) { new ResizeObserver(cekLebar).observe(area); }
            else { window.addEventListener('resize', cekLebar); }
            cekLebar();
            if (!lebarTerakhir) gambar();
        })();

JS;
    layoutEnd(['base' => '', 'script' => $script_halaman . "\n" . $script_grafik]);
?>