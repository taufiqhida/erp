// Penerapan tema pada <html>: data-tema = "gelap" | "terang" (yang sedang tampil),
// data-pilihan-tema = pilihan pengguna ("gelap" | "terang" | "sistem"). Server merender keduanya saat halaman
// dimuat; fungsi ini dipakai saat pengguna mengganti pilihan, saat data akun masuk lewat navigasi (mis. setelah login),
// dan untuk mengikuti perubahan tema sistem.
//
// Pilihan terakhir juga diingat di PERANGKAT ini (localStorage) hanya supaya halaman Masuk (tamu) tidak kembali
// ke gelap setelah logout/refresh. Setelah login, yang berlaku selalu pilihan di akun.

const KUNCI_PERANGKAT = 'ssid-tema-perangkat';
const PILIHAN = ['gelap', 'terang', 'sistem'];

const mediaTerang = typeof window !== 'undefined' ? window.matchMedia('(prefers-color-scheme: light)') : null;

const hasilTema = (pilihan) => (pilihan === 'sistem' ? (mediaTerang?.matches ? 'terang' : 'gelap') : pilihan);

export function terapkanTema(pilihan) {
    if (!PILIHAN.includes(pilihan)) return;
    const el = document.documentElement;
    el.dataset.pilihanTema = pilihan;
    el.dataset.tema = hasilTema(pilihan);
    try { localStorage.setItem(KUNCI_PERANGKAT, pilihan); } catch { /* penyimpanan browser tidak tersedia: abaikan */ }
}

export function temaPerangkat() {
    try {
        const v = localStorage.getItem(KUNCI_PERANGKAT);
        return PILIHAN.includes(v) ? v : null;
    } catch {
        return null;
    }
}

/**
 * Samakan tampilan dengan data yang baru masuk dari server. Dipanggil pada setiap perpindahan halaman:
 *  - sudah login → pakai pilihan AKUN (tema & ukuran teks), jadi indikator dan layar selalu sama;
 *  - tamu (halaman Masuk dst.) → pakai pilihan terakhir di perangkat ini, kalau ada.
 */
export function sinkronTampilan(props) {
    const user = props?.auth?.user;
    const el = document.documentElement;

    if (user) {
        if (user.tema) terapkanTema(user.tema);
        if (user.ukuran_font) el.dataset.ukuran = user.ukuran_font;
        return;
    }

    const pilihan = temaPerangkat();
    if (pilihan) {
        el.dataset.pilihanTema = pilihan;
        el.dataset.tema = hasilTema(pilihan);
    }
}

// Pilihan "sistem": ikuti perubahan pengaturan perangkat saat aplikasi sedang terbuka.
mediaTerang?.addEventListener?.('change', () => {
    const el = document.documentElement;
    if (el.dataset.pilihanTema === 'sistem') el.dataset.tema = hasilTema('sistem');
});
