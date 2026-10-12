// Jalankan: node --test tests/js
import test from 'node:test';
import assert from 'node:assert/strict';
import { isiArea, lebarkan, kontur, sederhanakan, kePersen, luasPoligon, didalam, titikLabel, petakanKlik } from '../../resources/js/Composables/isiArea.js';

/** Gambar putih W×H; fungsi `gambar(set)` menggambar piksel gelap. */
function kanvas(w, h, gambar) {
    const data = new Uint8ClampedArray(w * h * 4).fill(255);
    const set = (x, y, v = 0) => { if (x >= 0 && y >= 0 && x < w && y < h) { const p = (y * w + x) * 4; data[p] = data[p + 1] = data[p + 2] = v; } };
    gambar(set);
    return { data, width: w, height: h };
}
const LONGGAR = { luasMaks: 0.9 };     // gambar uji kecil: jangan anggap kotak biasa sebagai bocor
const garisKotak = (set, x1, y1, x2, y2, tebal = 2) => {
    for (let t = 0; t < tebal; t++) {
        for (let x = x1; x <= x2; x++) { set(x, y1 + t); set(x, y2 - t); }
        for (let y = y1; y <= y2; y++) { set(x1 + t, y); set(x2 - t, y); }
    }
};

test('mewarnai kotak tertutup persis di dalam garis', () => {
    const img = kanvas(100, 100, (s) => garisKotak(s, 20, 20, 40, 40));
    const r = isiArea(img, 30, 30, LONGGAR);
    assert.equal(r.bocor, false);
    assert.equal(r.luas, 17 * 17);                                  // 22..38 → 17 piksel per sisi
    assert.deepEqual(r.bbox, { minX: 22, minY: 22, maxX: 38, maxY: 38 });
});

test('garis putus membuat warna tumpah dan ditandai bocor', () => {
    const img = kanvas(100, 100, (s) => { garisKotak(s, 20, 20, 40, 40); for (let y = 28; y < 33; y++) { s(20, y, 255); s(21, y, 255); } });
    const r = isiArea(img, 30, 30);
    assert.equal(r.bocor, true);
});

test('teks/noda di dalam kotak tidak melubangi hasil akhir (kontur luar)', () => {
    const img = kanvas(100, 100, (s) => { garisKotak(s, 20, 20, 40, 40); for (let x = 28; x < 33; x++) for (let y = 28; y < 33; y++) s(x, y); });
    const r = petakanKlik(img, 24, 24, LONGGAR);
    assert.ok(r.poligon);
    assert.ok(didalam(r.poligon, 30, 30), 'titik di noda tetap termasuk poligon');
    assert.ok(Math.abs(luasPoligon(r.poligon) - 18 * 18) <= 20, `luas ${luasPoligon(r.poligon)}`);   // 21..39 setelah dilasi
});

test('klik pada garis menghasilkan salahKlik, bukan poligon', () => {
    const img = kanvas(100, 100, (s) => garisKotak(s, 20, 20, 40, 40));
    const r = petakanKlik(img, 20, 30, LONGGAR);
    assert.equal(r.poligon, null);
    assert.equal(r.salahKlik, true);
});

test('kontur kotak disederhanakan menjadi 4 sudut', () => {
    const img = kanvas(100, 100, (s) => garisKotak(s, 20, 20, 40, 40));
    const r = petakanKlik(img, 30, 30, LONGGAR);
    assert.equal(r.poligon.length, 4);
    const xs = r.poligon.map((p) => p[0]), ys = r.poligon.map((p) => p[1]);
    assert.deepEqual([Math.min(...xs), Math.max(...xs), Math.min(...ys), Math.max(...ys)], [21, 40, 21, 40]);
});

test('bentuk L (tidak cembung) tetap benar', () => {
    const img = kanvas(100, 100, (s) => {
        const sisi = [[20, 20], [60, 20], [60, 40], [40, 40], [40, 60], [20, 60]];
        for (let i = 0; i < sisi.length; i++) {
            const [a, b] = [sisi[i], sisi[(i + 1) % sisi.length]];
            for (let x = Math.min(a[0], b[0]); x <= Math.max(a[0], b[0]); x++) for (let y = Math.min(a[1], b[1]); y <= Math.max(a[1], b[1]); y++) { s(x, y); s(x + 1, y); s(x, y + 1); }
        }
    });
    const r = petakanKlik(img, 25, 25, LONGGAR);
    assert.equal(r.poligon.length, 6);
    assert.ok(didalam(r.poligon, 25, 55) && didalam(r.poligon, 55, 25));
    assert.ok(!didalam(r.poligon, 55, 55), 'sudut kosong L tidak ikut');
});

test('kotak berwarna (bukan putih) dan toleransi untuk tepi lembut', () => {
    const img = kanvas(100, 100, (s) => garisKotak(s, 20, 20, 60, 60));
    for (let y = 22; y < 59; y++) for (let x = 22; x < 59; x++) { const p = (y * 100 + x) * 4; img.data[p] = 200; img.data[p + 1] = 230; img.data[p + 2] = 200; }
    img.data[(30 * 100 + 30) * 4] = 210;                           // sedikit variasi warna
    const r = isiArea(img, 25, 25, { toleransi: 30, luasMaks: 0.9 });
    assert.equal(r.bocor, false);
    assert.equal(r.luas, 37 * 37);
});

test('area yang menyentuh tepi gambar atau melewati batas luas ditandai bocor', () => {
    const img = kanvas(100, 100, () => {});
    assert.equal(isiArea(img, 50, 50).bocor, true);
    const img2 = kanvas(100, 100, (s) => garisKotak(s, 5, 5, 95, 95));
    assert.equal(isiArea(img2, 50, 50, { luasMaks: 0.9 }).bocor, false);
    assert.equal(isiArea(img2, 50, 50, { luasMaks: 0.04 }).bocor, true);
});

test('sederhanakan membuang titik segaris dan kePersen membulatkan', () => {
    const lurus = [[0, 0], [5, 0], [10, 0], [10, 5], [10, 10], [0, 10]];
    assert.equal(sederhanakan(lurus, 0.5).length, 4);
    assert.deepEqual(kePersen([[50, 25]], 200, 100), [[25, 25]]);
    assert.deepEqual(kePersen([[1, 1]], 3, 3), [[33.33, 33.33]]);
});

test('titikLabel untuk bentuk L jatuh di dalam poligon', () => {
    const L = [[0, 0], [10, 0], [10, 3], [3, 3], [3, 10], [0, 10]];
    const [x, y] = titikLabel(L);
    assert.ok(didalam(L, x, y));
});

test('lebarkan menambah 1 piksel di sekeliling', () => {
    const img = kanvas(50, 50, (s) => garisKotak(s, 10, 10, 20, 20));
    const dasar = isiArea(img, 15, 15);
    assert.equal(lebarkan(dasar, 1).luas, dasar.luas + 4 * 7 /* sisi 7 */ + 0);
    assert.equal(kontur(dasar).length, 4);
});
