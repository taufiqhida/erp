<script setup>
// Editor penandaan kotak kavling di siteplan raster: pilih/ikuti unit → klik di dalam kotaknya → sistem mewarnai area tertutup
// (paint bucket) dan menampilkan pratinjau → Oke menyimpan lalu otomatis lanjut ke unit berikutnya yang belum ditandai.
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import KavlingSearchSelect from '@/Components/KavlingSearchSelect.vue';
import SiteplanLapis from '@/Components/SiteplanLapis.vue';
import { petakanKlik, didalam, titikLabel } from '@/Composables/isiArea';

const props = defineProps({
    gambar:               { type: String, required: true },
    kavlings:             { type: Array, default: () => [] },
    statusConfig:         { type: Object, default: () => ({}) },
    statusBangunColorHex: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['tutup', 'tersimpan']);

// Gambar diambil dari host halaman ini (bukan APP_URL) supaya canvas tidak "tainted" dan pikselnya bisa dibaca.
const sumberGambar = (() => {
    try { const u = new URL(props.gambar, location.href); u.protocol = location.protocol; u.host = location.host; return u.toString(); }
    catch { return props.gambar; }
})();

// ── Daftar unit ──────────────────────────────────────────────────────────
const lokal = reactive({});   // id → bentuk terbaru (null = dihapus); menimpa k.bentuk dari server
const bentukDari = (k) => (k.id in lokal ? lokal[k.id] : k.bentuk);
const ditandai = (k) => Array.isArray(bentukDari(k)) && bentukDari(k).length >= 3;

const urut = (a, b) => (a.blok ?? '').localeCompare(b.blok ?? '', undefined, { numeric: true })
    || String(a.nomor_kavling).localeCompare(String(b.nomor_kavling), undefined, { numeric: true });
const semua = computed(() => [...props.kavlings].sort(urut));
const filterBlok = ref('');
const daftarBlok = computed(() => [...new Set(semua.value.map(k => k.blok).filter(Boolean))]);
const daftar = computed(() => semua.value.filter(k => !filterBlok.value || k.blok === filterBlok.value));

const jumlahDitandai = computed(() => semua.value.filter(ditandai).length);
const persen = computed(() => (semua.value.length ? Math.round((jumlahDitandai.value / semua.value.length) * 100) : 0));
const kavlingTampil = computed(() => semua.value.map(k => ({ ...k, bentuk: bentukDari(k) })));

const aktifId = ref(null);
const aktif = computed(() => semua.value.find(k => k.id === aktifId.value) ?? null);

/** Unit berikutnya yang belum ditandai setelah `id` (melingkar), selain `id` sendiri. */
const berikutnya = (id) => {
    const d = daftar.value;
    const i = d.findIndex(k => k.id === id);
    const urutan = [...d.slice(i + 1), ...d.slice(0, Math.max(i, 0))];
    return urutan.find(k => !ditandai(k) && k.id !== id)?.id ?? null;
};
aktifId.value = daftar.value.find(k => !ditandai(k))?.id ?? daftar.value[0]?.id ?? null;
watch(filterBlok, () => {
    if (!daftar.value.some(k => k.id === aktifId.value)) aktifId.value = daftar.value.find(k => !ditandai(k))?.id ?? daftar.value[0]?.id ?? null;
});

// ── Pewarnaan area ───────────────────────────────────────────────────────
const imgEl = ref(null);
const toleransi = ref(40);
const areaBesar = ref(false);
const pratinjau = ref(null);
const klikTerakhir = ref(null);
const pesan = ref(null);          // { jenis: 'info' | 'ok' | 'salah', teks }
const menyimpan = ref(false);
let cachePiksel = null;

const ambilPiksel = () => {
    if (cachePiksel) return cachePiksel;
    const im = imgEl.value;
    const s = Math.min(1, 3000 / Math.max(im.naturalWidth, im.naturalHeight));
    const w = Math.round(im.naturalWidth * s), h = Math.round(im.naturalHeight * s);
    try {
        const c = document.createElement('canvas');
        c.width = w; c.height = h;
        const ctx = c.getContext('2d', { willReadFrequently: true });
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, w, h);            // PNG transparan dianggap berlatar putih
        ctx.drawImage(im, 0, 0, w, h);
        cachePiksel = ctx.getImageData(0, 0, w, h);
    } catch {
        pesan.value = { jenis: 'salah', teks: 'Piksel gambar tidak bisa dibaca browser. Muat ulang halaman, atau unggah ulang siteplan.' };
        return null;
    }

    return cachePiksel;
};

const jalankan = () => {
    const k = klikTerakhir.value;
    if (!k) return;
    const img = ambilPiksel();
    if (!img) return;
    const hasil = petakanKlik(img, k.fx * img.width, k.fy * img.height, { toleransi: toleransi.value, luasMaks: areaBesar.value ? 0.25 : 0.04 });
    if (hasil.poligon) {
        pratinjau.value = hasil.poligon;
        pesan.value = null;
    } else {
        pratinjau.value = null;
        pesan.value = hasil.bocor
            ? { jenis: 'salah', teks: 'Warna tumpah keluar kotak — garis batasnya putus atau toleransi terlalu besar. Turunkan toleransi, atau centang "Izinkan area besar" bila kotaknya memang besar.' }
            : { jenis: 'salah', teks: 'Klik jatuh pada garis atau tulisan. Klik di bagian kosong di dalam kotak kavling.' };
    }
};
watch(toleransi, () => { if (klikTerakhir.value) jalankan(); });
watch(areaBesar, () => { if (klikTerakhir.value) jalankan(); });

const klik = (e) => {
    const r = imgEl.value.getBoundingClientRect();
    const fx = (e.clientX - r.left) / r.width, fy = (e.clientY - r.top) / r.height;
    if (fx < 0 || fx > 1 || fy < 0 || fy > 1) return;
    const kena = semua.value.find(k => ditandai(k) && didalam(bentukDari(k), fx * 100, fy * 100));
    if (kena && kena.id !== aktifId.value && !pratinjau.value) {
        aktifId.value = kena.id;
        pesan.value = { jenis: 'info', teks: `${kena.nomor_lengkap} sudah ditandai. Klik kotaknya sekali lagi untuk menandai ulang, atau tekan "Hapus bentuk".` };
        return;
    }
    if (!aktifId.value) { pesan.value = { jenis: 'salah', teks: 'Pilih unit yang akan ditandai terlebih dahulu.' }; return; }
    klikTerakhir.value = { fx, fy };
    jalankan();
};

// ── Simpan / ulangi / lewati / hapus ─────────────────────────────────────
const kirim = async (id, bentuk) => {
    const { data } = await window.axios.patch(route('kavlings.bentuk', id), { bentuk });
    lokal[id] = data.bentuk;
    emit('tersimpan', id, data.bentuk);
};
const galat = (e) => e.response?.data?.message ?? e.response?.data?.errors?.bentuk?.[0] ?? 'Gagal menyimpan. Coba lagi.';

const simpan = async () => {
    if (!pratinjau.value || !aktifId.value || menyimpan.value) return;
    menyimpan.value = true;
    const unit = aktif.value;
    try {
        await kirim(aktifId.value, pratinjau.value);
        pratinjau.value = null; klikTerakhir.value = null;
        const lanjut = berikutnya(unit.id);
        if (lanjut) {
            aktifId.value = lanjut;
            pesan.value = { jenis: 'ok', teks: `${unit.nomor_lengkap} tersimpan. Lanjut: klik kotak ${semua.value.find(k => k.id === lanjut).nomor_lengkap}.` };
        } else {
            pesan.value = { jenis: 'ok', teks: `${unit.nomor_lengkap} tersimpan. Semua unit${filterBlok.value ? ` di blok ${filterBlok.value}` : ''} sudah ditandai.` };
        }
    } catch (e) {
        pesan.value = { jenis: 'salah', teks: galat(e) };
    } finally {
        menyimpan.value = false;
    }
};

const ulangi = () => { pratinjau.value = null; klikTerakhir.value = null; pesan.value = null; };
const lewati = () => {
    const lanjut = berikutnya(aktifId.value);
    ulangi();
    if (lanjut) aktifId.value = lanjut; else pesan.value = { jenis: 'info', teks: 'Tidak ada unit lain yang belum ditandai.' };
};
const hapus = async () => {
    if (!aktif.value || !ditandai(aktif.value) || menyimpan.value) return;
    menyimpan.value = true;
    try {
        await kirim(aktif.value.id, null);
        ulangi();
        pesan.value = { jenis: 'info', teks: `Bentuk ${aktif.value.nomor_lengkap} dihapus.` };
    } catch (e) {
        pesan.value = { jenis: 'salah', teks: galat(e) };
    } finally {
        menyimpan.value = false;
    }
};

// ── Zoom & geser ─────────────────────────────────────────────────────────
const vp = ref(null);
const skala = ref(1), tx = ref(0), ty = ref(0);
const gaya = computed(() => `transform: translate(${tx.value}px, ${ty.value}px) scale(${skala.value}); transform-origin: 0 0;`);
const siap = ref(false);

const pas = () => {
    if (!imgEl.value || !vp.value) return;
    const vw = vp.value.clientWidth, vh = vp.value.clientHeight, ih = imgEl.value.offsetHeight;
    skala.value = Math.min(1, vh / ih);
    tx.value = (vw - vw * skala.value) / 2;
    ty.value = 0;
};
const gambarSiap = () => { siap.value = true; nextTick(pas); };

const zoomDi = (mx, my, faktor) => {
    const ns = Math.min(12, Math.max(0.3, skala.value * faktor));
    tx.value = mx - (mx - tx.value) * (ns / skala.value);
    ty.value = my - (my - ty.value) * (ns / skala.value);
    skala.value = ns;
};
const roda = (e) => {
    const r = vp.value.getBoundingClientRect();
    zoomDi(e.clientX - r.left, e.clientY - r.top, e.deltaY < 0 ? 1.2 : 1 / 1.2);
};
const zoomTombol = (faktor) => zoomDi(vp.value.clientWidth / 2, vp.value.clientHeight / 2, faktor);

let seret = null;
const turun = (e) => {
    if (e.button !== 0 && e.button !== 1) return;
    seret = { x: e.clientX, y: e.clientY, tx: tx.value, ty: ty.value, geser: false };
    vp.value.setPointerCapture(e.pointerId);
};
const pindah = (e) => {
    if (!seret) return;
    const dx = e.clientX - seret.x, dy = e.clientY - seret.y;
    if (!seret.geser && Math.hypot(dx, dy) > 5) seret.geser = true;
    if (seret.geser) { tx.value = seret.tx + dx; ty.value = seret.ty + dy; }
};
const naik = (e) => {
    if (!seret) return;
    const tanpaGeser = !seret.geser && e.button === 0;
    seret = null;
    if (tanpaGeser) klik(e);
};

// ── Pintasan papan ketik ─────────────────────────────────────────────────
const tombol = (e) => {
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target?.tagName)) return;
    if (e.key === 'Enter' && pratinjau.value) { e.preventDefault(); simpan(); }
    else if (e.key === 'Escape') { if (pratinjau.value) { e.preventDefault(); ulangi(); } }
    else if ((e.key === 's' || e.key === 'S') && !e.ctrlKey && !e.metaKey) lewati();
};
onMounted(() => {
    window.addEventListener('keydown', tombol);
    window.addEventListener('resize', pas);
    if (imgEl.value?.complete && imgEl.value.naturalWidth) gambarSiap();     // gambar sudah di cache: event load keburu lewat
});
onBeforeUnmount(() => { window.removeEventListener('keydown', tombol); window.removeEventListener('resize', pas); });

const labelPratinjau = computed(() => (pratinjau.value ? titikLabel(pratinjau.value) : null));
const hintUnit = (k) => (ditandai(k) ? '✓ sudah ditandai' : 'belum');
</script>

<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-50 flex flex-col bg-slate-950" role="dialog" aria-modal="true" aria-label="Tandai kotak kavling">
            <!-- Bar atas -->
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 border-b border-slate-800 bg-slate-900">
                <h2 class="text-white font-semibold text-sm">Tandai Kotak Kavling</h2>
                <div class="flex items-center gap-2 text-xs text-slate-400">
                    <div class="w-28 h-1.5 rounded-full bg-slate-800 overflow-hidden"><div class="h-full bg-emerald-500" :style="`width:${persen}%`"></div></div>
                    {{ jumlahDitandai }} / {{ semua.length }} unit ditandai
                </div>
                <button type="button" class="ml-auto px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs rounded-lg" @click="emit('tutup')">Selesai</button>
            </div>

            <!-- Bar kendali -->
            <div class="flex flex-wrap items-center gap-3 px-4 py-2.5 border-b border-slate-800 bg-slate-900/60">
                <div class="w-56">
                    <KavlingSearchSelect v-model="aktifId" :kavlings="daftar" placeholder="-- pilih unit --" :option-hint="hintUnit" />
                </div>
                <select v-model="filterBlok" class="px-2.5 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                    <option value="">Semua blok</option>
                    <option v-for="b in daftarBlok" :key="b" :value="b">Blok {{ b }}</option>
                </select>
                <label class="flex items-center gap-2 text-xs text-slate-400">
                    Toleransi warna
                    <input type="range" v-model.number="toleransi" min="5" max="120" step="5" class="w-24 accent-violet-500" />
                    <span class="w-6 text-slate-300">{{ toleransi }}</span>
                </label>
                <label class="flex items-center gap-1.5 text-xs text-slate-400 cursor-pointer">
                    <input type="checkbox" v-model="areaBesar" class="rounded border-slate-600 bg-slate-800 text-violet-500" /> Izinkan area besar
                </label>
                <div class="ml-auto flex items-center gap-1">
                    <button type="button" class="w-8 h-8 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-lg" title="Perkecil" @click="zoomTombol(1 / 1.3)">−</button>
                    <button type="button" class="w-8 h-8 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-lg" title="Perbesar" @click="zoomTombol(1.3)">+</button>
                    <button type="button" class="px-2.5 h-8 bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs rounded-lg" @click="pas">Pas layar</button>
                </div>
            </div>

            <!-- Kanvas -->
            <div ref="vp" class="relative flex-1 overflow-hidden bg-slate-950 select-none cursor-crosshair" style="touch-action: none;"
                 @wheel.prevent="roda" @pointerdown="turun" @pointermove="pindah" @pointerup="naik" @pointercancel="seret = null">
                <div class="absolute left-0 top-0 w-full" :style="gaya">
                    <img ref="imgEl" :src="sumberGambar" alt="Siteplan" class="block w-full pointer-events-none" draggable="false" @load="gambarSiap" />
                    <SiteplanLapis :kavlings="kavlingTampil" :status-config="statusConfig" :status-bangun-color-hex="statusBangunColorHex"
                                   :selected-id="aktifId" :aktif="false" />
                    <svg v-if="pratinjau" class="absolute inset-0 w-full h-full pointer-events-none" viewBox="0 0 100 100" preserveAspectRatio="none">
                        <polygon :points="pratinjau.map(p => p.join(',')).join(' ')" fill="#f59e0b" fill-opacity="0.45" stroke="#f59e0b"
                                 stroke-width="2" stroke-dasharray="6 3" vector-effect="non-scaling-stroke" />
                    </svg>
                    <span v-if="pratinjau && labelPratinjau && aktif" class="absolute -translate-x-1/2 -translate-y-1/2 px-1.5 py-0.5 rounded bg-amber-500 text-slate-900 font-bold text-[0.625rem] whitespace-nowrap pointer-events-none"
                          :style="`left:${labelPratinjau[0]}%; top:${labelPratinjau[1]}%;`">{{ aktif.nomor_lengkap }}</span>
                </div>
                <div v-if="!siap" class="absolute inset-0 flex items-center justify-center text-slate-500 text-sm">Memuat gambar…</div>
            </div>

            <!-- Bar bawah: petunjuk + aksi -->
            <div class="flex flex-wrap items-center gap-3 px-4 py-3 border-t border-slate-800 bg-slate-900">
                <div class="flex-1 min-w-[240px] text-sm">
                    <p v-if="pesan" :class="{ 'text-emerald-400': pesan.jenis === 'ok', 'text-rose-400': pesan.jenis === 'salah', 'text-slate-300': pesan.jenis === 'info' }">{{ pesan.teks }}</p>
                    <p v-else-if="pratinjau" class="text-amber-400">Pratinjau untuk <b>{{ aktif?.nomor_lengkap }}</b> — sudah pas? Tekan <kbd class="px-1 bg-slate-800 rounded">Enter</kbd> untuk simpan, atau klik lagi untuk mengulang.</p>
                    <p v-else-if="aktif" class="text-slate-300">Klik di dalam kotak <b class="text-white">{{ aktif.nomor_lengkap }}</b>{{ ditandai(aktif) ? ' (menandai ulang)' : '' }}. Gulir untuk zoom, seret untuk geser.</p>
                    <p v-else class="text-slate-500">Tidak ada unit pada blok ini.</p>
                </div>
                <button v-if="aktif && ditandai(aktif) && !pratinjau" type="button" :disabled="menyimpan" @click="hapus"
                        class="px-3 py-2 text-rose-400 hover:bg-rose-500/10 border border-rose-500/30 text-xs rounded-lg disabled:opacity-50">Hapus bentuk</button>
                <button type="button" @click="lewati" class="px-3 py-2 text-slate-300 hover:bg-slate-800 border border-slate-700 text-xs rounded-lg">Lewati (S)</button>
                <button v-if="pratinjau" type="button" @click="ulangi" class="px-3 py-2 text-slate-300 hover:bg-slate-800 border border-slate-700 text-xs rounded-lg">Ulangi (Esc)</button>
                <button v-if="pratinjau" type="button" :disabled="menyimpan" @click="simpan"
                        class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold rounded-lg disabled:opacity-50">
                    {{ menyimpan ? 'Menyimpan…' : 'Oke, simpan (Enter)' }}
                </button>
            </div>
        </div>
    </Teleport>
</template>
