<script setup>
import Pagination from '@/Components/Pagination.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PopoverPanel from '@/Components/PopoverPanel.vue';
import RekapSel from '@/Components/RekapSel.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SortTh from '@/Components/SortTh.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';

// Rekap Kekurangan — satu baris per konsumen, kolom per komponen tagihan. Tiga tampilan (Piutang Konsumen KPR, Pencairan Bank, Piutang
// Konsumen Cash). Status + kolom terpilih menentukan baris & isi sel; disort dan dipaginasi di server. Filter yang jarang dipakai
// dilipat ke popover supaya toolbar tetap ringkas.
const props = defineProps({
    rows:          Object, // paginated
    kolom:         Array,  // [{key, label, grup, jumlah}] semua kolom yang bisa dipilih pada tampilan ini
    terpilih:      Array,  // kunci kolom yang sedang tampil
    summary:       Object,
    tahapOptions:  Array,  // [{key, label}] tahap yang tersedia pada tampilan ini
    tahapDefault:  Array,
    filterOptions: Object,
    filters:       Object,
});

const tampilan = computed(() => props.filters?.tampilan ?? 'pencairan_bank');
const mode     = computed(() => props.filters?.mode ?? 'belum_cair');
const sort     = computed(() => props.filters?.sort ?? 'total');
const dir      = computed(() => props.filters?.dir ?? 'desc');
const adaBank  = computed(() => tampilan.value !== 'piutang_cash');
const tampilPiutang = computed(() => tampilan.value !== 'pencairan_bank');

const search     = ref(props.filters?.search ?? '');
const kluster    = ref(props.filters?.kluster ?? '');
const blok       = ref(props.filters?.blok ?? '');
const caraBayar  = ref(props.filters?.cara_bayar ?? '');
const bank       = ref(props.filters?.bank ?? '');
const umur       = ref(props.filters?.umur ?? '');
const akadDari   = ref(props.filters?.akad_dari ?? '');
const akadSampai = ref(props.filters?.akad_sampai ?? '');
const tahapPilihan = ref([...(props.filters?.tahap ?? [])]);
const pilihan      = ref([...(props.terpilih ?? [])]);

// Data server adalah sumber kebenaran: sinkronkan pilihan lokal setiap kali hasil baru datang (mis. setelah ganti tampilan).
watch(() => props.terpilih, (v) => { pilihan.value = [...v]; });
watch(() => props.filters?.tahap, (v) => { tahapPilihan.value = [...(v ?? [])]; }, { deep: true });
watch(() => props.filters?.cara_bayar, (v) => { caraBayar.value = v ?? ''; });

const urut = (a) => [...a].sort().join('|');
const tahapBeda = computed(() => urut(tahapPilihan.value) !== urut(props.tahapDefault));
const kolomBeda = computed(() => props.kolom.some(k => !pilihan.value.includes(k.key)));

const go = (override = {}) => {
    router.get(route('keuangan.rekap-kekurangan'), {
        tampilan: tampilan.value,
        search: search.value || undefined,
        kluster: kluster.value || undefined,
        blok: blok.value || undefined,
        cara_bayar: caraBayar.value || undefined,
        bank: adaBank.value ? (bank.value || undefined) : undefined,
        umur: umur.value || undefined,
        akad_dari: akadDari.value || undefined,
        akad_sampai: akadSampai.value || undefined,
        // Tanpa parameter = bawaan tampilan; "-" = tidak ada yang dipilih.
        tahap: tahapBeda.value ? (tahapPilihan.value.length ? tahapPilihan.value : ['-']) : undefined,
        kolom: kolomBeda.value ? (pilihan.value.length ? pilihan.value : ['-']) : undefined,
        mode: mode.value,
        sort: sort.value,
        dir: dir.value,
        ...override,
    }, { preserveState: true, replace: true });
};

const setSort = (key, direction) => go({ sort: key, dir: direction });
const setMode = (m) => go({ mode: m });
// Ganti tampilan: kolom, tahap, urutan, dan cara bayar kembali ke bawaan tampilan itu; pencarian & filter lain tetap.
const setTampilan = (t) => {
    if (t === tampilan.value) return;
    go({ tampilan: t, tahap: undefined, kolom: undefined, cara_bayar: undefined, sort: undefined, dir: undefined });
};

let debounce = null;
const tunda = () => { clearTimeout(debounce); debounce = setTimeout(() => go(), 350); };
watch(search, () => { clearTimeout(debounce); debounce = setTimeout(() => go(), 300); });

const toggle = (daftar, key) => (daftar.includes(key) ? daftar.filter(k => k !== key) : [...daftar, key]);
const toggleTahap = (key) => { tahapPilihan.value = toggle(tahapPilihan.value, key); tunda(); };
const toggleKolom = (key) => { pilihan.value = toggle(pilihan.value, key); tunda(); };
const setTahap = (keys) => { tahapPilihan.value = [...keys]; go(); };
const pilihSemuaKolom = () => { pilihan.value = props.kolom.map(k => k.key); go(); };
const kosongkanKolom = () => { pilihan.value = []; go(); };

// Pintasan tahap: hanya yang relevan dengan pipeline tampilan itu.
const pintasanTahap = computed(() => {
    const semua = props.tahapOptions.map(o => o.key);
    if (tampilan.value === 'piutang_kpr') {
        return [
            { label: 'Menuju akad', keys: ['sp3k', 'rencana_akad'] },
            { label: 'Sudah akad', keys: ['akad', 'bast'] },
            { label: 'Semua', keys: semua },
        ];
    }
    return tampilan.value === 'piutang_cash' ? [{ label: 'Semua', keys: semua }] : [];
});

const filterLainAktif = computed(() => [caraBayar.value, umur.value, kluster.value, blok.value, akadDari.value || akadSampai.value].filter(Boolean).length);
const hasFilter = computed(() => !!(search.value || bank.value || filterLainAktif.value || tahapBeda.value));
const resetFilters = () => {
    search.value = ''; kluster.value = ''; blok.value = ''; caraBayar.value = ''; bank.value = '';
    umur.value = ''; akadDari.value = ''; akadSampai.value = ''; tahapPilihan.value = [...props.tahapDefault];
    go({ search: undefined, kluster: undefined, blok: undefined, cara_bayar: undefined, bank: undefined, umur: undefined, akad_dari: undefined, akad_sampai: undefined, tahap: undefined });
};

const exportUrl = computed(() => {
    const params = new URLSearchParams();
    Object.entries({
        tampilan: tampilan.value, search: search.value, kluster: kluster.value, blok: blok.value, cara_bayar: caraBayar.value,
        bank: adaBank.value ? bank.value : '', umur: umur.value, akad_dari: akadDari.value, akad_sampai: akadSampai.value,
        mode: mode.value, sort: sort.value, dir: dir.value,
    }).forEach(([k, v]) => { if (v) params.set(k, v); });
    if (tahapBeda.value) (tahapPilihan.value.length ? tahapPilihan.value : ['-']).forEach(k => params.append('tahap[]', k));
    if (kolomBeda.value) (pilihan.value.length ? pilihan.value : ['-']).forEach(k => params.append('kolom[]', k));
    return route('keuangan.rekap-kekurangan.export') + '?' + params.toString();
});

const tampilanList = [
    { key: 'piutang_kpr',    label: 'Piutang Konsumen KPR', info: 'Yang masih harus dibayar konsumen KPR: Booking Fee, DP, biaya tanah, biaya tambahan, Tambahan UM.' },
    { key: 'pencairan_bank', label: 'Pencairan Bank',       info: 'Dana bank yang masih ditahan untuk KPR yang sudah akad: Dajam, SBUM, pencairan netto. Cocok untuk pengajuan ke bank.' },
    { key: 'piutang_cash',   label: 'Piutang Konsumen Cash', info: 'Yang masih harus dibayar konsumen Cash dan Cash Bertahap: Booking Fee, DP, Pelunasan.' },
];
const infoTampilan = computed(() => tampilanList.find(t => t.key === tampilan.value)?.info);
const chips = [
    { key: 'belum_cair', label: 'Belum masuk', title: 'Hanya konsumen yang masih ada tagihan di kolom terpilih; yang sudah masuk dikosongkan.' },
    { key: 'sudah_cair', label: 'Sudah masuk', title: 'Hanya yang sudah lunas di kolom terpilih.' },
    { key: 'semua',      label: 'Semua',       title: 'Semua konsumen, dengan status tiap kolom.' },
];
const labelTotal = computed(() => ({ belum_cair: 'Total belum masuk', sudah_cair: 'Total sudah masuk', semua: 'Sisa belum masuk' }[mode.value]));
const warnaTotal = computed(() => mode.value === 'sudah_cair' ? 'text-emerald-400' : 'text-amber-400');

const grupKolom = computed(() => [
    { key: 'konsumen', judul: 'Piutang konsumen' },
    { key: 'dajam',    judul: 'Item Dajam' },
    { key: 'lainnya',  judul: 'Lainnya' },
].map(g => ({ ...g, daftar: props.kolom.filter(k => k.grup === g.key) })).filter(g => g.daftar.length));
const kolomTampil = computed(() => props.kolom.filter(k => (props.terpilih ?? []).includes(k.key)));
const jumlahIdentitas = computed(() => (tampilPiutang.value ? 4 : 3));

const statusTahap = computed(() => {
    const colors = usePage().props.statusColors?.status_penjualan ?? {};
    return (k) => `background:${colors[k] ?? '#94a3b8'}26; color:${colors[k] ?? '#94a3b8'}`;
});

const formatRp = (v) => 'Rp ' + Math.round(Number(v ?? 0)).toLocaleString('id-ID');
const angka = (v) => Math.round(Number(v ?? 0)).toLocaleString('id-ID');
const selectCls = 'w-full px-3 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500';
</script>

<template>
    <Head title="Rekap Kekurangan" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2 text-slate-400 text-sm">
                <span>Keuangan</span><span>/</span>
                <span class="text-slate-200 font-medium">Rekap Kekurangan</span>
            </div>
        </template>

        <div class="p-6 space-y-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h1 class="text-white font-bold text-xl">Rekap Kekurangan</h1>
                    <p class="text-slate-400 text-sm mt-0.5">{{ infoTampilan }}</p>
                </div>
                <a :href="exportUrl" class="inline-flex items-center gap-1.5 px-3 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs rounded-lg transition-colors flex-shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5 text-sky-400">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-4.5L12 16.5m0 0l4.5-4.5M12 16.5V3" />
                    </svg>
                    Export Excel
                </a>
            </div>

            <div class="flex flex-wrap gap-x-1 border-b border-slate-800">
                <button v-for="t in tampilanList" :key="t.key" type="button" @click="setTampilan(t.key)"
                    :class="tampilan === t.key ? 'border-violet-500 text-white' : 'border-transparent text-slate-400 hover:text-slate-200'"
                    class="px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors">
                    {{ t.label }}
                </button>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 bg-slate-900 border border-slate-800 rounded-xl px-4 py-3">
                <div class="flex items-baseline gap-8">
                    <div>
                        <div class="text-slate-500 text-[0.6875rem]">Konsumen</div>
                        <div class="text-white text-lg font-bold leading-tight">{{ summary.jumlah }}</div>
                    </div>
                    <div>
                        <div class="text-slate-500 text-[0.6875rem]">{{ labelTotal }}</div>
                        <div class="text-lg font-bold leading-tight" :class="warnaTotal">{{ formatRp(summary.total) }}</div>
                    </div>
                </div>
                <div class="inline-flex rounded-lg bg-slate-800 p-0.5">
                    <button v-for="c in chips" :key="c.key" @click="setMode(c.key)" :title="c.title"
                        :class="mode === c.key ? 'bg-violet-600 text-white shadow' : 'text-slate-400 hover:text-slate-200'"
                        class="px-3.5 py-1.5 rounded-md text-xs font-medium transition-all">
                        {{ c.label }}
                    </button>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <div class="relative flex-1 min-w-[14rem]">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"
                        class="w-4 h-4 text-slate-500 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                    </svg>
                    <input v-model="search" type="text" placeholder="Cari nama, No. HP, NIK, atau unit..."
                        class="w-full pl-9 pr-3 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-xs placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-violet-500" />
                </div>

                <PopoverPanel label="Tahap" :badge="tahapPilihan.length" :active="tahapBeda" width="w-60">
                    <div class="space-y-1">
                        <label v-for="o in tahapOptions" :key="o.key" class="flex items-center gap-2 text-slate-300 text-xs cursor-pointer py-0.5">
                            <input type="checkbox" :checked="tahapPilihan.includes(o.key)" @change="toggleTahap(o.key)"
                                class="rounded border-slate-600 bg-slate-800 text-violet-500 focus:ring-violet-500" />
                            {{ o.label }}
                        </label>
                    </div>
                    <div v-if="pintasanTahap.length" class="flex flex-wrap gap-x-3 gap-y-1 mt-3 pt-2 border-t border-slate-800 text-xs">
                        <button v-for="p in pintasanTahap" :key="p.label" type="button" @click="setTahap(p.keys)" class="text-violet-400 hover:text-violet-300">{{ p.label }}</button>
                    </div>
                </PopoverPanel>

                <select v-if="adaBank" v-model="bank" @change="go()" :class="selectCls" class="!w-auto">
                    <option value="">Semua Bank</option>
                    <option v-for="b in filterOptions.bank" :key="b.id" :value="b.id">{{ b.nama }}</option>
                </select>

                <PopoverPanel label="Filter lain" :badge="filterLainAktif || null" :active="filterLainAktif > 0" width="w-72">
                    <div class="space-y-2.5">
                        <label class="block text-slate-500 text-[0.6875rem]">Cara bayar
                            <select v-model="caraBayar" @change="go()" :class="selectCls" class="mt-1">
                                <option value="">Semua</option>
                                <option v-for="(label, key) in filterOptions.cara_bayar" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </label>
                        <label class="block text-slate-500 text-[0.6875rem]">Umur sejak akad
                            <select v-model="umur" @change="go()" :class="selectCls" class="mt-1">
                                <option value="">Semua</option>
                                <option value="30">Lebih dari 30 hari</option>
                                <option value="60">Lebih dari 60 hari</option>
                                <option value="90">Lebih dari 90 hari</option>
                            </select>
                        </label>
                        <div class="grid grid-cols-2 gap-2">
                            <label class="block text-slate-500 text-[0.6875rem]">Kluster
                                <select v-model="kluster" @change="go()" :class="selectCls" class="mt-1">
                                    <option value="">Semua</option>
                                    <option v-for="k in filterOptions.kluster" :key="k" :value="k">{{ k }}</option>
                                </select>
                            </label>
                            <label class="block text-slate-500 text-[0.6875rem]">Blok
                                <select v-model="blok" @change="go()" :class="selectCls" class="mt-1">
                                    <option value="">Semua</option>
                                    <option v-for="b in filterOptions.blok" :key="b" :value="b">{{ b }}</option>
                                </select>
                            </label>
                        </div>
                        <div class="text-slate-500 text-[0.6875rem]">Tanggal akad
                            <div class="grid grid-cols-2 gap-2 mt-1">
                                <input v-model="akadDari" @change="go()" type="date" :class="selectCls" />
                                <input v-model="akadSampai" @change="go()" type="date" :class="selectCls" />
                            </div>
                        </div>
                    </div>
                </PopoverPanel>

                <PopoverPanel label="Kolom" :badge="`${pilihan.length}/${kolom.length}`" :active="kolomBeda" align="right" width="w-72">
                    <div class="flex gap-3 text-xs mb-2">
                        <button type="button" @click="pilihSemuaKolom" class="text-violet-400 hover:text-violet-300">Pilih semua</button>
                        <button type="button" @click="kosongkanKolom" class="text-slate-400 hover:text-slate-200">Kosongkan</button>
                    </div>
                    <div class="space-y-3 max-h-72 overflow-y-auto pr-1">
                        <div v-for="g in grupKolom" :key="g.key">
                            <div class="text-slate-500 text-[0.625rem] font-semibold uppercase tracking-wide mb-1">{{ g.judul }}</div>
                            <label v-for="k in g.daftar" :key="k.key" class="flex items-center gap-2 text-slate-300 text-xs cursor-pointer py-0.5">
                                <input type="checkbox" :checked="pilihan.includes(k.key)" @change="toggleKolom(k.key)"
                                    class="rounded border-slate-600 bg-slate-800 text-violet-500 focus:ring-violet-500" />
                                <span class="flex-1">{{ k.label }}</span>
                                <span class="text-slate-500">{{ k.jumlah }}</span>
                            </label>
                        </div>
                    </div>
                </PopoverPanel>

                <button v-if="hasFilter" @click="resetFilters" class="px-2 py-1.5 text-slate-500 hover:text-slate-300 text-xs transition-colors">✕ Reset</button>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-slate-800 text-xs text-slate-500 uppercase tracking-wide">
                            <tr>
                                <SortTh label="Konsumen" sort-key="nama" :current="sort" :dir="dir" first-dir="asc" class="sticky left-0 z-10 bg-slate-900 min-w-[11rem]" @sort="setSort" />
                                <SortTh label="Unit" sort-key="unit" :current="sort" :dir="dir" first-dir="asc" @sort="setSort" />
                                <th v-if="tampilPiutang" class="px-4 py-3 text-left font-medium">Tahap</th>
                                <SortTh :label="tampilPiutang ? 'Akad / Rencana' : 'Tgl Akad'" sort-key="akad" :current="sort" :dir="dir" first-dir="asc" @sort="setSort" />
                                <SortTh v-for="k in kolomTampil" :key="k.key" :label="k.label" :sort-key="k.key" :current="sort" :dir="dir" align="right" @sort="setSort" />
                                <SortTh :label="labelTotal" sort-key="total" :current="sort" :dir="dir" align="right" @sort="setSort" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in rows.data" :key="row.id" class="border-b border-slate-800/50 hover:bg-slate-800/30 transition-colors">
                                <td class="px-4 py-3 text-slate-200 font-medium sticky left-0 z-10 bg-slate-900">
                                    <Link :href="route('keuangan.detail', row.id)" class="hover:text-violet-300 transition-colors">{{ row.nama }}</Link>
                                </td>
                                <td class="px-4 py-3 text-slate-400 whitespace-nowrap">
                                    {{ row.unit }}
                                    <div class="text-slate-500 text-xs">{{ row.project_nama }}</div>
                                </td>
                                <td v-if="tampilPiutang" class="px-4 py-3 whitespace-nowrap">
                                    <span class="px-2 py-0.5 text-xs rounded-full font-medium" :style="statusTahap(row.tahap)">{{ row.tahap_label }}</span>
                                </td>
                                <td class="px-4 py-3 text-xs whitespace-nowrap">
                                    <div v-if="row.akad" class="text-slate-300">{{ row.akad }}</div>
                                    <div v-else-if="row.rencana" class="text-slate-400 italic">Rencana {{ row.rencana }}</div>
                                    <div v-else class="text-slate-500">-</div>
                                    <div v-if="adaBank" class="text-slate-500 text-[0.625rem]">{{ row.bank ?? 'Bank belum diisi' }}</div>
                                </td>
                                <td v-for="k in kolomTampil" :key="k.key" class="px-4 py-3 text-right whitespace-nowrap">
                                    <RekapSel :sel="row.sel[k.key]" :mode="mode" />
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap font-semibold tabular-nums" :class="row.total > 0 ? warnaTotal : 'text-slate-500'">{{ angka(row.total) }}</td>
                            </tr>
                            <tr v-if="!rows.data.length">
                                <td :colspan="jumlahIdentitas + kolomTampil.length + 1">
                                    <EmptyState v-if="!terpilih.length" title="Pilih minimal satu kolom"
                                        description="Buka tombol Kolom di atas, lalu centang yang ingin direkap." />
                                    <EmptyState v-else-if="!tahapPilihan.length" title="Pilih minimal satu tahap"
                                        description="Buka tombol Tahap di atas, lalu centang tahap penjualan yang ingin ditampilkan." />
                                    <EmptyState v-else :filtered="hasFilter || mode !== 'semua'"
                                        :title="mode === 'belum_cair' ? 'Tidak ada kekurangan di kolom terpilih' : 'Tidak ada data yang cocok'"
                                        :description="mode === 'belum_cair' ? 'Semua kolom terpilih sudah masuk untuk konsumen yang lolos filter.' : 'Coba ubah status, kolom, atau filter.'"
                                        @reset="resetFilters" />
                                </td>
                            </tr>
                        </tbody>
                        <tfoot v-if="rows.data.length">
                            <tr class="border-t border-slate-700 bg-slate-800/40 text-slate-200 font-semibold">
                                <td class="px-4 py-3 sticky left-0 z-10 bg-slate-800 text-xs uppercase tracking-wide text-slate-400" :colspan="jumlahIdentitas">Total seluruh hasil filter</td>
                                <td v-for="k in kolomTampil" :key="k.key" class="px-4 py-3 text-right tabular-nums whitespace-nowrap">{{ angka(summary.total_kolom[k.key]) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap" :class="warnaTotal">{{ angka(summary.total) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <Pagination :paginator="rows" />
        </div>
    </AuthenticatedLayout>
</template>
