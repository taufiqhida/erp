// Penerapan tema pada <html>: data-tema = "gelap" | "terang" (yang sedang tampil),
// data-pilihan-tema = pilihan pengguna ("gelap" | "terang" | "sistem"). Server merender keduanya saat halaman
// dimuat; fungsi ini dipakai saat pengguna mengganti pilihan, dan untuk mengikuti perubahan tema sistem.

const mediaTerang = typeof window !== 'undefined' ? window.matchMedia('(prefers-color-scheme: light)') : null;

const hasilTema = (pilihan) => (pilihan === 'sistem' ? (mediaTerang?.matches ? 'terang' : 'gelap') : pilihan);

export function terapkanTema(pilihan) {
    const el = document.documentElement;
    el.dataset.pilihanTema = pilihan;
    el.dataset.tema = hasilTema(pilihan);
}

// Pilihan "sistem": ikuti perubahan pengaturan perangkat saat aplikasi sedang terbuka.
mediaTerang?.addEventListener?.('change', () => {
    const el = document.documentElement;
    if (el.dataset.pilihanTema === 'sistem') el.dataset.tema = hasilTema('sistem');
});
