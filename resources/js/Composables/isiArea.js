// Mesin "paint bucket" untuk menandai kavling di siteplan raster. Murni (tanpa DOM) supaya bisa diuji dengan `node --test`.
//
// Alur: isiArea() mewarnai area tertutup dari titik klik → lebarkan() menebalkan 1px agar menutup setengah garis batas →
// kontur() menelusuri tepi luar (lubang akibat teks/nomor di dalam kotak otomatis tertutup) → sederhanakan() merapikan
// tangga piksel menjadi beberapa titik sudut → ke persen() mengubahnya ke koordinat 0–100 yang disimpan di database.

/**
 * Warnai area yang warnanya mirip titik awal (4 arah). `img` = { data: Uint8ClampedArray RGBA, width, height }.
 * Mengembalikan { mask, w, h, luas, bocor, bbox } — `bocor` bila area menyentuh tepi gambar atau lebih luas dari `luasMaks`
 * (tanda garis batas putus sehingga warna "tumpah" ke luar kotak).
 */
export function isiArea(img, sx, sy, { toleransi = 40, luasMaks = 0.04 } = {}) {
    const { data, width: w, height: h } = img;
    sx = Math.floor(sx); sy = Math.floor(sy);
    const mask = new Uint8Array(w * h);
    if (sx < 0 || sy < 0 || sx >= w || sy >= h) return { mask, w, h, luas: 0, bocor: false, bbox: null };

    const p0 = (sy * w + sx) * 4;
    const r0 = data[p0], g0 = data[p0 + 1], b0 = data[p0 + 2], a0 = data[p0 + 3];
    const mirip = (i) => {
        const p = i * 4;
        return Math.abs(data[p] - r0) <= toleransi && Math.abs(data[p + 1] - g0) <= toleransi
            && Math.abs(data[p + 2] - b0) <= toleransi && Math.abs(data[p + 3] - a0) <= toleransi;
    };

    let luas = 0, minX = sx, maxX = sx, minY = sy, maxY = sy;
    const batas = Math.max(1, Math.floor(w * h * luasMaks));
    const tumpukan = [sx, sy];
    while (tumpukan.length) {
        const y = tumpukan.pop(), x0 = tumpukan.pop();
        let x = x0;
        if (mask[y * w + x] || !mirip(y * w + x)) continue;
        while (x > 0 && !mask[y * w + x - 1] && mirip(y * w + x - 1)) x--;     // geser ke ujung kiri baris
        let atas = false, bawah = false;
        for (; x < w && !mask[y * w + x] && mirip(y * w + x); x++) {
            mask[y * w + x] = 1; luas++;
            if (x < minX) minX = x; if (x > maxX) maxX = x;
            if (y > 0) {
                const ok = !mask[(y - 1) * w + x] && mirip((y - 1) * w + x);
                if (ok && !atas) { tumpukan.push(x, y - 1); atas = true; } else if (!ok) atas = false;
            }
            if (y < h - 1) {
                const ok = !mask[(y + 1) * w + x] && mirip((y + 1) * w + x);
                if (ok && !bawah) { tumpukan.push(x, y + 1); bawah = true; } else if (!ok) bawah = false;
            }
        }
        if (y < minY) minY = y; if (y > maxY) maxY = y;
        if (luas > batas) return { mask, w, h, luas, bocor: true, bbox: { minX, minY, maxX, maxY } };
    }

    // Menyentuh tepi gambar = tumpah keluar kotak.
    const sentuhTepi = minX === 0 || minY === 0 || maxX === w - 1 || maxY === h - 1;

    return { mask, w, h, luas, bocor: sentuhTepi, bbox: { minX, minY, maxX, maxY } };
}

/** Tebalkan area 1 piksel ke segala arah (4 tetangga) — hasil baru, mask asli tidak diubah. */
export function lebarkan(hasil, kali = 1) {
    const { w, h } = hasil;
    let cur = hasil.mask;
    let { minX, minY, maxX, maxY } = hasil.bbox;
    for (let k = 0; k < kali; k++) {
        const nxt = new Uint8Array(cur);
        const x1 = Math.max(0, minX - 1), x2 = Math.min(w - 1, maxX + 1), y1 = Math.max(0, minY - 1), y2 = Math.min(h - 1, maxY + 1);
        for (let y = y1; y <= y2; y++) {
            for (let x = x1; x <= x2; x++) {
                const i = y * w + x;
                if (cur[i]) continue;
                if ((x > 0 && cur[i - 1]) || (x < w - 1 && cur[i + 1]) || (y > 0 && cur[i - w]) || (y < h - 1 && cur[i + w])) nxt[i] = 1;
            }
        }
        cur = nxt; minX = x1; maxX = x2; minY = y1; maxY = y2;
    }
    let luas = 0;
    for (let y = minY; y <= maxY; y++) for (let x = minX; x <= maxX; x++) luas += cur[y * w + x];

    return { ...hasil, mask: cur, luas, bbox: { minX, minY, maxX, maxY } };
}

// Arah: 0 kanan, 1 bawah, 2 kiri, 3 atas. Dua piksel di depan sebuah sudut (kiri-jalan, kanan-jalan) untuk tiap arah.
const DX = [1, 0, -1, 0], DY = [0, 1, 0, -1];
const KIRI = [[0, -1], [0, 0], [-1, 0], [-1, -1]];
const KANAN = [[0, 0], [-1, 0], [-1, -1], [0, -1]];

/**
 * Telusuri tepi LUAR area sebagai daftar titik sudut (koordinat sudut piksel, bukan pusat). Lubang di dalam diabaikan.
 * Mengembalikan [] bila mask kosong.
 */
export function kontur(hasil) {
    const { mask, w, h, bbox } = hasil;
    if (!bbox) return [];
    const isi = (x, y) => x >= 0 && y >= 0 && x < w && y < h && mask[y * w + x] === 1;

    let x0 = -1, y0 = -1;
    cari: for (let y = bbox.minY; y <= bbox.maxY; y++) {
        for (let x = bbox.minX; x <= bbox.maxX; x++) if (isi(x, y)) { x0 = x; y0 = y; break cari; }
    }
    if (x0 < 0) return [];

    const titik = [];
    let x = x0, y = y0, d = 0, maks = (w + h) * 8 + 16, langkah = 0;
    do {
        // Jaga agar area tetap di sisi kanan jalan: kiri-depan terisi → belok kiri; kanan-depan terisi → lurus; selain itu belok kanan.
        let nd;
        if (isi(x + KIRI[d][0], y + KIRI[d][1])) nd = (d + 3) % 4;
        else if (isi(x + KANAN[d][0], y + KANAN[d][1])) nd = d;
        else nd = (d + 1) % 4;
        if (nd !== d || titik.length === 0) titik.push([x, y]);
        d = nd;
        x += DX[d]; y += DY[d];
        if (++langkah > maks * 8) break;       // pengaman terhadap perulangan tak berujung
    } while (!(x === x0 && y === y0));

    return titik;
}

/** Douglas–Peucker untuk poligon tertutup: pecah di dua titik terjauh agar tidak bergantung titik awal. */
export function sederhanakan(titik, epsilon = 1.5) {
    if (titik.length <= 3) return titik.slice();
    const jarakKeGaris = (p, a, b) => {
        const dx = b[0] - a[0], dy = b[1] - a[1], k = dx * dx + dy * dy;
        if (k === 0) return Math.hypot(p[0] - a[0], p[1] - a[1]);
        return Math.abs(dy * p[0] - dx * p[1] + b[0] * a[1] - b[1] * a[0]) / Math.sqrt(k);
    };
    const dp = (pts, lo, hi, keep) => {
        // iteratif agar aman untuk kontur panjang
        const st = [[lo, hi]];
        while (st.length) {
            const [a, b] = st.pop();
            let idx = -1, maxD = epsilon;
            for (let i = a + 1; i < b; i++) {
                const dd = jarakKeGaris(pts[i], pts[a], pts[b]);
                if (dd > maxD) { maxD = dd; idx = i; }
            }
            if (idx >= 0) { keep[idx] = true; st.push([a, idx], [idx, b]); }
        }
    };
    // titik terjauh dari titik pertama
    let jauh = 0, md = -1;
    for (let i = 1; i < titik.length; i++) {
        const dd = (titik[i][0] - titik[0][0]) ** 2 + (titik[i][1] - titik[0][1]) ** 2;
        if (dd > md) { md = dd; jauh = i; }
    }
    const keep = new Array(titik.length).fill(false);
    keep[0] = keep[jauh] = true;
    dp(titik, 0, jauh, keep);
    const sisi2 = titik.slice(jauh).concat([titik[0]]);
    const keep2 = new Array(sisi2.length).fill(false);
    keep2[0] = keep2[sisi2.length - 1] = true;
    dp(sisi2, 0, sisi2.length - 1, keep2);

    const hasil = [];
    for (let i = 0; i < jauh; i++) if (keep[i]) hasil.push(titik[i]);
    for (let i = 0; i < sisi2.length - 1; i++) if (keep2[i]) hasil.push(sisi2[i]);

    return hasil;
}

/** Piksel → persen (0–100) terhadap lebar/tinggi gambar, dibulatkan 2 desimal. */
export function kePersen(titik, w, h) {
    const r = (v) => Math.round(v * 100) / 100;
    return titik.map(([x, y]) => [r(Math.min(100, Math.max(0, (x / w) * 100))), r(Math.min(100, Math.max(0, (y / h) * 100)))]);
}

/** Luas poligon (persen²) dengan rumus tali sepatu — sama dengan pengecekan di server. */
export function luasPoligon(poli) {
    let s = 0;
    for (let i = 0; i < poli.length; i++) {
        const [x1, y1] = poli[i], [x2, y2] = poli[(i + 1) % poli.length];
        s += x1 * y2 - x2 * y1;
    }

    return Math.abs(s) / 2;
}

/** Apakah titik (x,y) berada di dalam poligon (even-odd). */
export function didalam(poli, x, y) {
    let dalam = false;
    for (let i = 0, j = poli.length - 1; i < poli.length; j = i++) {
        const [xi, yi] = poli[i], [xj, yj] = poli[j];
        if ((yi > y) !== (yj > y) && x < ((xj - xi) * (y - yi)) / (yj - yi) + xi) dalam = !dalam;
    }

    return dalam;
}

/** Titik tengah "aman" untuk label: pusat massa kotak pembatas bila di dalam poligon, jika tidak titik terdekat yang di dalam. */
export function titikLabel(poli) {
    const xs = poli.map((p) => p[0]), ys = poli.map((p) => p[1]);
    const cx = (Math.min(...xs) + Math.max(...xs)) / 2, cy = (Math.min(...ys) + Math.max(...ys)) / 2;
    if (didalam(poli, cx, cy)) return [cx, cy];
    let terbaik = null, jarak = Infinity;
    const langkah = Math.max((Math.max(...xs) - Math.min(...xs)) / 20, 0.05);
    for (let y = Math.min(...ys); y <= Math.max(...ys); y += langkah) {
        for (let x = Math.min(...xs); x <= Math.max(...xs); x += langkah) {
            if (!didalam(poli, x, y)) continue;
            const dd = (x - cx) ** 2 + (y - cy) ** 2;
            if (dd < jarak) { jarak = dd; terbaik = [x, y]; }
        }
    }

    return terbaik ?? [cx, cy];
}

/**
 * Jalur lengkap: klik (piksel) → poligon persen. Mengembalikan { poligon, bocor, salahKlik, luasPiksel }.
 * `poligon` null bila gagal. `bocor`: warna tumpah keluar kotak (garis batas putus). `salahKlik`: klik jatuh pada garis/teks
 * dan bukan di dalam kotak — hasilnya bingkai tipis yang menyelubungi ruang kosong jauh lebih luas dari luasnya sendiri.
 */
export function petakanKlik(img, sx, sy, opsi = {}) {
    const { luasMin = 30, epsilon = 1.2, dilasi = 1 } = opsi;
    const isi = isiArea(img, sx, sy, opsi);
    const gagal = (tambah) => ({ poligon: null, bocor: false, salahKlik: false, luasPiksel: isi.luas, ...tambah });
    if (isi.bocor) return gagal({ bocor: true });
    if (isi.luas < luasMin) return gagal({ salahKlik: true });
    const sudut = kontur(isi);
    if (sudut.length < 3 || isi.luas < luasPoligon(sudut) * 0.4) return gagal({ salahKlik: true });
    const tebal = dilasi > 0 ? lebarkan(isi, dilasi) : isi;
    const poli = kePersen(sederhanakan(kontur(tebal), epsilon), img.width, img.height);
    if (poli.length < 3) return gagal({ salahKlik: true });

    return { poligon: poli, bocor: false, salahKlik: false, luasPiksel: isi.luas };
}
