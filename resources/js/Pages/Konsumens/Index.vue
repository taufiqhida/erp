<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';

const page = usePage();

const props = defineProps({
    mode:          String, // 'unit' | 'konsumen'
    rows:          Object, // paginated
    filterOptions: Object,
    filters:       Object,
});

const search      = ref(props.filters?.search ?? '');
const kluster     = ref(props.filters?.kluster ?? '');
const blok        = ref(props.filters?.blok ?? '');
const tipeUnit    = ref(props.filters?.tipe_unit_preset_id ?? '');
const statusJual  = ref(props.filters?.status_jual ?? '');
const statusBangun = ref(props.filters?.status_bangun_stage_id ?? '');
const statusPenjualan = ref(props.filters?.status_penjualan ?? '');

const applyFilter = () => {
    router.get(
        route('konsumens.index'),
        {
            view: props.mode,
            search: search.value || undefined,
            kluster: kluster.value || undefined,
            blok: blok.value || undefined,
            tipe_unit_preset_id: tipeUnit.value || undefined,
            status_jual: statusJual.value || undefined,
            status_bangun_stage_id: statusBangun.value || undefined,
            status_penjualan: statusPenjualan.value || undefined,
        },
        { preserveState: true, replace: true }
    );
};

let searchTimer;
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilter, 400);
});
watch([kluster, blok, tipeUnit, statusJual, statusBangun, statusPenjualan], applyFilter);

const resetFilters = () => {
    kluster.value = '';
    blok.value = '';
    tipeUnit.value = '';
    statusJual.value = '';
    statusBangun.value = '';
    statusPenjualan.value = '';
};

// "Hari berjalan" / countdown pipeline (dari KavlingKonsumen::pipeline_progress_info)
const formatPipelineProgress = (p) => {
    if (!p) return '';
    if (p.type === 'elapsed') return `${p.hari} ${p.label}`;
    if (p.type === 'countdown') return p.hari >= 0 ? `${p.tanggal} (${p.hari} hari lagi)` : `${p.tanggal} (lewat ${Math.abs(p.hari)} hari)`;
    return '';
};

const switchMode = (mode) => {
    if (mode === props.mode) return;
    router.get(route('konsumens.index'), {
        view: mode,
        search: search.value || undefined,
    }, { preserveState: false });
};

const deleteKonsumen = (konsumen) => {
    if (!confirm(`Hapus konsumen "${konsumen.nama}"?`)) return;
    router.delete(route('konsumens.destroy', konsumen.id));
};

const formatRp = (v) => v
    ? 'Rp ' + Number(v).toLocaleString('id-ID')
    : '-';

const initials = (nama) => nama
    ? nama.split(' ').slice(0, 2).map(n => n[0]).join('').toUpperCase()
    : '?';

// Warna Status Jual & Pipeline — sumbernya master "Warna Status" (Pengaturan),
// nama status tetap system-driven, cuma warnanya dinamis (page.props.statusColors).
const STATUS_JUAL_LABELS = {
    available: 'Tersedia', hold: 'Tidak Tersedia', booked: 'Dipesan',
    sold: 'Terjual', cancellation_requested: 'Proses Pembatalan',
};
const STATUS_PENJUALAN_LABELS = {
    booking: 'Booking', pemberkasan: 'Pemberkasan', proses_bank: 'Proses Bank/SLIK',
    sp3k: 'SP3K', rencana_akad: 'Rencana Akad', akad: 'Akad', bast: 'BAST / Selesai', batal: 'Batal',
};
const statusJualBadgeStyle = computed(() => {
    const colors = page.props.statusColors?.status_jual ?? {};
    return Object.fromEntries(Object.keys(STATUS_JUAL_LABELS).map(k => [k, `background:${colors[k] ?? '#94a3b8'}26; color:${colors[k] ?? '#94a3b8'}`]));
});
const statusJualLegend = computed(() => {
    const colors = page.props.statusColors?.status_jual ?? {};
    return Object.fromEntries(Object.entries(STATUS_JUAL_LABELS).map(([k, label]) => [k, { label, dotStyle: `background:${colors[k] ?? '#94a3b8'}` }]));
});

// Status Bangun — sumbernya master preset live (filterOptions.status_bangun,
// dikirim dari Pengaturan > Status Bangun via KonsumenController), warna
// badge dibentuk inline dari hex `warna` masing2 tahap, bukan class hardcode.
const statusBangunColorHex = computed(() =>
    Object.fromEntries((props.filterOptions?.status_bangun ?? []).map(s => [s.id, s.warna]))
);

const statusPenjualanConfig = computed(() => {
    const colors = page.props.statusColors?.status_penjualan ?? {};
    return Object.fromEntries(Object.entries(STATUS_PENJUALAN_LABELS).map(([k, label]) => {
        const hex = colors[k] ?? '#94a3b8';
        return [k, { label, style: `background:${hex}26; color:${hex}` }];
    }));
});

const showLegend = ref(false);

const expandedKonsumen = ref(null);
const toggleExpand = (id) => { expandedKonsumen.value = expandedKonsumen.value === id ? null : id; };
</script>

<template>
    <Head title="Konsumen" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2 text-slate-400 text-sm">
                <span class="text-slate-200 font-medium">Konsumen</span>
            </div>
        </template>

        <div class="p-6 space-y-5">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-white font-bold text-xl">Data Konsumen</h1>
                    <p class="text-slate-400 text-sm mt-0.5">Daftar semua konsumen &amp; unit properti — konsumen baru ditambahkan lewat form Booking di halaman Penjualan.</p>
                </div>
            </div>

            <!-- View Mode Toggle -->
            <div class="flex gap-1 bg-slate-900 border border-slate-800 rounded-xl p-1 w-fit">
                <button @click="switchMode('unit')"
                    :class="mode === 'unit' ? 'bg-violet-600 text-white shadow-lg shadow-violet-500/20' : 'text-slate-400 hover:text-slate-200'"
                    class="px-4 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap">
                    🏠 Per Unit
                </button>
                <button @click="switchMode('konsumen')"
                    :class="mode === 'konsumen' ? 'bg-violet-600 text-white shadow-lg shadow-violet-500/20' : 'text-slate-400 hover:text-slate-200'"
                    class="px-4 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap">
                    👤 Per Konsumen
                </button>
            </div>

            <!-- Search + Filter -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 space-y-3">
                <div class="relative max-w-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                    </svg>
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Cari nama, NIK, HP..."
                        class="w-full pl-9 pr-4 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-violet-500 focus:border-violet-500"
                    />
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
                    <select v-model="tipeUnit" class="px-3 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                        <option value="">Semua Tipe</option>
                        <option v-for="t in filterOptions.tipe_unit" :key="t.id" :value="t.id">{{ t.nama }}</option>
                    </select>
                    <select v-model="statusJual" class="px-3 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                        <option value="">Semua Status Jual</option>
                        <option v-for="(label, key) in filterOptions.status_jual" :key="key" :value="key">{{ label }}</option>
                    </select>
                    <select v-model="statusBangun" class="px-3 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                        <option value="">Semua Status Bangun</option>
                        <option v-for="s in filterOptions.status_bangun" :key="s.id" :value="s.id">{{ s.nama }}</option>
                    </select>
                    <select v-model="statusPenjualan" class="px-3 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                        <option value="">Semua Pipeline</option>
                        <option v-for="s in filterOptions.status_penjualan" :key="s.key" :value="s.key">{{ s.label }}</option>
                    </select>
                    <button v-if="kluster || blok || tipeUnit || statusJual || statusBangun || statusPenjualan" @click="resetFilters"
                        class="px-3 py-1.5 text-slate-500 hover:text-slate-300 text-xs transition-colors">
                        ✕ Reset filter unit
                    </button>
                    <button @click="showLegend = !showLegend"
                        class="ml-auto px-3 py-1.5 text-violet-400 hover:text-violet-300 text-xs transition-colors">
                        {{ showLegend ? '✕ Tutup' : 'ℹ️ Keterangan Warna' }}
                    </button>
                </div>

                <!-- Legend -->
                <div v-if="showLegend" class="pt-3 mt-1 border-t border-slate-800 space-y-2.5 text-xs">
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5">
                        <span class="text-slate-500 font-medium w-28 flex-shrink-0">Status Jual</span>
                        <span v-for="(cfg, key) in statusJualLegend" :key="key" class="flex items-center gap-1.5 text-slate-400">
                            <span class="w-2 h-2 rounded-full" :style="cfg.dotStyle" />
                            {{ cfg.label }}
                        </span>
                    </div>
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5">
                        <span class="text-slate-500 font-medium w-28 flex-shrink-0">Status Bangun</span>
                        <span v-for="s in filterOptions.status_bangun" :key="s.id" class="flex items-center gap-1.5 text-slate-400">
                            <span :style="`background:${s.warna}25; color:${s.warna}`" class="px-1.5 py-0.5 rounded-full text-[10px] font-medium">{{ s.nama }}</span>
                        </span>
                    </div>
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5">
                        <span class="text-slate-500 font-medium w-28 flex-shrink-0">Pipeline</span>
                        <span v-for="(cfg, key) in statusPenjualanConfig" :key="key" class="flex items-center gap-1.5 text-slate-400">
                            <span class="px-1.5 py-0.5 rounded-full text-[10px] font-medium" :style="cfg.style">{{ cfg.label }}</span>
                        </span>
                    </div>
                </div>
            </div>

            <!-- ═══ MODE: PER UNIT ═══ -->
            <div v-if="mode === 'unit'" class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-slate-800 text-xs text-slate-500 uppercase tracking-wide">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium">Konsumen</th>
                                <th class="px-4 py-3 text-left font-medium">Unit</th>
                                <th class="px-4 py-3 text-right font-medium">Harga</th>
                                <th class="px-4 py-3 text-left font-medium">Cara Bayar</th>
                                <th class="px-4 py-3 text-left font-medium">Bank</th>
                                <th class="px-4 py-3 text-left font-medium">Status Bangun</th>
                                <th class="px-4 py-3 text-left font-medium">ID Rumah</th>
                                <th class="px-4 py-3 text-left font-medium">Pemberkasan</th>
                                <th class="px-4 py-3 text-left font-medium">Pipeline</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in rows.data" :key="row.id"
                                class="border-b border-slate-800/50 hover:bg-slate-800/30 transition-colors">
                                <td class="px-4 py-3">
                                    <div class="text-slate-200 font-medium">{{ row.konsumen_nama }}</div>
                                    <div class="text-slate-600 text-xs">{{ row.konsumen_no_hp ?? '-' }}</div>
                                </td>
                                <td class="px-4 py-3 text-slate-400">
                                    {{ row.kavling_nomor }}
                                    <div class="text-slate-600 text-xs">{{ row.project_nama }}<span v-if="row.kluster"> · {{ row.kluster }}</span></div>
                                </td>
                                <td class="px-4 py-3 text-slate-300 text-right font-medium">{{ formatRp(row.harga_deal) }}</td>
                                <td class="px-4 py-3 text-slate-400">{{ row.cara_bayar_label }}</td>
                                <td class="px-4 py-3 text-slate-400 text-xs">{{ row.bank_rekanan_kpr ?? '-' }}</td>
                                <td class="px-4 py-3">
                                    <span :style="`background:${statusBangunColorHex[row.status_bangun_stage_id]}25; color:${statusBangunColorHex[row.status_bangun_stage_id]}`"
                                        class="px-2 py-0.5 text-xs rounded-full font-medium">
                                        {{ row.status_bangun_label }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-slate-400 text-xs font-mono">{{ row.id_rumah ?? '-' }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <div class="w-16 h-1.5 bg-slate-800 rounded-full overflow-hidden">
                                            <div class="h-full bg-gradient-to-r from-violet-500 to-indigo-500 rounded-full" :style="`width: ${row.progress_berkas.persen}%`" />
                                        </div>
                                        <span class="text-slate-500 text-xs">{{ row.progress_berkas.persen }}%</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-0.5 text-xs rounded-full font-medium" :style="statusPenjualanConfig[row.status_penjualan]?.style">
                                        {{ statusPenjualanConfig[row.status_penjualan]?.label ?? row.status_penjualan_label }}
                                    </span>
                                    <div v-if="row.pipeline_progress" class="text-slate-500 text-[11px] mt-1">{{ formatPipelineProgress(row.pipeline_progress) }}</div>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <Link :href="`${route('konsumens.show', row.konsumen_id)}?transaksi=${row.id}`"
                                        class="text-xs text-violet-400 hover:text-violet-300 transition-colors whitespace-nowrap">
                                        Detail →
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="!rows.data.length">
                                <td colspan="10" class="px-4 py-12 text-center text-slate-600">Tidak ada unit konsumen ditemukan.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ═══ MODE: PER KONSUMEN ═══ -->
            <div v-else class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-slate-800 text-xs text-slate-500 uppercase tracking-wide">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium">Konsumen</th>
                                <th class="px-4 py-3 text-left font-medium">Kontak</th>
                                <th class="px-4 py-3 text-left font-medium">NIK</th>
                                <th class="px-4 py-3 text-left font-medium">Transaksi</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="k in rows.data" :key="k.id">
                            <tr class="border-b border-slate-800/50 hover:bg-slate-800/30 transition-colors">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 rounded-full bg-gradient-to-br from-violet-500 to-indigo-600 flex items-center justify-center text-white text-xs font-semibold flex-shrink-0">
                                            {{ initials(k.nama) }}
                                        </div>
                                        <span class="text-slate-200 font-medium">{{ k.nama }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-slate-400 text-xs">
                                    <div>{{ k.no_hp ?? '-' }}</div>
                                    <div class="text-slate-600">{{ k.email ?? '-' }}</div>
                                </td>
                                <td class="px-4 py-3 text-slate-400 text-xs">{{ k.nik ?? '-' }}</td>
                                <td class="px-4 py-3">
                                    <button v-if="k.units.length" @click="toggleExpand(k.id)"
                                        class="inline-flex items-center gap-1 px-2 py-0.5 bg-violet-500/10 text-violet-400 ring-1 ring-violet-500/20 rounded-full text-xs hover:bg-violet-500/20 transition-colors">
                                        {{ k.transaksi_count }} unit
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"
                                            :class="['w-3 h-3 transition-transform', expandedKonsumen === k.id ? 'rotate-180' : '']">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                        </svg>
                                    </button>
                                    <span v-else class="text-slate-600 text-xs">Belum ada unit</span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <Link :href="route('konsumens.show', k.id)" class="p-1.5 text-slate-500 hover:text-violet-400 hover:bg-violet-400/10 rounded-md transition-colors" title="Detail">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                        </Link>
                                        <Link :href="route('konsumens.edit', k.id)" class="p-1.5 text-slate-500 hover:text-amber-400 hover:bg-amber-400/10 rounded-md transition-colors" title="Edit">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" /></svg>
                                        </Link>
                                        <button @click="deleteKonsumen(k)" class="p-1.5 text-slate-500 hover:text-rose-400 hover:bg-rose-400/10 rounded-md transition-colors" title="Hapus">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="expandedKonsumen === k.id" class="bg-slate-800/40 border-b border-slate-800/50">
                                <td colspan="5" class="px-4 py-3">
                                    <div class="space-y-1.5">
                                        <div v-for="unit in k.units" :key="unit.id"
                                            class="flex items-center justify-between px-3 py-2 bg-slate-900 border border-slate-800 rounded-lg text-xs">
                                            <div>
                                                <span class="text-slate-300">{{ unit.kavling_nomor }}</span>
                                                <span class="text-slate-600 ml-2">{{ unit.project_nama }} · {{ unit.cara_bayar_label }}</span>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-medium" :style="statusJualBadgeStyle[unit.status_jual]">{{ unit.status_jual_label }}</span>
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-medium" :style="statusPenjualanConfig[unit.status_penjualan]?.style">
                                                    {{ statusPenjualanConfig[unit.status_penjualan]?.label ?? unit.status_penjualan_label }}
                                                </span>
                                                <Link :href="`${route('konsumens.show', k.id)}?transaksi=${unit.id}`"
                                                    class="text-violet-400 hover:text-violet-300 transition-colors">
                                                    Detail →
                                                </Link>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            </template>
                            <tr v-if="!rows.data.length">
                                <td colspan="5" class="px-4 py-12 text-center text-slate-600">Tidak ada konsumen ditemukan.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pagination -->
            <div v-if="rows.last_page > 1" class="flex justify-center gap-1">
                <Link
                    v-for="link in rows.links"
                    :key="link.label"
                    :href="link.url ?? '#'"
                    v-html="link.label"
                    :class="[
                        'px-3 py-1.5 text-xs rounded-md transition-colors',
                        link.active ? 'bg-violet-600 text-white' : 'text-slate-400 hover:bg-slate-800 bg-slate-900',
                        !link.url ? 'opacity-40 pointer-events-none' : ''
                    ]"
                />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
