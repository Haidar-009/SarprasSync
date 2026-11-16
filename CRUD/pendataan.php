<?php
    session_start();
    include '../koneksi.php';
    require_once __DIR__ . '/../layout.php';

    // Hanya untuk pengguna yang sudah login
    requireLogin('../');

    date_default_timezone_set('Asia/Jakarta');

    $userManager      = new UserManager($koneksi);
    $transaksiManager = new TransaksiManager($koneksi);

    // Data petugas yang sedang bertugas (otomatis dari sesi)
    $username_aktif = $_SESSION['username'];
    $id_petugas     = $userManager->findIdPetugas($username_aktif);
    $nama_petugas   = $_SESSION['nama_lengkap'] ?? $username_aktif;
    $tanggal_tampil = formatTanggal(time(), true);

    // Isian lama: dipakai agar form tidak kosong lagi jika penyimpanan ditolak
    // (foto tidak bisa diisi ulang oleh server, jadi harus dipilih lagi)
    $isian_lama = [];

    // PROSES SIMPAN TRANSAKSI
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_transaksi'])) {
        if (!csrfOk()) {
            setFlash('err', 'Sesi formulir tidak valid, atau total ukuran foto melebihi batas server. Silakan muat ulang halaman.');
        } elseif ($id_petugas === 0) {
            setFlash('err', 'Akun petugas tidak ditemukan. Silakan login ulang.');
        } else {
            // Seluruh validasi, cek stok, dan penyimpanan ada di TransaksiManager::addTransaksi()
            $error = $transaksiManager->addTransaksi($id_petugas, $_POST, FotoManager::rapikan($_FILES['foto'] ?? []));

            if ($error === null) {
                $jumlah_barang = is_array($_POST['items'] ?? null) ? count($_POST['items']) : 0;
                setFlash('ok', 'Transaksi ' . (string)$_POST['jenis_transaksi'] . ' berhasil disimpan (' . $jumlah_barang . ' barang).');
                header('Location: ../index.php');
                exit();
            }
            setFlash('err', $error);
            $isian_lama = $_POST;
        }
    }

    function nilaiLama(array $isian, string $kunci): string
    {
        return e($isian[$kunci] ?? '');
    }

    $daftar_barang   = $transaksiManager->getDaftarBarang();
    $pinjaman_aktif  = $transaksiManager->getPinjamanAktif();

    // Ubah nama file foto kondisi awal menjadi alamat gambar (halaman ini ada di subfolder, jadi '../')
    foreach ($pinjaman_aktif as &$peminjam_aktif) {
        foreach ($peminjam_aktif['barang'] as &$brg_aktif) {
            $brg_aktif['foto_url'] = FotoManager::url($brg_aktif['foto_awal'], '../');
            unset($brg_aktif['foto_awal']);
        }
        unset($brg_aktif);
    }
    unset($peminjam_aktif);

    // Barang yang sempat diisi (untuk dipulihkan oleh JavaScript jika penyimpanan ditolak)
    $items_lama = array_values(array_filter(
        is_array($isian_lama['items'] ?? null) ? $isian_lama['items'] : [],
        'is_array'
    ));

    layoutStart(['judul' => 'Transaksi Barang', 'aktif' => 'transaksi', 'base' => '../']);
    echo renderHeader('Pendataan / Transaksi Barang', 'Catat pergerakan barang masuk, keluar, maupun peminjaman.');
?>

            <div class="form-container">
                <form action="" method="POST" enctype="multipart/form-data" id="form_transaksi" data-loading>
                    <?php echo csrfField(); ?>

                    <!-- INFO OTOMATIS -->
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Petugas Bertugas</label>
                            <input type="text" class="form-control" value="<?php echo e($nama_petugas); ?> (otomatis)" readonly>
                        </div>
                        <div class="form-group">
                            <label>Tanggal Transaksi</label>
                            <input type="text" class="form-control" value="<?php echo e($tanggal_tampil); ?>" readonly>
                        </div>
                    </div>

                    <hr class="form-sep">

                    <!-- JENIS TRANSAKSI -->
                    <div class="form-group">
                        <label for="jenis_transaksi">Pilih Jenis Transaksi</label>
                        <?php $jenis_lama = $isian_lama['jenis_transaksi'] ?? ''; ?>
                        <select name="jenis_transaksi" id="jenis_transaksi" class="form-control" required>
                            <option value="" disabled <?php echo $jenis_lama === '' ? 'selected' : ''; ?>>-- Pilih Jenis --</option>
                            <option value="<?php echo JenisTransaksi::PEMINJAMAN; ?>" <?php echo $jenis_lama === JenisTransaksi::PEMINJAMAN ? 'selected' : ''; ?>>1. Peminjaman</option>
                            <option value="<?php echo JenisTransaksi::PENGEMBALIAN; ?>" <?php echo $jenis_lama === JenisTransaksi::PENGEMBALIAN ? 'selected' : ''; ?>>2. Pengembalian</option>
                            <option value="<?php echo JenisTransaksi::BARANG_MASUK; ?>" <?php echo $jenis_lama === JenisTransaksi::BARANG_MASUK ? 'selected' : ''; ?>>3. Barang Masuk (hibah / beli)</option>
                            <option value="<?php echo JenisTransaksi::BARANG_KELUAR; ?>" <?php echo $jenis_lama === JenisTransaksi::BARANG_KELUAR ? 'selected' : ''; ?>>4. Barang Keluar (rusak / afkir / hilang)</option>
                        </select>
                    </div>

                    <!-- NAMA PEMINJAM (Peminjaman & Pengembalian) — satu nama untuk seluruh barang -->
                    <div class="form-group" id="form_penerima" style="display: none;">
                        <label for="input_penerima">Nama Peminjam</label>
                        <input type="text" name="nama_penerima" id="input_penerima" class="form-control" placeholder="Masukkan nama peminjam..." autocomplete="off" value="<?php echo nilaiLama($isian_lama, 'nama_penerima'); ?>">
                        <!-- Saran nama peminjam yang masih punya pinjaman (aktif saat Pengembalian) -->
                        <datalist id="daftar_peminjam">
                            <?php foreach ($pinjaman_aktif as $peminjam): ?>
                                <option value="<?php echo e($peminjam['nama']); ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <small id="info_pengembalian" class="info-box" style="display: none;"></small>
                    </div>

                    <hr class="form-sep">

                    <?php if (empty($daftar_barang)): ?>
                        <p class="form-hint">Belum ada barang di gudang. Pilih jenis "Barang Masuk" untuk mendaftarkan barang pertama.</p>
                    <?php endif; ?>

                    <!-- DAFTAR BARANG: baris-barisnya dibuat oleh JavaScript dari <template> di bawah -->
                    <div id="daftar_baris"></div>

                    <div id="aksi_tambah" style="display: none;">
                        <button type="button" class="btn-tambah" id="btn_tambah">+ Tambah Barang</button>
                    </div>

                    <button type="submit" name="simpan_transaksi" class="btn btn-block">Simpan Transaksi</button>
                </form>

                <!-- ====== CETAKAN BARIS (tidak ikut terkirim; __i__ diganti nomor unik oleh JavaScript) ====== -->

                <!-- Peminjaman & Barang Keluar: pilih barang yang sudah ada -->
                <template id="tpl_baris_pilih">
                    <div class="baris-barang" data-baris>
                        <div class="baris-kepala">
                            <strong class="baris-no">Barang 1</strong>
                            <button type="button" class="baris-hapus">Hapus</button>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Pilih Barang (dari database)</label>
                                <select name="items[__i__][id_barang]" class="form-control" data-f="id_barang" required>
                                    <option value="" disabled selected>-- Pilih Barang --</option>
                                    <?php foreach ($daftar_barang as $barang): ?>
                                        <option value="<?php echo e($barang['id_barang']); ?>" data-stok="<?php echo (int)$barang['Stok']; ?>">
                                            <?php echo e($barang['id_barang'] . ' - ' . $barang['Nama_barang'] . ' (Stok: ' . $barang['Stok'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Kuantitas / Jumlah</label>
                                <input type="number" name="items[__i__][kuantitas]" class="form-control" data-f="kuantitas" min="1" step="1" placeholder="Misal: 5" required>
                            </div>
                        </div>
                        <div class="form-group" data-f="grup_alasan" style="display: none; max-width: 320px;">
                            <label>Alasan Barang Keluar</label>
                            <select name="items[__i__][alasan]" class="form-control" data-f="alasan">
                                <option value="" disabled selected>-- Pilih Alasan --</option>
                                <?php foreach (JenisTransaksi::ALASAN_KELUAR as $nilai => $label): ?>
                                    <option value="<?php echo e($nilai); ?>"><?php echo e($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label data-f="label_foto">Foto Kondisi Barang</label>
                            <input type="file" name="foto[__i__]" class="form-control" data-f="foto" accept="image/jpeg,image/png,image/webp">
                            <div class="foto-box" data-f="preview" style="display: none;"></div>
                        </div>
                    </div>
                </template>

                <!-- Barang Masuk: barang baru -->
                <template id="tpl_baris_masuk">
                    <div class="baris-barang" data-baris>
                        <div class="baris-kepala">
                            <strong class="baris-no">Barang 1</strong>
                            <button type="button" class="baris-hapus">Hapus</button>
                        </div>
                        <div class="form-group">
                            <label>Nama Barang / Aset</label>
                            <input type="text" name="items[__i__][nama_barang]" class="form-control" data-f="nama_barang" placeholder="Masukkan nama barang..." required>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Kategori / Jenis Barang</label>
                                <select name="items[__i__][kategori]" class="form-control" data-f="kategori" required>
                                    <option value="" disabled selected>-- Pilih Kategori --</option>
                                    <?php foreach (BarangManager::KATEGORI as $nilai => $label): ?>
                                        <option value="<?php echo e($nilai); ?>"><?php echo e($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Sumber Dana</label>
                                <select name="items[__i__][sumber_dana]" class="form-control" data-f="sumber_dana" required>
                                    <option value="" disabled selected>-- Sumber Pendanaan --</option>
                                    <?php foreach (BarangManager::SUMBER_DANA as $nilai => $label): ?>
                                        <option value="<?php echo e($nilai); ?>"><?php echo e($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group" style="max-width: 220px;">
                            <label>Kuantitas / Jumlah</label>
                            <input type="number" name="items[__i__][kuantitas]" class="form-control" data-f="kuantitas" min="1" step="1" placeholder="Misal: 5" required>
                        </div>
                        <div class="form-group">
                            <label data-f="label_foto">Foto Kondisi Barang</label>
                            <input type="file" name="foto[__i__]" class="form-control" data-f="foto" accept="image/jpeg,image/png,image/webp">
                            <div class="foto-box" data-f="preview" style="display: none;"></div>
                        </div>
                    </div>
                </template>

                <!-- Pengembalian: satu baris per barang yang sedang dipinjam orang tersebut -->
                <template id="tpl_baris_kembali">
                    <div class="baris-barang baris-kembali" data-baris>
                        <label class="baris-centang">
                            <input type="checkbox" data-f="centang">
                            <strong data-f="nama"></strong>
                            <span class="baris-sisa" data-f="sisa"></span>
                        </label>
                        <div data-bagian="isi">
                            <input type="hidden" name="items[__i__][id_barang]" data-f="id_barang">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Jumlah Dikembalikan</label>
                                    <input type="number" name="items[__i__][kuantitas]" class="form-control" data-f="kuantitas" min="1" step="1" required>
                                </div>
                                <div class="form-group">
                                    <label>Foto Kondisi Awal (saat dipinjam)</label>
                                    <div class="foto-box foto-terkunci" data-f="foto_awal"></div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Foto Kondisi Setelah Dikembalikan (wajib)</label>
                                <input type="file" name="foto[__i__]" class="form-control" data-f="foto" accept="image/jpeg,image/png,image/webp" required>
                                <div class="foto-box" data-f="preview" style="display: none;"></div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

<?php
    // JavaScript form: membuat baris barang sesuai jenis transaksi, mengisi daftar pengembalian
    // dari nama peminjam, dan mengecilkan foto di browser sebelum dikirim.
    $flag_json = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
    $script_halaman =
        "var DAFTAR_PINJAMAN = " . json_encode(array_values($pinjaman_aktif), $flag_json) . ";\n" .
        "var ITEMS_LAMA = " . json_encode($items_lama, $flag_json) . ";\n" .
        <<<'JS'
        var JENIS_PEMINJAMAN = "Peminjaman";
        var JENIS_PENGEMBALIAN = "Pengembalian";
        var JENIS_MASUK = "Barang Masuk";
        var JENIS_KELUAR = "Barang Keluar";
        var MAKS_BARANG = 20;
        var MAKS_BYTE_FOTO = 8 * 1024 * 1024;
        var SISI_FOTO = 1280;

        // Label & aturan foto per jenis (untuk baris Peminjaman / Barang Masuk / Barang Keluar)
        var ATURAN_FOTO = {};
        ATURAN_FOTO[JENIS_PEMINJAMAN] = { label: "Foto Kondisi Awal Barang (wajib)", wajib: true };
        ATURAN_FOTO[JENIS_MASUK]      = { label: "Foto Kondisi Barang (wajib)", wajib: true };
        ATURAN_FOTO[JENIS_KELUAR]     = { label: "Foto Kondisi Barang (opsional)", wajib: false };

        function byId(id) { return document.getElementById(id); }
        function cari(baris, f) { return baris.querySelector('[data-f="' + f + '"]'); }

        var form      = byId("form_transaksi");
        var selJenis  = byId("jenis_transaksi");
        var inpNama   = byId("input_penerima");
        var info      = byId("info_pengembalian");
        var daftar    = byId("daftar_baris");
        var btnTambah = byId("btn_tambah");
        var counter   = 0;   // nomor unik tiap baris (dipakai pada name="items[n][...]" dan name="foto[n]")

        var dataPinjam = {};
        DAFTAR_PINJAMAN.forEach(function (p) { dataPinjam[p.nama.trim().toLowerCase()] = p; });
        var pinjamanTerpilih = null;

        function jenisAktif() { return selJenis.value; }

        function tampilkanInfo(teks, kosong) {
            info.className = kosong ? "info-box kosong" : "info-box";
            info.textContent = teks;
            info.style.display = "block";
        }

        // Buat satu baris dari <template>, mengganti __i__ dengan nomor unik
        function buatBaris(idTemplate) {
            var html = byId(idTemplate).innerHTML.replace(/__i__/g, counter++);
            var wadah = document.createElement("div");
            wadah.innerHTML = html.trim();
            return wadah.firstElementChild;
        }

        function nomori() {
            var semua = daftar.querySelectorAll("[data-baris]");
            semua.forEach(function (b, n) {
                var no = b.querySelector(".baris-no");
                if (no) no.textContent = "Barang " + (n + 1);
                var hapus = b.querySelector(".baris-hapus");
                if (hapus) hapus.disabled = (semua.length === 1);   // minimal satu baris
            });
            btnTambah.disabled = (semua.length >= MAKS_BARANG);
        }

        // Barang yang sudah dipilih di satu baris tidak bisa dipilih lagi di baris lain
        function sinkronPilihan() {
            var terpakai = {};
            var daftarSelect = daftar.querySelectorAll('select[data-f="id_barang"]');
            daftarSelect.forEach(function (s) { if (s.value) terpakai[s.value] = s; });
            daftarSelect.forEach(function (s) {
                for (var i = 0; i < s.options.length; i++) {
                    var o = s.options[i];
                    o.disabled = (o.value === "") || ((o.value in terpakai) && terpakai[o.value] !== s);
                }
            });
        }

        // Kecilkan foto di browser (maks 1280 px, JPEG) agar total unggahan banyak foto tidak melebihi batas server.
        // Jika browser tidak mendukung, foto asli tetap dikirim dan server yang mengecilkannya.
        function kecilkanFoto(input) {
            var berkas = input.files[0];
            if (!berkas || !window.DataTransfer || !window.createImageBitmap) return Promise.resolve();
            return createImageBitmap(berkas).then(function (bmp) {
                var skala = Math.min(1, SISI_FOTO / Math.max(bmp.width, bmp.height));
                var kanvas = document.createElement("canvas");
                kanvas.width = Math.max(1, Math.round(bmp.width * skala));
                kanvas.height = Math.max(1, Math.round(bmp.height * skala));
                var ctx = kanvas.getContext("2d");
                ctx.fillStyle = "#ffffff";
                ctx.fillRect(0, 0, kanvas.width, kanvas.height);
                ctx.drawImage(bmp, 0, 0, kanvas.width, kanvas.height);
                return new Promise(function (selesai) {
                    kanvas.toBlob(function (blob) {
                        if (blob) {
                            var dt = new DataTransfer();
                            dt.items.add(new File([blob], "foto.jpg", { type: "image/jpeg" }));
                            input.files = dt.files;
                        }
                        selesai();
                    }, "image/jpeg", 0.85);
                });
            }).catch(function () { /* biarkan foto asli */ });
        }

        // Pratinjau + pengecilan untuk input foto sebuah baris
        function pasangFoto(baris) {
            var inp = cari(baris, "foto");
            var pr  = cari(baris, "preview");
            if (!inp) return;
            inp.addEventListener("change", function () {
                pr.innerHTML = "";
                pr.style.display = "none";
                if (!inp.files[0]) return;
                kecilkanFoto(inp).then(function () {
                    var f = inp.files[0];
                    if (!f) return;
                    if (f.size > MAKS_BYTE_FOTO) {
                        alert("Ukuran foto lebih dari 8 MB. Pilih foto lain.");
                        inp.value = "";
                        return;
                    }
                    var img = document.createElement("img");
                    img.src = URL.createObjectURL(f);
                    img.alt = "Pratinjau foto";
                    pr.appendChild(img);
                    pr.style.display = "block";
                });
            });
        }

        // ===== Tambah satu baris barang (Peminjaman / Barang Masuk / Barang Keluar) =====
        function tambahBaris(nilai) {
            var jenis = jenisAktif();
            if (!jenis || jenis === JENIS_PENGEMBALIAN) return null;
            if (daftar.querySelectorAll("[data-baris]").length >= MAKS_BARANG) return null;

            var baris  = buatBaris(jenis === JENIS_MASUK ? "tpl_baris_masuk" : "tpl_baris_pilih");
            var aturan = ATURAN_FOTO[jenis];
            cari(baris, "label_foto").textContent = aturan.label;
            cari(baris, "foto").required = aturan.wajib;

            var grupAlasan = cari(baris, "grup_alasan");
            if (grupAlasan) {
                var tampil = (jenis === JENIS_KELUAR);
                grupAlasan.style.display = tampil ? "block" : "none";
                cari(baris, "alasan").required = tampil;
            }

            baris.querySelector(".baris-hapus").addEventListener("click", function () {
                daftar.removeChild(baris);
                nomori();
                sinkronPilihan();
            });

            var sel = cari(baris, "id_barang");
            if (sel) {
                sel.addEventListener("change", function () {
                    var o = sel.options[sel.selectedIndex];
                    var q = cari(baris, "kuantitas");
                    if (o && o.getAttribute("data-stok")) q.max = o.getAttribute("data-stok");
                    sinkronPilihan();
                });
            }
            pasangFoto(baris);
            daftar.appendChild(baris);

            if (nilai) {   // pulihkan isian jika penyimpanan sebelumnya ditolak
                ["id_barang", "kuantitas", "alasan", "nama_barang", "kategori", "sumber_dana"].forEach(function (k) {
                    var el = cari(baris, k);
                    if (el && nilai[k] !== undefined) el.value = nilai[k];
                });
                if (sel) sel.dispatchEvent(new Event("change"));
            }
            nomori();
            sinkronPilihan();
            return baris;
        }

        // ===== PENGEMBALIAN: daftar semua barang yang sedang dipinjam, petugas mencentang yang dikembalikan =====
        function bangunDaftarPengembalian(data, lama) {
            daftar.innerHTML = "";
            var tunggal = (data.barang.length === 1);

            data.barang.forEach(function (b) {
                var baris = buatBaris("tpl_baris_kembali");
                cari(baris, "nama").textContent = b.nama;
                cari(baris, "sisa").textContent = "(dipinjam: " + b.sisa + " unit)";
                cari(baris, "id_barang").value = b.id;

                var qty = cari(baris, "kuantitas");
                qty.max = b.sisa;
                qty.value = b.sisa;

                // Foto kondisi awal: hanya ditampilkan, tidak ada input apa pun yang bisa mengubahnya
                var kotak = cari(baris, "foto_awal");
                if (b.foto_url) {
                    var img = document.createElement("img");
                    img.src = b.foto_url;
                    img.alt = "Foto kondisi awal " + b.nama;
                    kotak.appendChild(img);
                } else {
                    kotak.className = "foto-box foto-terkunci foto-kosong";
                    kotak.textContent = "Tidak ada foto kondisi awal (peminjaman ini dicatat sebelum fitur foto ada).";
                }

                // Baris yang tidak dicentang dinonaktifkan sehingga tidak ikut terkirim
                var centang = cari(baris, "centang");
                function aturBaris() {
                    var aktif = centang.checked;
                    baris.classList.toggle("mati", !aktif);
                    ["id_barang", "kuantitas", "foto"].forEach(function (k) { cari(baris, k).disabled = !aktif; });
                }
                centang.addEventListener("change", aturBaris);

                var dipilihLama = lama.filter(function (x) { return x.id_barang === b.id; })[0];
                centang.checked = tunggal || !!dipilihLama;
                if (dipilihLama && dipilihLama.kuantitas) qty.value = dipilihLama.kuantitas;
                aturBaris();

                pasangFoto(baris);
                daftar.appendChild(baris);
            });
        }

        function aturModePengembalian(peringatan, lama) {
            if (jenisAktif() !== JENIS_PENGEMBALIAN) return;
            var nama = inpNama.value.trim().toLowerCase();
            var data = (nama !== "" && dataPinjam[nama]) ? dataPinjam[nama] : null;

            if (data !== pinjamanTerpilih) {   // daftar hanya dibangun ulang jika peminjamnya berganti
                pinjamanTerpilih = data;
                daftar.innerHTML = "";
                if (data) bangunDaftarPengembalian(data, lama || []);
            }

            if (data) {
                var ringkas = data.barang.map(function (b) { return b.nama + " (" + b.sisa + ")"; }).join(", ");
                tampilkanInfo("Sedang meminjam: " + ringkas + ". Centang barang yang dikembalikan.", false);
            } else if (peringatan && nama !== "") {
                tampilkanInfo("Tidak ada pinjaman aktif atas nama ini.", true);
            } else {
                info.style.display = "none";
            }
        }

        // ===== Ganti jenis transaksi: bangun ulang seluruh daftar =====
        function gantiJenis(pakaiLama) {
            var jenis = jenisAktif();
            var pakaiPeminjam = (jenis === JENIS_PEMINJAMAN || jenis === JENIS_PENGEMBALIAN);

            byId("form_penerima").style.display = pakaiPeminjam ? "block" : "none";
            inpNama.required = pakaiPeminjam;
            if (jenis === JENIS_PENGEMBALIAN) inpNama.setAttribute("list", "daftar_peminjam");
            else inpNama.removeAttribute("list");

            info.style.display = "none";
            daftar.innerHTML = "";
            pinjamanTerpilih = null;
            byId("aksi_tambah").style.display = (jenis && jenis !== JENIS_PENGEMBALIAN) ? "block" : "none";
            if (!jenis) return;

            if (jenis === JENIS_PENGEMBALIAN) {
                aturModePengembalian(false, pakaiLama ? ITEMS_LAMA : []);
                return;
            }
            if (pakaiLama && ITEMS_LAMA.length) {
                ITEMS_LAMA.forEach(function (it) { tambahBaris(it); });
            } else {
                tambahBaris();
            }
        }

        selJenis.addEventListener("change", function () { gantiJenis(false); });
        btnTambah.addEventListener("click", function () { tambahBaris(); });
        inpNama.addEventListener("input",  function () { aturModePengembalian(false); });
        inpNama.addEventListener("change", function () { aturModePengembalian(true); });

        // Pengembalian wajib mencentang minimal satu barang.
        // stopPropagation: agar app.js tidak menandai form "sedang mengirim" padahal dibatalkan.
        form.addEventListener("submit", function (e) {
            if (jenisAktif() === JENIS_PENGEMBALIAN &&
                daftar.querySelectorAll('[data-f="centang"]:checked').length === 0) {
                e.preventDefault();
                e.stopPropagation();
                tampilkanInfo("Centang minimal satu barang yang dikembalikan.", true);
            }
        });

        // Keadaan awal (juga dipakai saat form ditampilkan ulang setelah gagal disimpan)
        gantiJenis(true);
JS;
    layoutEnd(['base' => '../', 'script' => $script_halaman]);
?>