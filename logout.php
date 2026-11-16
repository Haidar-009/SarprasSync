<?php
/*
 * logout.php (gabungan: proses logout + pop-up konfirmasi)
 *
 * 1. Dibuka langsung (klik "Ya, Keluar" -> logout.php): menghancurkan sesi lalu ke login.
 * 2. Di-include oleh layout.php: hanya menampilkan pop-up konfirmasi.
 *    (CSS pop-up ada di assets/style.css, bagian "Pop-up konfirmasi logout")
 */

    // Mode 1: file ini dibuka langsung lewat URL -> proses logout
    if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])) {
        session_start();
        $_SESSION = [];
        session_destroy();
        header("Location: login.php?logout=1");
        exit();
    }

// Mode 2: file ini di-include -> tampilkan pop-up (HTML di bawah)
?>

<div class="lo-overlay" id="loOverlay" role="dialog" aria-modal="true"
     aria-labelledby="loJudul" aria-describedby="loIsi" aria-hidden="true">

    <div class="lo-box">

        <div class="lo-icon"><i class='bx bx-log-out'></i></div>
        <h3 id="loJudul">Keluar dari akun?</h3>
        <p id="loIsi">Anda akan keluar dari Sistem Inventaris. Pastikan semua perubahan sudah tersimpan.</p>

        <div class="lo-actions">
            <button type="button" class="lo-batal" id="loBatal">Batal</button>
            <button type="button" class="lo-ya" id="loYa">Ya, Keluar</button>
        </div>

    </div>
</div>

<script>
    (function () {
        var overlay = document.getElementById('loOverlay');
        var btnBatal = document.getElementById('loBatal');
        var btnYa = document.getElementById('loYa');
        var tujuan = 'logout.php';
        var pemicu = null;

        function buka(href, el) {
            tujuan = href || tujuan;
            pemicu = el;
            overlay.classList.add('show');
            overlay.setAttribute('aria-hidden', 'false');
            btnBatal.focus();
        }

        function tutup(kembalikanFokus) {
            overlay.classList.remove('show');
            overlay.setAttribute('aria-hidden', 'true');
            if (kembalikanFokus !== false && pemicu) pemicu.focus();
        }

        // Klik pada link logout -> tampilkan pop-up, bukan langsung pindah halaman
        document.addEventListener('click', function (e) {
            var a = e.target.closest('[data-logout]');
            if (!a) return;
            e.preventDefault();
            buka(a.getAttribute('href'), a);
        });

        // Klik area gelap di luar kartu = batal
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) tutup();
        });
        btnBatal.addEventListener('click', function () { tutup(); });

        btnYa.addEventListener('click', function () {
            btnYa.disabled = true;
            btnYa.textContent = 'Keluar...';
            window.location.href = tujuan;
        });

        // Keyboard: Esc menutup, Tab berputar di antara dua tombol
        document.addEventListener('keydown', function (e) {
            if (!overlay.classList.contains('show')) return;
            if (e.key === 'Escape') { tutup(); return; }
            if (e.key === 'Tab') {
                if (e.shiftKey && document.activeElement === btnBatal) { e.preventDefault(); btnYa.focus(); }
                else if (!e.shiftKey && document.activeElement === btnYa) { e.preventDefault(); btnBatal.focus(); }
            }
        });

        // Jika pengguna menekan tombol Back dan halaman dipulihkan dari cache
        window.addEventListener('pageshow', function (e) {
            if (e.persisted) {
                btnYa.disabled = false;
                btnYa.textContent = 'Ya, Keluar';
                tutup(false);
            }
        });
    })();
</script>