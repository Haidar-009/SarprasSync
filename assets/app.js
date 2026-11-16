/* app.js — perilaku bersama semua halaman (dimuat dari layout.php) */

(function () {
    'use strict';

    var konten = document.getElementById('konten');
    var bar = document.getElementById('loadBar');

    /* 1. Pindah halaman: isi memudar + bilah progres tipis di atas layar.
          Tautan boleh mengecualikan diri dengan atribut data-no-fade. */
    document.addEventListener('click', function (e) {
        var a = e.target.closest('a');
        if (!a || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey) return;
        var href = a.getAttribute('href');
        if (!href || href.charAt(0) === '#') return;
        if (a.target === '_blank' || a.hasAttribute('download') ||
            a.hasAttribute('onclick') || a.hasAttribute('data-no-fade')) return;
        if (a.origin !== location.origin) return;
        e.preventDefault();
        if (konten) konten.classList.add('fade-out');
        if (bar) bar.classList.add('run');
        setTimeout(function () { location.href = a.href; }, 220);
    });

    /* 2. Form: tombol simpan menampilkan spinner dan mencegah kirim ganda.
          Aktif untuk <form data-loading>. Tombol sengaja TIDAK di-disable agar
          nilai name="..." tombol tetap ikut terkirim ke server. */
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.hasAttribute('data-loading')) return;
        if (form.dataset.sending === '1') { e.preventDefault(); return; }
        form.dataset.sending = '1';
        var tombol = form.querySelector('button[type="submit"]');
        if (tombol) tombol.classList.add('is-loading');
        if (bar) bar.classList.add('run');
    });

    /* 3. Pulihkan keadaan jika halaman dibuka lagi lewat tombol Back (cache) */
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        if (konten) konten.classList.remove('fade-out');
        if (bar) bar.classList.remove('run');
        document.querySelectorAll('form[data-sending]').forEach(function (f) { delete f.dataset.sending; });
        document.querySelectorAll('.is-loading').forEach(function (t) { t.classList.remove('is-loading'); });
    });

    /* 4. Toast hilang sendiri setelah beberapa detik (bisa juga diklik) */
    function tutupToast(t) {
        t.classList.add('keluar');
        setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 320);
    }
    document.querySelectorAll('.toast').forEach(function (t) {
        setTimeout(function () { tutupToast(t); }, t.classList.contains('err') ? 7000 : 4500);
        t.addEventListener('click', function () { tutupToast(t); });
    });

    /* 5. Foto profil: buka/tutup dialog dan tampilkan pratinjau foto yang dipilih.
          Pemeriksaan format & ukuran yang sebenarnya dilakukan di server (UserManager::simpanFoto). */
    var dlgFoto = document.getElementById('dlgFoto');
    if (dlgFoto) {
        var pratinjau = document.getElementById('fotoPreview');
        var tombolSimpan = document.getElementById('btnSimpanFoto');
        var isiAwal = pratinjau.innerHTML;

        document.getElementById('btnFotoSetting').addEventListener('click', function () { dlgFoto.showModal(); });
        document.getElementById('btnTutupFoto').addEventListener('click', function () { dlgFoto.close(); });

        // Dialog ditutup -> kembalikan ke keadaan awal
        dlgFoto.addEventListener('close', function () {
            document.getElementById('formFoto').reset();
            pratinjau.innerHTML = isiAwal;
            tombolSimpan.disabled = true;
        });

        // File dipilih -> tampilkan pratinjau dan aktifkan tombol Simpan
        document.getElementById('inputFoto').addEventListener('change', function () {
            var berkas = this.files[0];
            if (!berkas) return;
            var gambar = document.createElement('img');
            gambar.src = URL.createObjectURL(berkas);
            pratinjau.innerHTML = '';
            pratinjau.appendChild(gambar);
            tombolSimpan.disabled = false;
        });
    }
})();
