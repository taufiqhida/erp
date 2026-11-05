<script setup>
import Pagination from '@/Components/Pagination.vue';
import EmptyState from '@/Components/EmptyState.vue';
import RekapSel from '@/Components/RekapSel.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SortTh from '@/Components/SortTh.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';

// Rekap Kekurangan Pencairan — satu baris per konsumen KPR sudah akad, kolom per komponen dana bank (item Dajam, SBUM, Pencairan netto,
// belum dirinci). Mode + kolom terpilih menentukan baris & isi sel; disort dan dipaginasi di server.
const props = defineProps({
    rows:          Object, // paginated
    kolom:         Array,  // [{key, label, grup, jumlah}] semua kolom yang bisa dipilih
    terpilih:      Array,  // kunci kolom yang sedang tampil
    summary:       Object,
    filterOptions: Object,
    filters:       Object,
});

const search    = ref(props.filters?.search ?? '');
const kluster   = ref(props.filters?.kluster ?? '');
const blok      = ref(props.filters?.blok ?? '');
const caraBayar = ref(props.filters?.cara_bayar ?? '');
const bank      = ref(props.filters?.bank ?? '');
const tahap     = ref(props.filters?.tahap ?? '');
const umur      = ref(props.filters?.umur ?? '');
const akadDari  = ref(props.filters?.akad_dari ?? '');
const akadSampai = ref(props.filters?.akad_sampai ?? '');

const mode = computed(() => props.filters?.mode ?? 'belum_cair');
const sort = computed(() => props.filters?.sort ?? 'total');
const dir  = computed(() => props.filters?.dir ?? 'desc');

const pilihan = ref([...(props.terpilih ?? [])]);
const bukaKolom = ref(false);
watch(() => props.terpilih, (v) => {
    if (JSON.stringify(v) !== JSON.stringify(pilihan.value)) pilihan.value = [...v];
});

const kolomDajam = computed(() => props.kolom.filter(k => k.grup === 'dajam'));
const kolomLain  = computed(() => props.kolom.filter(k => k.grup === 'lainnya'));
const kolomTampil = computed(() => props.kolom.filter(k => (props.terpilih ?? []).includes(k.key)));

const go = (override = {}) => {
    const semua = pilihan.value.length === props.kolom.length;
    router.get(route('keuangan.rekap-kekurangan'), {
        search: search.value || undefined,
        kluster: kluster.value || undefined,
        blok: blok.value || undefined,
        cara_bayar: caraBayar.value || undefined,
        bank: bank.value || undefined,
        tahap: tahap.value || undefined,
        umur: umur.value || undefined,
        akad_dari: akadDari.value || undefined,
        akad_sampai: akadSampai.value || undefined,
        // Tanpa parameter = semua kolom; "-" = tidak ada kolom dipilih.
        kolom: semua ? undefined : (pilihan.value.length ? pilihan.value : ['-']),
        mode: mode.value,
        sort: sort.value,
        dir: dir.value,
        ...override,
    }, { preserveState: true, replace: true });
};
const setSort = (key, direction) => go({ sort: key, dir: direction });
const setMode = (m) => go({ mode: m });

const exportUrl = computed(() => {
    const params = new URLSearchParams();
    Object.entries({
        search: search.value, kluster: kluster.value, blok: blok.value, cara_bayar: caraBayar.value, bank: bank.value,
        tahap: tahap.value, umur: umur.value, akad_dari: akadDari.value, akad_sampai: akadSampai.value,
        mode: mode.value, sort: sort.value, dir: dir.value,
    }).forEach(([k, v]) => { if (v) params.set(k, v); });
    if (pilihan.value.length !== props.kolom.length) {
        (pilihan.value.length ? pilihan.value : ['-']).forEach(k => params.append('kolom[]', k));
    }
    return route('keuangan.rekap-kekurangan.export') + '?' + params.toString();
});

let debounce = null;
watch(search, () => { clearTimeout(debounce); debounce = setTimeout(() => go(), 300); });
watch([kluster, blok, caraBayar, bank, tahap, umur, akadDari, akadSampai], () => go());
watch(pilihan, () => { clearTimeout(debounce); debounce = setTimeout(() => go(), 350); }, { deep: true });

const pilihSemua = () => { pilihan.value = props.kolom.map(k => k.key); };
const kosongkan = () => { pilihan.value = []; };

const resetFilters = () => {
    search.value = ''; kluster.value = ''; blok.value = ''; caraBayar.value = ''; bank.value = '';
    tahap.value = ''; umur.value = ''; akadDari.value = ''; akadSampai.value = '';
};
const hasFilter = computed(() => search.value || kluster.value || blok.value || caraBayar.value || bank.value || tahap.value || umur.value || akadDari.value || akadSampai.value);

const chips = [
    { key: 'belum_cair', label: 'Belum cair' },
    { key: 'sudah_cair', label: 'Sudah cair' },
    { key: 'semua',      label: 'Semua' },
];
const labelTotal = computed(() => ({ belum_cair: 'Total belum cair', sudah_cair: 'Total sudah cair', semua: 'Sisa belum cair' }[mode.value]));
const warnaTotal = computed(() => mode.value === 'sudah_cair' ? 'text-emerald-400' : 'text-amber-400');

const formatRp = (v) => 'Rp ' + Math.round(Number(v ?? 0)).toLocaleString('id-ID');
const angka = (v) => Math.round(Number(v ?? 0)).toLocaleString('id-ID');
</script>

<template>
    <Head title="Rekap Kekurangan Pencairan" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2 text-slate-400 text-sm">
                <span>Keuangan</span><span>/</span>
                <span class="text-slate-200 font-medium">Rekap Kekurangan</span>
            </div>
        </template>

        <div class="p-6 space-y-5">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h1 class="text-white font-bold text-xl">Rekap Kekurangan Pencairan</h1>
                    <p class="text-slate-400 text-sm mt-0.5">Per konsumen KPR yang sudah akad: apa saja yang masih ditahan bank (Dajam, SBUM, pencairan netto). Pilih kolom yang ingin dilihat, lalu ekspor untuk pengajuan ke bank.</p>
                </div>
                <a :href="exportUrl" class="inline-flex items-center gap-1.5 px-3 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs rounded-lg transition-colors flex-shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5 text-sky-400">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-4.5L12 16.5m0 0l4.5-4.5M12 16.5V3" />
                    </svg>
                    Export Excel
                </a>
            </div>

            <div class="grid grid-cols-2 lg:grid-cols-3 gap-3">
                <div class="bg-slate-900 border border-slate-800 rounded-xl px-4 py-3">
                    <div class="text-slate-500 text-[0.6875rem]">Jumlah konsumen</div>
                    <div class="text-white text-lg font-bold mt-0.5">{{ summary.jumlah }}</div>
                </div>
                <div class="bg-slate-900 border border-slate-800 rounded-xl px-4 py-3">
                    <div class="text-slate-500 text-[0.6875rem]">{{ labelTotal }}</div>
                    <div class="text-lg font-bold mt-0.5" :class="warnaTotal">{{ formatRp(summary.total) }}</div>
                </div>
                <div class="bg-slate-900 border border-slate-800 rounded-xl px-4 py-3 col-span-2 lg:col-span-1">
                    <div class="text-slate-500 text-[0.6875rem]">Kolom ditampilkan</div>
                    <div class="text-slate-200 text-lg font-bold mt-0.5">{{ terpilih.length }} <span class="text-slate-500 text-sm font-normal">dari {{ kolom.length }}</span></div>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-1.5">
                <button v-for="c in chips" :key="c.key" @click="setMode(c.key)"
                    :class="mode === c.key ? 'bg-violet-600 text-white shadow-lg shadow-violet-500/20' : 'bg-slate-900 text-slate-400 hover:text-slate-200 border border-slate-800'"
                    class="px-3.5 py-1.5 rounded-lg text-xs font-medium transition-all">
                    {{ c.label }}
                </button>
                <span class="ml-2 text-slate-500 text-xs">
                    <template v-if="mode === 'belum_cair'">Hanya konsumen yang masih ada tagihan di kolom terpilih; item yang sudah cair dikosongkan.</template>
                    <template v-else-if="mode === 'sudah_cair'">Hanya yang sudah cair penuh di kolom terpilih.</template>
                    <template v-else>Semua konsumen, dengan status tiap kolom.</template>
                </span>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 space-y-3">
                <div class="relative">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"
                        class="w-4 h-4 text-slate-500 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                    </svg>
                    <input v-model="search" type="text" placeholder="Cari nama konsumen, No. HP, NIK, atau nomor unit..."
                        class="w-full pl-9 pr-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-violet-500" />
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <select v-model="bank" class="px-3 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                        <option value="">Semua Bank</option>
                        <option v-for="b in filterOptions.bank" :key="b.id" :value="b.id">{{ b.nama }}</option>
                    </select>
                    <select v-model="caraBayar" class="px-3 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                        <option value="">Subsidi &amp; Komersil</option>
                        <option v-for="(label, key) in filterOptions.cara_bayar" :key="key" :value="key">{{ label }}</option>
                    </select>
                    <select v-model="tahap" class="px-3 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                        <option value="">Akad &amp; BAST</option>
                        <option value="akad">Akad</option>
                        <option value="bast">BAST</option>
                    </select>
                    <select v-model="umur" class="px-3 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                        <option value="">Semua umur sejak akad</option>
                        <option value="30">Lebih dari 30 hari</option>
                        <option value="60">Lebih dari 60 hari</option>
                        <option value="90">Lebih dari 90 hari</option>
                    </select>
                    <select v-model="kluster" class="px-3 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                        <option value="">Semua Kluster</option>
                        <option v-for="k in filterOptions.kluster" :key="k" :value="k">{{ k }}</option>
                    </select>
                    <select v-model="blok" class="px-3 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                        <option value="">Semua Blok</option>
                        <option v-for="b in filterOptions.blok" :key="b" :value="b">Blok {{ b }}</option>
                    </select>
                    <label class="inline-flex items-center gap-1.5 text-slate-500 text-xs">Akad
                        <input v-model="akadDari" type="date" class="px-2 py-1 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
                        s/d
                        <input v-model="akadSampai" type="date" class="px-2 py-1 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
                    </label>
                    <button v-if="hasFilter" @click="resetFilters" class="px-3 py-1.5 text-slate-500 hover:text-slate-300 text-xs transition-colors">✕ Reset filter</button>
                </div>

                <div class="border-t border-slate-800 pt-3">
                    <button type="button" @click="bukaKolom = !bukaKolom" class="inline-flex items-center gap-1.5 text-slate-300 hover:text-white text-xs font-medium">
                        <span>{{ bukaKolom ? '▾' : '▸' }}</span> Kolom yang ditampilkan ({{ pilihan.length }}/{{ kolom.length }})
                    </button>
                    <div v-if="bukaKolom" class="mt-3 space-y-3">
                        <div class="flex gap-3 text-xs">
                            <button type="button" @click="pilihSemua" class="text-violet-400 hover:text-violet-300">Pilih semua</button>
                            <button type="button" @click="kosongkan" class="text-slate-400 hover:text-slate-200">Kosongkan</button>
                        </div>
                        <div v-if="kolomDajam.length">
                            <div class="text-slate-500 text-[0.6875rem] font-semibold uppercase tracking-wide mb-1.5">Item Dajam</div>
                            <div class="flex flex-wrap gap-x-5 gap-y-1.5">
                                <label v-for="k in kolomDajam" :key="k.key" class="inline-flex items-center gap-2 text-slate-300 text-xs cursor-pointer">
                                    <input type="checkbox" v-model="pilihan" :value="k.key" class="rounded border-slate-600 bg-slate-800 text-violet-500 focus:ring-violet-500" />
                                    {{ k.label }} <span class="text-slate-500">({{ k.jumlah }})</span>
                                </label>
                            </div>
                        </div>
                        <div>
                            <div class="text-slate-500 text-[0.6875rem] font-semibold uppercase tracking-wide mb-1.5">Lainnya</div>
                            <div class="flex flex-wrap gap-x-5 gap-y-1.5">
                                <label v-for="k in kolomLain" :key="k.key" class="inline-flex items-center gap-2 text-slate-300 text-xs cursor-pointer">
                                    <input type="checkbox" v-model="pilihan" :value="k.key" class="rounded border-slate-600 bg-slate-800 text-violet-500 focus:ring-violet-500" />
                                    {{ k.label }} <span class="text-slate-500">({{ k.jumlah }})</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-slate-800 text-xs text-slate-500 uppercase tracking-wide">
                            <tr>
                                <SortTh label="Konsumen" sort-key="nama" :current="sort" :dir="dir" first-dir="asc" class="sticky left-0 z-10 bg-slate-900 min-w-[11rem]" @sort="setSort" />
                                <SortTh label="Unit" sort-key="unit" :current="sort" :dir="dir" first-dir="asc" @sort="setSort" />
                                <SortTh label="Tgl Akad" sort-key="akad" :current="sort" :dir="dir" first-dir="asc" @sort="setSort" />
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
                                <td class="px-4 py-3 text-xs whitespace-nowrap">
                                    <template v-if="row.akad">
                                        <div class="text-slate-300">{{ row.akad }}</div>
                                        <div class="text-slate-500 text-[0.625rem]">{{ row.bank ?? 'Bank belum diisi' }}</div>
                                    </template>
                                    <span v-else class="text-slate-500">-</span>
                                </td>
                                <td v-for="k in kolomTampil" :key="k.key" class="px-4 py-3 text-right whitespace-nowrap">
                                    <RekapSel :sel="row.sel[k.key]" :mode="mode" />
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap font-semibold tabular-nums" :class="row.total > 0 ? warnaTotal : 'text-slate-500'">{{ angka(row.total) }}</td>
                            </tr>
                            <tr v-if="!rows.data.length">
                                <td :colspan="4 + kolomTampil.length">
                                    <EmptyState v-if="!terpilih.length" title="Pilih minimal satu kolom"
                                        description="Buka &quot;Kolom yang ditampilkan&quot; di atas lalu centang item Dajam, SBUM, atau pencairan yang ingin direkap." />
                                    <EmptyState v-else :filtered="!!hasFilter || mode !== 'semua'"
                                        :title="mode === 'belum_cair' ? 'Tidak ada kekurangan di kolom terpilih' : 'Tidak ada data yang cocok'"
                                        :description="mode === 'belum_cair' ? 'Semua kolom terpilih sudah cair untuk konsumen yang lolos filter.' : 'Coba ubah mode, kolom, atau filter.'"
                                        @reset="resetFilters" />
                                </td>
                            </tr>
                        </tbody>
                        <tfoot v-if="rows.data.length">
                            <tr class="border-t border-slate-700 bg-slate-800/40 text-slate-200 font-semibold">
                                <td class="px-4 py-3 sticky left-0 z-10 bg-slate-800 text-xs uppercase tracking-wide text-slate-400" colspan="3">Total seluruh hasil filter</td>
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
