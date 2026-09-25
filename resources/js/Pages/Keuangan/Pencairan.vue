<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SortTh from '@/Components/SortTh.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';

// Pencairan KPR — dana bank/pemerintah untuk konsumen KPR. Dipaginasi & disort di server.
const props = defineProps({
    rows:          Object, // paginated
    summary:       Object,
    filterOptions: Object,
    filters:       Object,
});

const search    = ref(props.filters?.search ?? '');
const kluster   = ref(props.filters?.kluster ?? '');
const blok      = ref(props.filters?.blok ?? '');
const caraBayar = ref(props.filters?.cara_bayar ?? '');
const bank      = ref(props.filters?.bank ?? '');

const tampil    = computed(() => props.filters?.tampil ?? 'belum_cair');
const belumAkad = computed(() => props.filters?.belum_akad === '1');
const sort      = computed(() => props.filters?.sort ?? 'sisa');
const dir       = computed(() => props.filters?.dir ?? 'desc');

const go = (override = {}) => {
    router.get(route('keuangan.pencairan'), {
        search: search.value || undefined,
        kluster: kluster.value || undefined,
        blok: blok.value || undefined,
        cara_bayar: caraBayar.value || undefined,
        bank: bank.value || undefined,
        tampil: tampil.value,
        belum_akad: belumAkad.value ? '1' : undefined,
        sort: sort.value,
        dir: dir.value,
        ...override,
    }, { preserveState: true, replace: true });
};
const setSort = (key, direction) => go({ sort: key, dir: direction });
const setTampil = (value) => go({ tampil: value });
const toggleBelumAkad = () => go({ belum_akad: belumAkad.value ? undefined : '1' });

let searchDebounce = null;
watch(search, () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => go({ tampil: search.value ? undefined : 'belum_cair' }), 300);
});
watch([kluster, blok, caraBayar, bank], () => go());

const resetFilters = () => {
    search.value = ''; kluster.value = ''; blok.value = ''; caraBayar.value = ''; bank.value = '';
};
const hasFilter = computed(() => search.value || kluster.value || blok.value || caraBayar.value || bank.value);

const chips = [
    { key: 'belum_cair', label: 'Belum cair penuh' },
    { key: 'cair_penuh', label: 'Sudah cair penuh' },
    { key: 'semua',      label: 'Semua' },
];

const formatRp = (v) => 'Rp ' + Number(v ?? 0).toLocaleString('id-ID');
const pct = (paid, total) => total > 0 ? Math.max(0, Math.min(100, Math.round((Number(paid) / Number(total)) * 100))) : 0;

const statusPenjualanConfig = computed(() => {
    const colors = usePage().props.statusColors?.status_penjualan ?? {};
    return Object.fromEntries(['booking', 'pemberkasan', 'proses_bank', 'sp3k', 'rencana_akad', 'akad', 'bast', 'batal']
        .map(k => [k, { style: `background:${colors[k] ?? '#94a3b8'}26; color:${colors[k] ?? '#94a3b8'}` }]));
});
</script>

<template>
    <Head title="Pencairan KPR" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2 text-slate-400 text-sm">
                <span>Keuangan</span><span>/</span>
                <span class="text-slate-200 font-medium">Pencairan KPR</span>
            </div>
        </template>

        <div class="p-6 space-y-5">
            <div>
                <h1 class="text-white font-bold text-xl">Pencairan KPR</h1>
                <p class="text-slate-400 text-sm mt-0.5">Dana bank &amp; pemerintah (pencairan KPR, SBUM, Dana Jaminan) khusus konsumen KPR. Default: yang sudah Akad dan belum cair penuh.</p>
            </div>

            <div class="grid grid-cols-2 lg:grid-cols-3 gap-3">
                <div class="bg-slate-900 border border-slate-800 rounded-xl px-4 py-3">
                    <div class="text-slate-500 text-[11px]">Jumlah transaksi</div>
                    <div class="text-white text-lg font-bold mt-0.5">{{ summary.jumlah }}</div>
                </div>
                <div class="bg-slate-900 border border-slate-800 rounded-xl px-4 py-3">
                    <div class="text-slate-500 text-[11px]">Total sisa pencairan</div>
                    <div class="text-amber-400 text-lg font-bold mt-0.5">{{ formatRp(summary.total_sisa) }}</div>
                </div>
                <div class="bg-slate-900 border border-slate-800 rounded-xl px-4 py-3">
                    <div class="text-slate-500 text-[11px]">Lewat 30 hari sejak akad, belum cair penuh</div>
                    <div class="text-lg font-bold mt-0.5" :class="Number(summary.lewat_30_hari) > 0 ? 'text-rose-400' : 'text-slate-300'">{{ summary.lewat_30_hari }} konsumen</div>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-1.5">
                <button v-for="c in chips" :key="c.key" @click="setTampil(c.key)"
                    :class="tampil === c.key ? 'bg-violet-600 text-white shadow-lg shadow-violet-500/20' : 'bg-slate-900 text-slate-400 hover:text-slate-200 border border-slate-800'"
                    class="px-3.5 py-1.5 rounded-lg text-xs font-medium transition-all">
                    {{ c.label }}
                </button>
                <label class="ml-2 inline-flex items-center gap-2 text-slate-400 text-xs cursor-pointer">
                    <input type="checkbox" :checked="belumAkad" @change="toggleBelumAkad" class="rounded border-slate-600 bg-slate-800 text-violet-500 focus:ring-violet-500" />
                    Termasuk yang belum akad
                </label>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 space-y-3">
                <div class="relative">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"
                        class="w-4 h-4 text-slate-500 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                    </svg>
                    <input v-model="search" type="text" placeholder="Cari nama konsumen, No. HP, NIK, atau nomor unit (mencakup semua tahap & status)..."
                        class="w-full pl-9 pr-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-violet-500" />
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <select v-model="kluster" class="px-3 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                        <option value="">Semua Kluster</option>
                        <option v-for="k in filterOptions.kluster" :key="k" :value="k">{{ k }}</option>
                    </select>
                    <select v-model="blok" class="px-3 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                        <option value="">Semua Blok</option>
                        <option v-for="b in filterOptions.blok" :key="b" :value="b">Blok {{ b }}</option>
                    </select>
                    <select v-model="caraBayar" class="px-3 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                        <option value="">Subsidi &amp; Komersil</option>
                        <option v-for="(label, key) in filterOptions.cara_bayar" :key="key" :value="key">{{ label }}</option>
                    </select>
                    <select v-model="bank" class="px-3 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                        <option value="">Semua Bank</option>
                        <option v-for="b in filterOptions.bank" :key="b" :value="b">{{ b }}</option>
                    </select>
                    <button v-if="hasFilter" @click="resetFilters" class="px-3 py-1.5 text-slate-500 hover:text-slate-300 text-xs transition-colors">✕ Reset filter</button>
                </div>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-slate-800 text-xs text-slate-500 uppercase tracking-wide">
                            <tr>
                                <SortTh label="Konsumen" sort-key="nama" :current="sort" :dir="dir" first-dir="asc" @sort="setSort" />
                                <SortTh label="Unit" sort-key="unit" :current="sort" :dir="dir" first-dir="asc" @sort="setSort" />
                                <th class="px-4 py-3 text-left font-medium">Tahap</th>
                                <SortTh label="Bank" sort-key="bank" :current="sort" :dir="dir" first-dir="asc" @sort="setSort" />
                                <SortTh label="Tgl Akad" sort-key="akad" :current="sort" :dir="dir" first-dir="asc" @sort="setSort" />
                                <SortTh label="Plafon KPR" sort-key="plafon" :current="sort" :dir="dir" align="right" @sort="setSort" />
                                <SortTh label="Progress Cair" sort-key="persen" :current="sort" :dir="dir" @sort="setSort" />
                                <SortTh label="Sisa Pencairan" sort-key="sisa" :current="sort" :dir="dir" align="right" @sort="setSort" />
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in rows.data" :key="row.id" class="border-b border-slate-800/50 hover:bg-slate-800/30 transition-colors">
                                <td class="px-4 py-3 text-slate-200 font-medium">{{ row.konsumen_nama }}</td>
                                <td class="px-4 py-3 text-slate-400">
                                    {{ row.kavling_nomor }}
                                    <div class="text-slate-600 text-xs">{{ row.project_nama }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-0.5 text-xs rounded-full font-medium" :style="statusPenjualanConfig[row.status_penjualan]?.style">{{ row.status_penjualan_label }}</span>
                                </td>
                                <td class="px-4 py-3 text-slate-400">
                                    {{ row.bank_rekanan_kpr ?? '-' }}
                                    <div class="text-slate-600 text-xs">{{ row.cara_bayar_label }}</div>
                                </td>
                                <td class="px-4 py-3 text-xs whitespace-nowrap">
                                    <template v-if="row.tanggal_akad">
                                        <div class="text-slate-400">{{ row.tanggal_akad }}</div>
                                        <div class="text-[10px]" :class="row.hari_sejak_akad > 30 && row.sisa > 0.009 ? 'text-rose-400' : 'text-slate-600'">{{ row.hari_sejak_akad }} hari lalu</div>
                                    </template>
                                    <span v-else class="text-slate-600">-</span>
                                </td>
                                <td class="px-4 py-3 text-slate-300 text-right whitespace-nowrap">{{ row.plafon_kpr !== null ? formatRp(row.plafon_kpr) : '-' }}</td>
                                <td class="px-4 py-3">
                                    <div class="text-slate-300 text-xs font-medium whitespace-nowrap">{{ formatRp(row.total_terbayar) }} / {{ formatRp(row.total_piutang) }}</div>
                                    <div class="h-1.5 bg-slate-800 rounded-full overflow-hidden mt-1 w-32">
                                        <div class="h-full bg-gradient-to-r from-emerald-500 to-teal-500 rounded-full" :style="`width: ${pct(row.total_terbayar, row.total_piutang)}%`" />
                                    </div>
                                    <div class="text-slate-600 text-[10px] mt-0.5">{{ pct(row.total_terbayar, row.total_piutang) }}%</div>
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <div v-if="row.sisa > 0.009" class="text-amber-400 font-medium">{{ formatRp(row.sisa) }}</div>
                                    <div v-else-if="row.sisa < -0.009" class="text-amber-400 font-medium">Kelebihan {{ formatRp(-row.sisa) }}</div>
                                    <div v-else class="text-emerald-500/80 text-xs">Cair penuh</div>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <Link :href="route('keuangan.detail', row.id)" title="Lihat Detail &amp; catat pencairan"
                                        class="inline-flex items-center justify-center w-7 h-7 rounded-lg text-slate-500 hover:text-violet-400 hover:bg-slate-800 transition-colors">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        </svg>
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="!rows.data.length">
                                <td colspan="9" class="px-4 py-12 text-center text-slate-600">Tidak ada transaksi KPR untuk filter ini.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="rows.last_page > 1" class="flex flex-wrap justify-center gap-1">
                <Link v-for="link in rows.links" :key="link.label" :href="link.url ?? '#'" v-html="link.label"
                    :class="['px-3 py-1.5 text-xs rounded-md transition-colors',
                        link.active ? 'bg-violet-600 text-white' : 'text-slate-400 hover:bg-slate-800 bg-slate-900',
                        !link.url ? 'opacity-40 pointer-events-none' : '']" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
