<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    stats: Object,
    kavlingBangunBreakdown: Array,
    pipelineFunnel: Array,
    caraBayarBreakdown: Array,
    financials: Object,
    piutangJatuhTempo: Object,
    bastTertunda: Array,
    recentActivities: Array,
    projectsSummary: Array,
    sp3kMonitoring: Array,
    periodFilters: Object,
    informasiUmum: Object,
    pipelineFunnelPeriodic: Object,
    caraBayarBreakdownPeriodic: Array,
    financialsPeriodic: Object,
    kecepatanPipeline: Object,
    performaSales: Array,
    cancellationRatePeriod: Object,
    trenTahunan: Object,
});

const sp3kBadge = {
    safe:     { label: 'Berlaku',  cls: 'bg-emerald-500/15 text-emerald-400' },
    warning:  { label: '≤30 hari', cls: 'bg-amber-500/15 text-amber-400' },
    critical: { label: '≤14 hari', cls: 'bg-orange-500/15 text-orange-400' },
    expired:  { label: 'Expired',  cls: 'bg-rose-500/15 text-rose-400' },
};

const page = usePage();
const flash = computed(() => page.props.flash ?? {});
const currentProject = computed(() => page.props.currentProject ?? null);

const formatNumber = (num) => new Intl.NumberFormat('id-ID').format(num ?? 0);
const formatRp = (v) => v ? 'Rp ' + Number(v).toLocaleString('id-ID') : 'Rp 0';

const statCards = [
    {
        label: 'Total Proyek Aktif',
        value: props.stats?.total_projects ?? 0,
        icon: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z" /></svg>`,
        color: 'from-violet-500 to-indigo-600',
        shadow: 'shadow-violet-500/20',
    },
    {
        label: 'Total Kavling',
        value: props.stats?.total_kavlings ?? 0,
        icon: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15" /></svg>`,
        color: 'from-blue-500 to-cyan-600',
        shadow: 'shadow-blue-500/20',
    },
    {
        label: 'Kavling Tersedia',
        value: props.stats?.kavling_available ?? 0,
        icon: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>`,
        color: 'from-emerald-500 to-green-600',
        shadow: 'shadow-emerald-500/20',
    },
    {
        label: 'Kavling Terjual',
        value: props.stats?.kavling_sold ?? 0,
        icon: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z" /></svg>`,
        color: 'from-amber-500 to-orange-600',
        shadow: 'shadow-amber-500/20',
    },
    {
        label: 'Pending Pembatalan',
        value: props.stats?.pending_cancellations ?? 0,
        icon: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>`,
        color: 'from-rose-500 to-pink-600',
        shadow: 'shadow-rose-500/20',
        urgent: true,
    },
];

const pipelineMax = computed(() => Math.max(1, ...(props.pipelineFunnel ?? []).map(s => s.count)));
const caraBayarTotal = computed(() => (props.caraBayarBreakdown ?? []).reduce((sum, c) => sum + c.count, 0));
const caraBayarColors = {
    cash: 'from-emerald-500 to-green-500',
    cash_bertahap: 'from-teal-500 to-cyan-500',
    kpr_subsidi: 'from-violet-500 to-indigo-500',
    kpr_komersil: 'from-blue-500 to-sky-500',
};
const kavlingBangunMax = computed(() => Math.max(1, ...(props.kavlingBangunBreakdown ?? []).map(s => s.count)));

const pct = (paid, total) => total > 0 ? Math.min(100, Math.round((Number(paid) / Number(total)) * 100)) : 0;

// ── Ringkasan Finansial — expand/collapse rincian per kartu ─────────────
const expandedFinansial = ref({ nilai: false, konsumen: false, bank: false });
const toggleFinansial = (key) => { expandedFinansial.value[key] = !expandedFinansial.value[key]; };

// Total per kategori (Kategori 1 Resmi / Kategori 2 Titipan) — jumlah dari
// rincian per item, bukan field terpisah dari backend.
const sumRincian = (obj) => Object.values(obj ?? {}).reduce((s, v) => s + Number(v || 0), 0);
const kategoriResmiTotal = computed(() => sumRincian(props.financials?.nilai_transaksi_rincian?.resmi));
const kategoriTitipanTotal = computed(() => sumRincian(props.financials?.nilai_transaksi_rincian?.titipan));

// ═══════════════════════════════════════════════════════════════════════
// Laporan Periode — date-range terpisah dari "kondisi sekarang" di atas.
// Pipeline Penjualan & Cara Pembayaran bersifat dual-mode: live kalau
// hasDateFilter false, periodik kalau true (lihat template).
// ═══════════════════════════════════════════════════════════════════════
const hasDateFilter = computed(() => props.periodFilters?.has_date_filter === true);

const todayIso = () => new Date().toISOString().slice(0, 10);
const dateFrom = ref(props.periodFilters?.from ?? '');
const dateTo = ref(props.periodFilters?.to ?? '');

const applyDateRange = (from, to) => {
    dateFrom.value = from ?? '';
    dateTo.value = to ?? '';
    router.get(route('dashboard'), {
        from: dateFrom.value || undefined,
        to: dateTo.value || undefined,
        tahun: tahunTren.value,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

const applyCustomRange = () => applyDateRange(dateFrom.value, dateTo.value);
const resetDateRange = () => applyDateRange(null, null);

// Format tanggal LOKAL (bukan toISOString, yang konversi ke UTC dan bisa
// geser mundur 1 hari di timezone WIB/UTC+7).
const fmtDate = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const presetHariIni = () => { const t = new Date(); applyDateRange(fmtDate(t), fmtDate(t)); };
const presetMingguIni = () => {
    const t = new Date();
    const awal = new Date(t); awal.setDate(t.getDate() - t.getDay());
    applyDateRange(fmtDate(awal), fmtDate(t));
};
const presetBulanIni = () => {
    const t = new Date();
    applyDateRange(fmtDate(new Date(t.getFullYear(), t.getMonth(), 1)), fmtDate(t));
};
const presetBulanLalu = () => {
    const t = new Date();
    const awal = new Date(t.getFullYear(), t.getMonth() - 1, 1);
    const akhir = new Date(t.getFullYear(), t.getMonth(), 0);
    applyDateRange(fmtDate(awal), fmtDate(akhir));
};

// ── Pipeline & Cara Bayar dual-mode ─────────────────────────────────────
const pipelinePeriodicMax = computed(() => Math.max(1,
    props.pipelineFunnelPeriodic?.booking ?? 0,
    props.pipelineFunnelPeriodic?.akad ?? 0,
    props.pipelineFunnelPeriodic?.bast ?? 0,
));
const caraBayarPeriodicTotal = computed(() => (props.caraBayarBreakdownPeriodic ?? []).reduce((sum, c) => sum + c.count, 0));

// ── Grafik Tren — kontrol tahun terpisah dari date-range di atas ────────
const tahunTren = ref(props.trenTahunan?.tahun ?? new Date().getFullYear());
const applyTahunTren = () => {
    router.get(route('dashboard'), {
        from: dateFrom.value || undefined,
        to: dateTo.value || undefined,
        tahun: tahunTren.value,
    }, { preserveState: true, preserveScroll: true, replace: true });
};
const trenMaxCount = computed(() => Math.max(1, ...(props.trenTahunan?.bulan ?? []).flatMap(b => [b.booking, b.akad, b.bast])));
const trenMaxRevenue = computed(() => Math.max(1, ...(props.trenTahunan?.bulan ?? []).map(b => b.revenue)));

// ── Grafik Tren sebagai line chart (SVG hand-rolled, tanpa library) ─────
const trenSvgW = 600;
const trenSvgH = 140;
const trenRevenueSvgH = 90;
const trenX = (i) => Math.round((i / 11) * trenSvgW);
const trenY = (val, max, h) => h - 8 - (max > 0 ? (val / max) * (h - 16) : 0);
const trenLinePoints = (key, h, max) => (props.trenTahunan?.bulan ?? [])
    .map((b, i) => `${trenX(i)},${trenY(b[key], max, h)}`).join(' ');

const formatDurasi = (hari) => hari === null || hari === undefined ? '-' : `${hari} hari`;
</script>

<template>
    <Head title="Dashboard" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <h1 class="text-slate-200 font-semibold text-sm">Dashboard</h1>
                <span v-if="currentProject" class="px-2 py-0.5 bg-violet-500/15 text-violet-300 text-xs rounded-full font-medium">
                    🏢 {{ currentProject.nama }}
                </span>
                <span v-else class="px-2 py-0.5 bg-slate-800 text-slate-400 text-xs rounded-full font-medium">
                    🏢 Semua Proyek
                </span>
            </div>
        </template>

        <div class="p-6 space-y-6">
            <!-- Flash Error (misal: akses ditolak, redirect ke sini) -->
            <div v-if="flash.error" class="flex items-center gap-3 px-4 py-3 bg-rose-500/10 border border-rose-500/20 rounded-xl text-rose-400 text-sm">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5 flex-shrink-0"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd"/></svg>
                {{ flash.error }}
            </div>

            <!-- Stats Grid -->
            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-4">
                <div
                    v-for="card in statCards"
                    :key="card.label"
                    class="relative bg-slate-900 border border-slate-800 rounded-xl p-4 overflow-hidden group hover:border-slate-700 transition-colors"
                    :class="{ 'ring-1 ring-rose-500/30': card.urgent && card.value > 0 }"
                >
                    <div class="flex items-start justify-between mb-3">
                        <div :class="['w-10 h-10 rounded-lg bg-gradient-to-br flex items-center justify-center text-white shadow-lg', card.color, card.shadow]">
                            <span v-html="card.icon" />
                        </div>
                        <Link
                            v-if="card.urgent && card.value > 0"
                            :href="route('cancellation-requests.index')"
                            class="text-xs text-rose-400 hover:text-rose-300 transition-colors font-medium"
                        >
                            Review →
                        </Link>
                    </div>
                    <div class="text-2xl font-bold text-white mb-0.5">{{ formatNumber(card.value) }}</div>
                    <div class="text-slate-500 text-xs font-medium">{{ card.label }}</div>

                    <!-- Subtle glow -->
                    <div :class="['absolute -bottom-4 -right-4 w-16 h-16 rounded-full bg-gradient-to-br opacity-10 blur-xl', card.color]" />
                </div>
            </div>

            <!-- Ringkasan Finansial -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-800">
                    <h2 class="text-slate-200 font-semibold text-sm">💰 Ringkasan Finansial</h2>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 divide-y sm:divide-y-0 sm:divide-x divide-slate-800/70">
                    <!-- Total Nilai Transaksi Aktif -->
                    <div class="p-5">
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-slate-500 text-xs">Total Nilai Transaksi Aktif</span>
                            <button @click="toggleFinansial('nilai')" class="text-slate-500 hover:text-slate-300 text-[11px] transition-colors">{{ expandedFinansial.nilai ? '▲ Tutup' : '▼ Rincian' }}</button>
                        </div>
                        <div class="text-white font-bold text-xl">{{ formatRp(financials?.total_pendapatan) }}</div>
                        <div class="text-slate-600 text-xs mt-1">Harga deal, semua transaksi berjalan</div>
                        <div v-if="expandedFinansial.nilai" class="mt-3 pt-3 border-t border-slate-800 space-y-3">
                            <div>
                                <div class="flex items-center justify-between text-[11px] text-slate-500 uppercase tracking-wide mb-1">
                                    <span>Kategori 1 — Pendapatan Resmi</span>
                                    <span class="text-slate-400 normal-case">{{ formatRp(kategoriResmiTotal) }}</span>
                                </div>
                                <div class="space-y-1 pl-2">
                                    <div class="flex items-center justify-between text-xs">
                                        <span class="text-slate-400">Harga Dasar</span>
                                        <span class="text-slate-300">{{ formatRp(financials?.nilai_transaksi_rincian?.resmi?.harga_dasar) }}</span>
                                    </div>
                                    <div v-if="financials?.nilai_transaksi_rincian?.resmi?.booking_fee > 0" class="flex items-center justify-between text-xs">
                                        <span class="text-slate-400">Booking Fee</span>
                                        <span class="text-slate-300">{{ formatRp(financials?.nilai_transaksi_rincian?.resmi?.booking_fee) }}</span>
                                    </div>
                                    <div v-if="financials?.nilai_transaksi_rincian?.resmi?.diskon < 0" class="flex items-center justify-between text-xs">
                                        <span class="text-slate-400">Diskon/Promo</span>
                                        <span class="text-rose-400">{{ formatRp(financials?.nilai_transaksi_rincian?.resmi?.diskon) }}</span>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <div class="flex items-center justify-between text-[11px] text-slate-500 uppercase tracking-wide mb-1">
                                    <span>Kategori 2 — Dana Rekening Titipan</span>
                                    <span class="text-slate-400 normal-case">{{ formatRp(kategoriTitipanTotal) }}</span>
                                </div>
                                <div class="space-y-1 pl-2">
                                    <div class="flex items-center justify-between text-xs">
                                        <span class="text-slate-400">Biaya Tambahan Tanah</span>
                                        <span class="text-slate-300">{{ formatRp(financials?.nilai_transaksi_rincian?.titipan?.biaya_tanah) }}</span>
                                    </div>
                                    <div class="flex items-center justify-between text-xs">
                                        <span class="text-slate-400">Biaya Tambahan Lainnya</span>
                                        <span class="text-slate-300">{{ formatRp(financials?.nilai_transaksi_rincian?.titipan?.biaya_tambahan_lain) }}</span>
                                    </div>
                                    <div class="flex items-center justify-between text-xs">
                                        <span class="text-slate-400">Titipan Biaya Akad</span>
                                        <span class="text-slate-300">{{ formatRp(financials?.nilai_transaksi_rincian?.titipan?.titipan_biaya_akad) }}</span>
                                    </div>
                                    <div v-if="financials?.nilai_transaksi_rincian?.titipan?.booking_fee > 0" class="flex items-center justify-between text-xs">
                                        <span class="text-slate-400">Booking Fee</span>
                                        <span class="text-slate-300">{{ formatRp(financials?.nilai_transaksi_rincian?.titipan?.booking_fee) }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Piutang Konsumen -->
                    <div class="p-5">
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-slate-500 text-xs">Piutang Konsumen</span>
                            <span class="text-slate-500 text-xs">{{ pct(financials?.terbayar_konsumen, financials?.piutang_konsumen) }}%</span>
                        </div>
                        <div class="text-slate-200 font-semibold text-sm">{{ formatRp(financials?.terbayar_konsumen) }} / {{ formatRp(financials?.piutang_konsumen) }}</div>
                        <div class="h-1.5 bg-slate-800 rounded-full overflow-hidden mt-2">
                            <div class="h-full bg-gradient-to-r from-violet-500 to-indigo-500 rounded-full"
                                :style="`width: ${pct(financials?.terbayar_konsumen, financials?.piutang_konsumen)}%`" />
                        </div>
                        <div class="flex items-center justify-between mt-1.5">
                            <span class="text-amber-400 text-xs font-medium">Sisa {{ formatRp(financials?.sisa_piutang_konsumen) }}</span>
                            <button @click="toggleFinansial('konsumen')" class="text-slate-500 hover:text-slate-300 text-[11px] transition-colors">{{ expandedFinansial.konsumen ? '▲ Tutup' : '▼ Rincian' }}</button>
                        </div>
                        <div v-if="expandedFinansial.konsumen" class="mt-3 pt-3 border-t border-slate-800 space-y-1.5">
                            <div v-for="item in financials?.piutang_konsumen_rincian" :key="item.nama" class="flex items-center justify-between text-xs">
                                <span class="text-slate-400">{{ item.nama }}</span>
                                <span class="text-slate-300">{{ formatRp(item.terbayar) }} / {{ formatRp(item.nominal) }}</span>
                            </div>
                            <div v-if="!financials?.piutang_konsumen_rincian?.length" class="text-slate-600 text-xs">Tidak ada rincian.</div>
                        </div>
                    </div>
                    <!-- Piutang Bank -->
                    <div class="p-5">
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-slate-500 text-xs">Piutang Bank (Pencairan KPR)</span>
                            <span class="text-slate-500 text-xs">{{ pct(financials?.terbayar_bank, financials?.piutang_bank) }}%</span>
                        </div>
                        <div class="text-slate-200 font-semibold text-sm">{{ formatRp(financials?.terbayar_bank) }} / {{ formatRp(financials?.piutang_bank) }}</div>
                        <div class="h-1.5 bg-slate-800 rounded-full overflow-hidden mt-2">
                            <div class="h-full bg-gradient-to-r from-emerald-500 to-teal-500 rounded-full"
                                :style="`width: ${pct(financials?.terbayar_bank, financials?.piutang_bank)}%`" />
                        </div>
                        <div class="flex items-center justify-between mt-1.5">
                            <span class="text-amber-400 text-xs font-medium">Sisa {{ formatRp(financials?.sisa_piutang_bank) }}</span>
                            <button @click="toggleFinansial('bank')" class="text-slate-500 hover:text-slate-300 text-[11px] transition-colors">{{ expandedFinansial.bank ? '▲ Tutup' : '▼ Rincian' }}</button>
                        </div>
                        <div v-if="expandedFinansial.bank" class="mt-3 pt-3 border-t border-slate-800 space-y-1.5">
                            <div v-for="item in financials?.piutang_bank_rincian" :key="item.nama" class="flex items-center justify-between text-xs">
                                <span class="text-slate-400">{{ item.nama }}</span>
                                <span class="text-slate-300">{{ formatRp(item.terbayar) }} / {{ formatRp(item.nominal) }}</span>
                            </div>
                            <div v-if="!financials?.piutang_bank_rincian?.length" class="text-slate-600 text-xs">Tidak ada rincian.</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pipeline Funnel + Cara Bayar — dual-mode: live tanpa filter tanggal,
                 periodik begitu rentang tanggal Laporan Periode di bawah dipilih. -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                    <div class="px-5 py-4 border-b border-slate-800 flex items-center justify-between">
                        <h2 class="text-slate-200 font-semibold text-sm">📊 Pipeline Penjualan</h2>
                        <span class="text-slate-500 text-[11px]">{{ hasDateFilter ? `${dateFrom} – ${dateTo}` : 'Sekarang' }}</span>
                    </div>
                    <!-- Live -->
                    <div v-if="!hasDateFilter" class="p-5 space-y-3">
                        <div v-for="stage in pipelineFunnel" :key="stage.key" class="flex items-center gap-3">
                            <span class="text-slate-400 text-xs w-28 flex-shrink-0">{{ stage.label }}</span>
                            <div class="flex-1 h-5 bg-slate-800 rounded-md overflow-hidden">
                                <div class="h-full bg-gradient-to-r from-violet-600 to-indigo-500 rounded-md transition-all duration-500 flex items-center justify-end px-2"
                                    :style="`width: ${Math.max(4, (stage.count / pipelineMax) * 100)}%`">
                                    <span v-if="stage.count > 0" class="text-white text-[10px] font-semibold">{{ stage.count }}</span>
                                </div>
                            </div>
                            <span v-if="stage.count === 0" class="text-slate-600 text-xs w-4 text-right">0</span>
                        </div>
                        <div v-if="!pipelineFunnel?.some(s => s.count > 0)" class="text-center text-slate-600 text-xs py-4">Belum ada transaksi berjalan.</div>
                    </div>
                    <!-- Periodik -->
                    <div v-else class="p-5 space-y-4">
                        <div class="space-y-3">
                            <div v-for="stage in [
                                    { key: 'booking', label: 'Booking', count: pipelineFunnelPeriodic?.booking ?? 0, cls: 'from-blue-600 to-blue-500' },
                                    { key: 'akad', label: 'Akad', count: pipelineFunnelPeriodic?.akad ?? 0, cls: 'from-violet-600 to-indigo-500' },
                                    { key: 'bast', label: 'BAST', count: pipelineFunnelPeriodic?.bast ?? 0, cls: 'from-emerald-600 to-teal-500' },
                                ]" :key="stage.key" class="flex items-center gap-3">
                                <span class="text-slate-400 text-xs w-16 flex-shrink-0">{{ stage.label }}</span>
                                <div class="flex-1 h-5 bg-slate-800 rounded-md overflow-hidden">
                                    <div :class="['h-full bg-gradient-to-r rounded-md transition-all duration-500 flex items-center justify-end px-2', stage.cls]"
                                        :style="`width: ${Math.max(4, (stage.count / pipelinePeriodicMax) * 100)}%`">
                                        <span v-if="stage.count > 0" class="text-white text-[10px] font-semibold">{{ stage.count }}</span>
                                    </div>
                                </div>
                                <span v-if="stage.count === 0" class="text-slate-600 text-xs w-4 text-right">0</span>
                            </div>
                        </div>
                        <div class="flex items-center gap-4 pt-3 border-t border-slate-800 text-xs">
                            <span class="text-slate-500">Konversi Booking→Akad: <span class="text-slate-200 font-semibold">{{ pipelineFunnelPeriodic?.conversion_booking_akad ?? 0 }}%</span></span>
                            <span class="text-slate-500">Booking→BAST: <span class="text-slate-200 font-semibold">{{ pipelineFunnelPeriodic?.conversion_booking_bast ?? 0 }}%</span></span>
                        </div>
                    </div>
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                    <div class="px-5 py-4 border-b border-slate-800 flex items-center justify-between">
                        <h2 class="text-slate-200 font-semibold text-sm">💳 Cara Pembayaran</h2>
                        <span class="text-slate-500 text-[11px]">{{ hasDateFilter ? `${dateFrom} – ${dateTo}` : 'Sekarang' }}</span>
                    </div>
                    <!-- Live -->
                    <div v-if="!hasDateFilter" class="p-5 space-y-3">
                        <div v-for="c in caraBayarBreakdown" :key="c.key" class="flex items-center gap-3">
                            <span class="text-slate-400 text-xs w-28 flex-shrink-0">{{ c.label }}</span>
                            <div class="flex-1 h-5 bg-slate-800 rounded-md overflow-hidden">
                                <div :class="['h-full bg-gradient-to-r rounded-md transition-all duration-500 flex items-center justify-end px-2', caraBayarColors[c.key]]"
                                    :style="`width: ${Math.max(4, (c.count / Math.max(1, caraBayarTotal)) * 100)}%`">
                                    <span v-if="c.count > 0" class="text-white text-[10px] font-semibold">{{ c.count }}</span>
                                </div>
                            </div>
                            <span class="text-slate-600 text-xs w-9 text-right">{{ caraBayarTotal > 0 ? Math.round((c.count / caraBayarTotal) * 100) : 0 }}%</span>
                        </div>
                        <div v-if="caraBayarTotal === 0" class="text-center text-slate-600 text-xs py-4">Belum ada transaksi berjalan.</div>
                    </div>
                    <!-- Periodik -->
                    <div v-else class="p-5 space-y-3">
                        <div v-for="c in caraBayarBreakdownPeriodic" :key="c.key" class="flex items-center gap-3">
                            <span class="text-slate-400 text-xs w-28 flex-shrink-0">{{ c.label }}</span>
                            <div class="flex-1 h-5 bg-slate-800 rounded-md overflow-hidden">
                                <div :class="['h-full bg-gradient-to-r rounded-md transition-all duration-500 flex items-center justify-end px-2', caraBayarColors[c.key]]"
                                    :style="`width: ${Math.max(4, (c.count / Math.max(1, caraBayarPeriodicTotal)) * 100)}%`">
                                    <span v-if="c.count > 0" class="text-white text-[10px] font-semibold">{{ c.count }}</span>
                                </div>
                            </div>
                            <span class="text-slate-600 text-xs w-9 text-right">{{ caraBayarPeriodicTotal > 0 ? Math.round((c.count / caraBayarPeriodicTotal) * 100) : 0 }}%</span>
                        </div>
                        <div v-if="caraBayarPeriodicTotal === 0" class="text-center text-slate-600 text-xs py-4">Tidak ada booking di periode ini.</div>
                    </div>
                </div>
            </div>

            <!-- Info Kavling: Status Pembangunan -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-800">
                    <h2 class="text-slate-200 font-semibold text-sm">🏗️ Status Pembangunan Kavling</h2>
                </div>
                <div class="p-5 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                    <div v-for="s in kavlingBangunBreakdown" :key="s.key" class="text-center">
                        <div class="text-white font-bold text-xl">{{ s.count }}</div>
                        <div class="h-1.5 bg-slate-800 rounded-full overflow-hidden my-1.5">
                            <div class="h-full rounded-full transition-all duration-500"
                                :style="`background:${s.color}; width: ${Math.max(s.count > 0 ? 6 : 0, (s.count / kavlingBangunMax) * 100)}%`" />
                        </div>
                        <div class="text-slate-500 text-[11px]">{{ s.label }}</div>
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════════════
                 LAPORAN PERIODE — terpisah dari panel "kondisi sekarang" di
                 atas. Semua section di sini (kecuali Grafik Tren) dikontrol
                 satu date-range, default "Bulan Ini" kalau belum dipilih.
            ════════════════════════════════════════════════════════════════ -->
            <div class="border-t-2 border-dashed border-slate-800 pt-6 space-y-6">
                <div class="flex items-center justify-between flex-wrap gap-3">
                    <h2 class="text-white font-bold text-lg flex items-center gap-2">📅 Laporan Periode</h2>
                    <div class="flex items-center gap-2 flex-wrap">
                        <button @click="presetHariIni" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs rounded-lg transition-colors">Hari Ini</button>
                        <button @click="presetMingguIni" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs rounded-lg transition-colors">Minggu Ini</button>
                        <button @click="presetBulanIni" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs rounded-lg transition-colors">Bulan Ini</button>
                        <button @click="presetBulanLalu" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs rounded-lg transition-colors">Bulan Lalu</button>
                        <div class="flex items-center gap-1.5">
                            <input v-model="dateFrom" type="date" class="px-2 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
                            <span class="text-slate-600 text-xs">–</span>
                            <input v-model="dateTo" type="date" class="px-2 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
                            <button @click="applyCustomRange" class="px-3 py-1.5 bg-violet-600 hover:bg-violet-500 text-white text-xs font-medium rounded-lg transition-colors">Terapkan</button>
                        </div>
                        <button v-if="hasDateFilter" @click="resetDateRange" class="px-2.5 py-1.5 text-slate-400 hover:text-slate-200 text-xs transition-colors">✕ Kembali ke Live</button>
                    </div>
                </div>
                <p class="text-slate-500 text-xs -mt-4">
                    Menampilkan: {{ hasDateFilter ? `${dateFrom} s/d ${dateTo}` : `Bulan Ini (${periodFilters?.from ?? ''} s/d ${periodFilters?.to ?? ''})` }}
                </p>

                <!-- Informasi Umum -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                    <div class="px-5 py-4 border-b border-slate-800">
                        <h3 class="text-slate-200 font-semibold text-sm">📋 Informasi Umum</h3>
                        <p class="text-slate-600 text-xs mt-0.5">Jumlah booking & akad dihitung gross (semua yang terjadi di periode ini) — pembatalan ditampilkan terpisah, bukan dikurangkan.</p>
                    </div>
                    <div class="grid grid-cols-3 divide-x divide-slate-800/70">
                        <div class="p-5 text-center">
                            <div class="text-white font-bold text-2xl">{{ formatNumber(informasiUmum?.jumlah_booking) }}</div>
                            <div class="text-slate-500 text-xs mt-0.5">Jumlah Booking</div>
                        </div>
                        <div class="p-5 text-center">
                            <div class="text-white font-bold text-2xl">{{ formatNumber(informasiUmum?.jumlah_akad) }}</div>
                            <div class="text-slate-500 text-xs mt-0.5">Jumlah Akad</div>
                        </div>
                        <div class="p-5 text-center">
                            <div class="text-rose-400 font-bold text-2xl">{{ formatNumber(informasiUmum?.jumlah_batal) }}</div>
                            <div class="text-slate-500 text-xs mt-0.5">Jumlah Batal</div>
                        </div>
                    </div>
                </div>

                <!-- Finansial Periode -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                    <div class="px-5 py-4 border-b border-slate-800">
                        <h3 class="text-slate-200 font-semibold text-sm">💰 Finansial Periode</h3>
                    </div>
                    <div class="grid grid-cols-2 lg:grid-cols-4 divide-x divide-y lg:divide-y-0 divide-slate-800/70">
                        <div class="p-5">
                            <div class="text-slate-500 text-xs mb-1">Pembayaran Diterima</div>
                            <div class="text-emerald-400 font-bold text-lg">{{ formatRp(financialsPeriodic?.total_pembayaran_diterima) }}</div>
                            <div class="text-slate-600 text-xs mt-1">Jatuh tempo (Booking Fee/DP): {{ formatRp(financialsPeriodic?.jatuh_tempo_piutang_konsumen) }}</div>
                        </div>
                        <div class="p-5">
                            <div class="text-slate-500 text-xs mb-1">Pencairan KPR Diterima</div>
                            <div class="text-emerald-400 font-bold text-lg">{{ formatRp(financialsPeriodic?.total_pencairan_kpr_diterima) }}</div>
                            <div class="text-slate-600 text-xs mt-1" title="Belum ada data tanggal estimasi pencairan tersimpan">Jatuh tempo: belum ada data</div>
                        </div>
                        <div class="p-5">
                            <div class="text-slate-500 text-xs mb-1">Jumlah Transaksi (Booking)</div>
                            <div class="text-white font-bold text-lg">{{ formatNumber(financialsPeriodic?.jumlah_transaksi) }}</div>
                        </div>
                        <div class="p-5">
                            <div class="text-slate-500 text-xs mb-1">Rata-rata Nilai Transaksi</div>
                            <div class="text-white font-bold text-lg">{{ formatRp(financialsPeriodic?.rata_rata_nilai_transaksi) }}</div>
                        </div>
                    </div>
                    <div v-if="financialsPeriodic?.revenue_per_proyek?.length" class="px-5 py-4 border-t border-slate-800">
                        <div class="text-slate-500 text-xs mb-2">Revenue per Proyek</div>
                        <div class="space-y-1.5">
                            <div v-for="rp in financialsPeriodic.revenue_per_proyek" :key="rp.project" class="flex items-center justify-between text-xs">
                                <span class="text-slate-300">{{ rp.project }} <span class="text-slate-600">({{ rp.count }} unit)</span></span>
                                <span class="text-slate-200 font-medium">{{ formatRp(rp.total) }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Kecepatan Pipeline + Cancellation Rate -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="lg:col-span-2 bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                        <div class="px-5 py-4 border-b border-slate-800">
                            <h3 class="text-slate-200 font-semibold text-sm">⚡ Kecepatan Pipeline</h3>
                            <p class="text-slate-600 text-xs mt-0.5">Dihitung mundur dari transaksi yang MENCAPAI tiap tahap di periode ini (mis. "SP3K" = akad yang lahir di periode ini, dihitung dari kapan SP3K-nya terbit) — bukan dari kapan transaksinya dibooking. "-" berarti belum ada transaksi yang mencapai tahap itu di periode ini.</p>
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-4 divide-x divide-y sm:divide-y-0 divide-slate-800/70">
                            <div class="p-4 text-center">
                                <div class="text-white font-bold text-lg">{{ formatDurasi(kecepatanPipeline?.pemberkasan) }}</div>
                                <div class="text-slate-500 text-[11px] mt-0.5">Pemberkasan</div>
                            </div>
                            <div class="p-4 text-center">
                                <div class="text-white font-bold text-lg">{{ formatDurasi(kecepatanPipeline?.proses_bank) }}</div>
                                <div class="text-slate-500 text-[11px] mt-0.5">Proses Bank</div>
                            </div>
                            <div class="p-4 text-center">
                                <div class="text-white font-bold text-lg">{{ formatDurasi(kecepatanPipeline?.sp3k) }}</div>
                                <div class="text-slate-500 text-[11px] mt-0.5">SP3K</div>
                            </div>
                            <div class="p-4 text-center">
                                <div class="text-white font-bold text-lg">{{ formatDurasi(kecepatanPipeline?.rencana_akad) }}</div>
                                <div class="text-slate-500 text-[11px] mt-0.5">Rencana Akad</div>
                            </div>
                        </div>
                        <div class="grid grid-cols-3 divide-x divide-slate-800/70 border-t border-slate-800">
                            <div class="p-4 text-center">
                                <div class="text-slate-300 font-semibold text-sm">{{ formatDurasi(kecepatanPipeline?.booking_ke_akad) }}</div>
                                <div class="text-slate-500 text-[11px] mt-0.5">Total: Booking → Akad</div>
                            </div>
                            <div class="p-4 text-center">
                                <div class="text-slate-300 font-semibold text-sm">{{ formatDurasi(kecepatanPipeline?.akad_ke_bast) }}</div>
                                <div class="text-slate-500 text-[11px] mt-0.5">Akad → BAST</div>
                            </div>
                            <div class="p-4 text-center">
                                <div class="text-slate-300 font-semibold text-sm">{{ formatDurasi(kecepatanPipeline?.booking_ke_bast) }}</div>
                                <div class="text-slate-500 text-[11px] mt-0.5">Total Cycle</div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                        <div class="px-5 py-4 border-b border-slate-800">
                            <h3 class="text-slate-200 font-semibold text-sm">🚫 Cancellation Rate</h3>
                        </div>
                        <div class="p-5 text-center">
                            <div class="text-rose-400 font-bold text-3xl">{{ cancellationRatePeriod?.rate ?? 0 }}%</div>
                            <div class="text-slate-500 text-xs mt-1">{{ cancellationRatePeriod?.jumlah_dibatalkan ?? 0 }} dari {{ cancellationRatePeriod?.jumlah_booking ?? 0 }} booking</div>
                        </div>
                    </div>
                </div>

                <!-- Performa Sales -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                    <div class="px-5 py-4 border-b border-slate-800">
                        <h3 class="text-slate-200 font-semibold text-sm">🏆 Performa Sales</h3>
                    </div>
                    <div v-if="!performaSales?.length" class="text-center text-slate-600 text-xs py-8">Belum ada booking dengan sales/agent di periode ini.</div>
                    <table v-else class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-800 text-left text-slate-500 text-xs uppercase tracking-wider">
                                <th class="px-5 py-2.5">Sales / Agent</th>
                                <th class="px-4 py-2.5 text-right">Booking</th>
                                <th class="px-5 py-2.5 text-right">Conversion Rate</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60">
                            <tr v-for="s in performaSales" :key="s.sales_agent_id">
                                <td class="px-5 py-2.5 text-slate-200">{{ s.nama }} <span v-if="s.tipe_label" class="text-slate-500 text-xs">· {{ s.tipe_label }}</span></td>
                                <td class="px-4 py-2.5 text-right text-slate-300 font-medium">{{ s.jumlah_booking }}</td>
                                <td class="px-5 py-2.5 text-right text-slate-300">{{ s.conversion_rate }}%</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Grafik Tren — kontrol tahun terpisah -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                    <div class="px-5 py-4 border-b border-slate-800 flex items-center justify-between">
                        <h3 class="text-slate-200 font-semibold text-sm">📈 Grafik Tren</h3>
                        <select v-model.number="tahunTren" @change="applyTahunTren"
                            class="px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500 cursor-pointer">
                            <option v-for="y in (trenTahunan?.tahun_options ?? [])" :key="y" :value="y">{{ y }}</option>
                        </select>
                    </div>
                    <div class="p-5 space-y-6">
                        <!-- Booking/Akad/BAST -->
                        <div>
                            <div class="flex items-center gap-4 mb-3 text-xs">
                                <span class="flex items-center gap-1.5 text-slate-400"><span class="w-2.5 h-2.5 rounded-full bg-blue-500 inline-block"/>Booking</span>
                                <span class="flex items-center gap-1.5 text-slate-400"><span class="w-2.5 h-2.5 rounded-full bg-violet-500 inline-block"/>Akad</span>
                                <span class="flex items-center gap-1.5 text-slate-400"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500 inline-block"/>BAST</span>
                            </div>
                            <svg :viewBox="`0 0 ${trenSvgW} ${trenSvgH}`" preserveAspectRatio="none" class="w-full" style="height: 140px">
                                <polyline :points="trenLinePoints('booking', trenSvgH, trenMaxCount)" fill="none" stroke="#3b82f6" stroke-width="2" />
                                <polyline :points="trenLinePoints('akad', trenSvgH, trenMaxCount)" fill="none" stroke="#8b5cf6" stroke-width="2" />
                                <polyline :points="trenLinePoints('bast', trenSvgH, trenMaxCount)" fill="none" stroke="#10b981" stroke-width="2" />
                                <template v-for="(b, i) in (trenTahunan?.bulan ?? [])" :key="`pt-${i}`">
                                    <circle :cx="trenX(i)" :cy="trenY(b.booking, trenMaxCount, trenSvgH)" r="3" fill="#3b82f6"><title>Booking {{ b.label }}: {{ b.booking }}</title></circle>
                                    <circle :cx="trenX(i)" :cy="trenY(b.akad, trenMaxCount, trenSvgH)" r="3" fill="#8b5cf6"><title>Akad {{ b.label }}: {{ b.akad }}</title></circle>
                                    <circle :cx="trenX(i)" :cy="trenY(b.bast, trenMaxCount, trenSvgH)" r="3" fill="#10b981"><title>BAST {{ b.label }}: {{ b.bast }}</title></circle>
                                </template>
                            </svg>
                            <div class="flex mt-1">
                                <span v-for="b in (trenTahunan?.bulan ?? [])" :key="b.label" class="flex-1 text-center text-slate-500 text-[10px]">{{ b.label }}</span>
                            </div>
                        </div>
                        <!-- Revenue -->
                        <div>
                            <div class="text-slate-400 text-xs mb-3">Pembayaran Diterima per Bulan</div>
                            <svg :viewBox="`0 0 ${trenSvgW} ${trenRevenueSvgH}`" preserveAspectRatio="none" class="w-full" style="height: 90px">
                                <polyline :points="trenLinePoints('revenue', trenRevenueSvgH, trenMaxRevenue)" fill="none" stroke="#f59e0b" stroke-width="2" />
                                <template v-for="(b, i) in (trenTahunan?.bulan ?? [])" :key="`rpt-${i}`">
                                    <circle :cx="trenX(i)" :cy="trenY(b.revenue, trenMaxRevenue, trenRevenueSvgH)" r="3" fill="#f59e0b"><title>{{ b.label }}: {{ formatRp(b.revenue) }}</title></circle>
                                </template>
                            </svg>
                            <div class="flex mt-1">
                                <span v-for="b in (trenTahunan?.bulan ?? [])" :key="b.label" class="flex-1 text-center text-slate-500 text-[10px]">{{ b.label }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bottom Grid: Projects Summary + Recent Activity -->
            <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
                <!-- Projects Summary (mode "Semua Proyek" saja) -->
                <div v-if="projectsSummary?.length" class="xl:col-span-2 bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                    <div class="flex items-center justify-between px-5 py-4 border-b border-slate-800">
                        <h2 class="text-slate-200 font-semibold text-sm">Proyek Aktif</h2>
                        <Link :href="route('beranda')" class="text-xs text-violet-400 hover:text-violet-300 transition-colors font-medium">
                            Lihat semua →
                        </Link>
                    </div>
                    <div class="divide-y divide-slate-800/70">
                        <div
                            v-for="project in projectsSummary"
                            :key="project.id"
                            class="px-5 py-4 hover:bg-slate-800/30 transition-colors group"
                        >
                            <div class="flex items-center justify-between mb-2">
                                <div>
                                    <Link
                                        :href="route('projects.show', project.id)"
                                        class="text-slate-200 text-sm font-medium hover:text-violet-300 transition-colors"
                                    >
                                        {{ project.nama }}
                                    </Link>
                                    <div class="text-slate-500 text-xs mt-0.5">{{ project.kode }} · {{ project.kota }}</div>
                                </div>
                                <div class="text-right">
                                    <div class="text-slate-200 text-sm font-semibold">{{ project.kavlings_sold }} / {{ project.kavlings_count }}</div>
                                    <div class="text-slate-500 text-xs">Terjual</div>
                                </div>
                            </div>
                            <!-- Progress Bar -->
                            <div class="h-1.5 bg-slate-800 rounded-full overflow-hidden">
                                <div
                                    class="h-full bg-gradient-to-r from-violet-500 to-indigo-500 rounded-full transition-all duration-700"
                                    :style="{ width: project.progress + '%' }"
                                />
                            </div>
                            <div class="text-slate-500 text-xs mt-1 text-right">{{ project.progress }}% terjual</div>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div :class="projectsSummary?.length ? '' : 'xl:col-span-3'" class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                    <div class="px-5 py-4 border-b border-slate-800">
                        <h2 class="text-slate-200 font-semibold text-sm">Aktivitas Terbaru</h2>
                    </div>
                    <div class="divide-y divide-slate-800/70 overflow-y-auto max-h-80">
                        <div v-if="!recentActivities?.length" class="px-5 py-8 text-center text-slate-500 text-sm">
                            Belum ada aktivitas.
                        </div>
                        <div
                            v-for="activity in recentActivities"
                            :key="activity.id"
                            class="px-5 py-3"
                        >
                            <div class="flex items-start gap-3">
                                <div class="w-7 h-7 rounded-full bg-gradient-to-br from-violet-500/30 to-indigo-600/30 border border-violet-500/20 flex items-center justify-center text-violet-400 text-xs font-semibold flex-shrink-0 mt-0.5">
                                    {{ activity.causer_name?.slice(0, 1) ?? 'S' }}
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-slate-300 text-xs leading-relaxed">{{ activity.description }}</p>
                                    <div class="flex items-center gap-2 mt-1">
                                        <span class="text-slate-500 text-xs">{{ activity.causer_name }}</span>
                                        <span class="text-slate-700 text-xs">·</span>
                                        <span class="text-slate-500 text-xs">{{ activity.created_at }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Piutang Jatuh Tempo -->
            <div v-if="piutangJatuhTempo?.items?.length" class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-800 flex items-center justify-between">
                    <h2 class="text-slate-200 font-semibold text-sm">⏰ Piutang Jatuh Tempo</h2>
                    <span class="text-slate-500 text-xs">{{ piutangJatuhTempo.total_count }} cicilan · {{ formatRp(piutangJatuhTempo.total_nominal) }}</span>
                </div>
                <div class="divide-y divide-slate-800/70">
                    <Link v-for="item in piutangJatuhTempo.items" :key="item.id"
                        :href="route('keuangan.detail', item.transaksi_id)"
                        class="px-5 py-3 flex items-center justify-between hover:bg-slate-800/20 transition-colors">
                        <div>
                            <div class="text-slate-200 text-sm font-medium">{{ item.konsumen_nama }}</div>
                            <div class="text-slate-500 text-xs mt-0.5">{{ item.kavling }} · {{ item.project }} · {{ item.jenis_label }}</div>
                        </div>
                        <div class="text-right">
                            <div class="text-slate-200 text-sm font-medium">{{ formatRp(item.jumlah) }}</div>
                            <div class="text-rose-400 text-xs mt-0.5">Terlambat {{ item.hari_terlambat }} hari</div>
                        </div>
                    </Link>
                </div>
            </div>

            <!-- BAST Tertunda -->
            <div v-if="bastTertunda?.length" class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-800 flex items-center justify-between">
                    <h2 class="text-slate-200 font-semibold text-sm">🔑 BAST Tertunda</h2>
                    <span class="text-slate-500 text-xs">{{ bastTertunda.length }} unit siap serah terima</span>
                </div>
                <div class="divide-y divide-slate-800/70">
                    <Link v-for="item in bastTertunda" :key="item.id"
                        :href="route('dokumen.index', item.id)"
                        class="px-5 py-3 flex items-center justify-between hover:bg-slate-800/20 transition-colors">
                        <div>
                            <div class="text-slate-200 text-sm font-medium">{{ item.konsumen_nama }}</div>
                            <div class="text-slate-500 text-xs mt-0.5">{{ item.kavling }} · {{ item.project }}</div>
                        </div>
                        <div class="text-right">
                            <span class="text-xs px-2 py-0.5 rounded-full font-medium bg-emerald-500/15 text-emerald-400">Siap Serah Terima</span>
                            <div class="text-slate-500 text-xs mt-1">Akad {{ item.tanggal_akad }}</div>
                        </div>
                    </Link>
                </div>
            </div>

            <!-- SP3K Expiry Monitoring -->
            <div v-if="sp3kMonitoring?.length" class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-800 flex items-center justify-between">
                    <h2 class="text-slate-200 font-semibold text-sm">🔔 Monitoring Masa Berlaku SP3K</h2>
                    <span class="text-slate-500 text-xs">{{ sp3kMonitoring.length }} transaksi</span>
                </div>
                <div class="divide-y divide-slate-800/70">
                    <div v-for="item in sp3kMonitoring" :key="item.id"
                        class="px-5 py-3 flex items-center justify-between hover:bg-slate-800/20 transition-colors">
                        <div>
                            <div class="text-slate-200 text-sm font-medium">{{ item.konsumen_nama }}</div>
                            <div class="text-slate-500 text-xs mt-0.5">{{ item.kavling }} · {{ item.project }}</div>
                        </div>
                        <div class="text-right">
                            <span :class="sp3kBadge[item.sp3k_expiry_status]?.cls" class="text-xs px-2 py-0.5 rounded-full font-medium">
                                {{ sp3kBadge[item.sp3k_expiry_status]?.label }}
                            </span>
                            <div class="text-slate-500 text-xs mt-1">{{ item.tanggal_expired_sp3k }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
