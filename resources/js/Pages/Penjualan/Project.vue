<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InlineSiteplanSvg from '@/Components/InlineSiteplanSvg.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

const props = defineProps({
    project:    Object,
    kavlings:   Array,
    konsumens:  Array,
    salesAgents: { type: Array, default: () => [] },
    biayaTambahanPresets: { type: Array, default: () => [] },
    promoPresets: { type: Array, default: () => [] },
    skemaDpPresets: { type: Array, default: () => [] },
});

const todayIso = () => new Date().toISOString().slice(0, 10);
const basisLabels = { harga_dasar: 'Harga Dasar', harga_netto: 'Harga Jual Netto' };

const page = usePage();
const user = computed(() => page.props.auth.user);
const isSalesOnly = computed(() =>
    user.value?.roles?.includes('sales') &&
    !user.value?.roles?.includes('superadmin') &&
    !user.value?.roles?.includes('manajer')
);

// ── View mode ────────────────────────────────────────────────────────
const viewMode = ref('siteplan');

// ── Status config ────────────────────────────────────────────────────
const statusConfig = {
    available:              { label: 'Tersedia',       siteplan: 'bg-emerald-500/25 hover:bg-emerald-500/40 border-2 border-emerald-500 hover:border-emerald-400 shadow-lg shadow-emerald-500/20',  badge: 'bg-emerald-500/15 text-emerald-400 ring-1 ring-emerald-500/30', dot: 'bg-emerald-400' },
    hold:                   { label: 'Tidak Tersedia', siteplan: 'bg-yellow-500/25 border-2 border-yellow-500 opacity-80',                                        badge: 'bg-yellow-500/15 text-yellow-400 ring-1 ring-yellow-500/30', dot: 'bg-yellow-400' },
    booked:                 { label: 'Dipesan',        siteplan: 'bg-blue-500/25 border-2 border-blue-500 opacity-80',                                                                                 badge: 'bg-blue-500/15 text-blue-400 ring-1 ring-blue-500/30', dot: 'bg-blue-400' },
    sold:                   { label: 'Terjual',        siteplan: 'bg-rose-500/25 border-2 border-rose-500 opacity-70',                                                                                 badge: 'bg-rose-500/15 text-rose-400 ring-1 ring-rose-500/30', dot: 'bg-rose-400' },
    cancellation_requested: { label: 'Pembatalan',     siteplan: 'bg-orange-500/25 border-2 border-orange-500 opacity-70',                                                                             badge: 'bg-orange-500/15 text-orange-400 ring-1 ring-orange-500/30', dot: 'bg-orange-400' },
};

// Warna hex untuk fill elemen SVG (siteplan berbasis SVG ID-matching)
const statusColorHex = {
    available:              '#10b981',
    hold:                   '#eab308',
    booked:                 '#3b82f6',
    sold:                   '#f43f5e',
    cancellation_requested: '#f97316',
};

const isSvgSiteplan = computed(() =>
    (props.project.siteplan_image ?? '').toLowerCase().endsWith('.svg')
);

const statusBangunLabels = {
    not_started:    'Belum Mulai',
    foundation:     'Pondasi',
    structure:      'Struktur',
    roofing:        'Atap',
    finishing:      'Finishing',
    handover_ready: 'Siap Serah Terima',
};

const statusBangunColorHex = {
    not_started:    '#64748b',
    foundation:     '#f97316',
    structure:      '#3b82f6',
    roofing:        '#6366f1',
    finishing:      '#a855f7',
    handover_ready: '#10b981',
};

const statusBangunOptionsLegend = [
    { value: 'not_started',    label: 'Belum Mulai' },
    { value: 'foundation',     label: 'Pondasi' },
    { value: 'structure',      label: 'Struktur' },
    { value: 'roofing',        label: 'Atap' },
    { value: 'finishing',      label: 'Finishing' },
    { value: 'handover_ready', label: 'Siap Serah Terima' },
];

// Ukuran marker siteplan mengikuti pengaturan admin di halaman Proyek
// (tidak ada slider terpisah di sini, supaya tampilan selalu konsisten).
const markerSize = computed(() => props.project.siteplan_marker_size ?? 28);

// ── Multi-filter (kluster/blok/tipe/status jual/status bangun) ───────────
const filters = ref({ kluster: '', blok: '', tipe_unit: '', status_jual: '', status_bangun: '' });

const uniqueOptions = (key) => {
    const values = (props.kavlings ?? []).map(k => k[key]).filter(v => v !== null && v !== undefined && v !== '');
    return [...new Set(values)].sort();
};
const klusterOptions  = computed(() => uniqueOptions('kluster'));
const blokOptions     = computed(() => uniqueOptions('blok'));
const tipeUnitOptions = computed(() => uniqueOptions('tipe_unit'));
const statusBangunOptions = computed(() => uniqueOptions('status_bangun'));

const filteredKavlings = computed(() => (props.kavlings ?? []).filter(k =>
    (!filters.value.kluster || k.kluster === filters.value.kluster) &&
    (!filters.value.blok || k.blok === filters.value.blok) &&
    (!filters.value.tipe_unit || k.tipe_unit === filters.value.tipe_unit) &&
    (!filters.value.status_jual || k.status_jual === filters.value.status_jual) &&
    (!filters.value.status_bangun || k.status_bangun === filters.value.status_bangun)
));

const resetFilters = () => {
    filters.value = { kluster: '', blok: '', tipe_unit: '', status_jual: '', status_bangun: '' };
};

const activeFilterCount = computed(() => Object.values(filters.value).filter(Boolean).length);

// ── Kavling grouped by blok ──────────────────────────────────────────
const kavlingsByBlok = computed(() => {
    const groups = {};
    filteredKavlings.value.forEach(k => {
        const blok = k.blok ?? '-';
        if (!groups[blok]) groups[blok] = [];
        groups[blok].push(k);
    });
    return groups;
});

const kavlingsWithKoordinat = computed(() =>
    filteredKavlings.value.filter(k => k.koordinat_x != null && k.koordinat_y != null)
);

// ── Selected kavling ──────────────────────────────────────────────────
const selectedKavling = ref(null);
const showDetailModal  = ref(false);
const showBookModal    = ref(false);

const selectKavling = (kavling) => {
    selectedKavling.value = kavling;
    showDetailModal.value = true;
};

const openBook = () => {
    if (!selectedKavling.value) return;
    bookForm.reset();
    bookForm.tanggal_booking = todayIso();
    showDetailModal.value = false;
    showBookModal.value = true;
};

// ── Booking Form ──────────────────────────────────────────────────────
const bookForm = useForm({
    // Section 1 — Konsumen, Sales & Unit
    tanggal_booking: todayIso(),
    konsumen_id:     null,
    konsumen_nama:   '',
    konsumen_no_hp:  '',
    konsumen_nik:    '',
    konsumen_email:  '',
    konsumen_mode:   'existing', // 'existing' | 'new'
    sales_agent_id:  null,
    // Section 2 — Biaya Kelebihan Tanah
    biaya_kelebihan_tanah_aktif: false,
    biaya_kelebihan_tanah_luas: '',
    biaya_kelebihan_tanah_mode: 'per_m2', // 'per_m2' | 'nominal'
    biaya_kelebihan_tanah_harga_per_m2: '',
    biaya_kelebihan_tanah_nominal_input: '',
    // Section 2 — Biaya Tambahan Lain (tambah dari preset satu per satu)
    biaya_tambahan_selected: [],
    biaya_tambahan_nominals: {},
    // Section 2 — Diskon / Promo
    promo_preset_id: null,
    diskon_mode:     '', // '' | 'persen' | 'nominal'
    diskon_nilai:    '',
    // Section 3 — Skema Pembayaran (baru terbuka setelah harga netto selesai dihitung)
    cara_bayar:      'kpr_subsidi',
    skema_dp_preset_id: null,
    booking_fee:     '',
    dp_nominal:      '',
    cicilan_kali:    '',
    catatan:         '',
});

const selectedSalesAgent = computed(() =>
    props.salesAgents.find(a => a.id === bookForm.sales_agent_id)
);

// ── Section 2: Biaya Lainnya — tombol "+ Tambah" dari preset ─────────────
const showBiayaPicker = ref(false);
const availableBiayaPresets = computed(() =>
    props.biayaTambahanPresets.filter(p => !bookForm.biaya_tambahan_selected.includes(p.id))
);
const biayaPresetNama = (id) => props.biayaTambahanPresets.find(p => p.id === id)?.nama ?? '-';

const addBiayaTambahan = (presetId) => {
    bookForm.biaya_tambahan_selected.push(presetId);
    bookForm.biaya_tambahan_nominals[presetId] = '';
    showBiayaPicker.value = false;
};
const removeBiayaTambahan = (presetId) => {
    const idx = bookForm.biaya_tambahan_selected.indexOf(presetId);
    if (idx !== -1) bookForm.biaya_tambahan_selected.splice(idx, 1);
    delete bookForm.biaya_tambahan_nominals[presetId];
};

// ── Kalkulasi Harga Jual Netto (live preview — perhitungan final tetap di server) ──
const hargaDasar = computed(() => Number(selectedKavling.value?.harga) || 0);

const biayaKelebihanTanahNominal = computed(() => {
    if (!bookForm.biaya_kelebihan_tanah_aktif) return 0;
    if (bookForm.biaya_kelebihan_tanah_mode === 'per_m2') {
        return (Number(bookForm.biaya_kelebihan_tanah_luas) || 0) * (Number(bookForm.biaya_kelebihan_tanah_harga_per_m2) || 0);
    }
    return Number(bookForm.biaya_kelebihan_tanah_nominal_input) || 0;
});

const biayaTambahanLainTotal = computed(() =>
    bookForm.biaya_tambahan_selected.reduce((sum, id) => sum + (Number(bookForm.biaya_tambahan_nominals[id]) || 0), 0)
);

const totalBiayaTambahan = computed(() => biayaKelebihanTanahNominal.value + biayaTambahanLainTotal.value);

const subtotalSebelumDiskon = computed(() => hargaDasar.value + totalBiayaTambahan.value);

const diskonNominal = computed(() => {
    if (!bookForm.diskon_mode || !bookForm.diskon_nilai) return 0;
    return bookForm.diskon_mode === 'persen'
        ? subtotalSebelumDiskon.value * (Number(bookForm.diskon_nilai) / 100)
        : Number(bookForm.diskon_nilai);
});

const hargaJualNetto = computed(() => subtotalSebelumDiskon.value - diskonNominal.value);

// ── Section 3: Skema Pembayaran — dropdown difilter oleh cara bayar ──────
const availableSkemaPresets = computed(() =>
    props.skemaDpPresets.filter(p => !p.cara_bayar || p.cara_bayar === bookForm.cara_bayar)
);
const selectedSkemaPreset = computed(() =>
    props.skemaDpPresets.find(p => p.id === bookForm.skema_dp_preset_id)
);

// Basis "harga_dasar" / "harga_netto" ditentukan per-preset di Pengaturan
// Skema Pembayaran — bukan diasumsikan tetap di sini.
const resolveBasisValue = (basis) => basis === 'harga_dasar' ? hargaDasar.value : hargaJualNetto.value;

const resolveNominal = (tipe, nilai, basis) => {
    if (!nilai) return 0;
    return tipe === 'persen' ? Math.round((Number(nilai) / 100) * resolveBasisValue(basis)) : Number(nilai);
};

// Ganti cara bayar -> reset pilihan skema pembayaran (daftar presetnya berubah)
watch(() => bookForm.cara_bayar, () => {
    bookForm.skema_dp_preset_id = null;
    bookForm.booking_fee = 0;
    bookForm.dp_nominal = 0;
});

// Pilih skema pembayaran -> hitung & kunci kolom yang relevan (nilai final
// tetap dihitung ulang & ditegakkan di server, ini cuma preview terkunci).
watch(() => bookForm.skema_dp_preset_id, () => {
    const preset = selectedSkemaPreset.value;
    bookForm.booking_fee = preset?.booking_fee_aktif
        ? resolveNominal(preset.booking_fee_tipe, preset.booking_fee_nilai, preset.booking_fee_basis)
        : 0;
    bookForm.dp_nominal = preset?.dp_aktif
        ? resolveNominal(preset.dp_tipe, preset.dp_nilai, preset.dp_basis)
        : 0;
});

const canChooseSkemaPembayaran = computed(() => hargaJualNetto.value > 0);

// Build skema_dp string & biaya_tambahan array sebelum submit
const submitBooking = () => {
    const preset = selectedSkemaPreset.value;
    const skema = (preset?.dp_aktif && bookForm.dp_nominal)
        ? `nominal:${bookForm.dp_nominal}`
        : 'tanpa_dp';

    const biayaTambahanPayload = bookForm.biaya_tambahan_selected.map(id => ({
        preset_id: id,
        nominal: Number(bookForm.biaya_tambahan_nominals[id]) || 0,
    }));

    const payload = {
        ...bookForm.data(),
        skema_dp: skema,
        booking_fee: preset?.booking_fee_aktif ? (Number(bookForm.booking_fee) || 0) : 0,
        biaya_tambahan: biayaTambahanPayload,
    };
    delete payload.biaya_tambahan_selected;
    delete payload.biaya_tambahan_nominals;
    delete payload.dp_nominal;

    bookForm.transform(() => payload).post(
        route('bookings.store', selectedKavling.value.id),
        { onSuccess: () => { showBookModal.value = false; bookForm.reset(); } }
    );
};

const formatRupiah = (v) => v
    ? 'Rp ' + Number(v).toLocaleString('id-ID')
    : '-';

const isBookable = (k) => k.status_jual === 'available';
</script>

<template>
    <Head :title="`Penjualan – ${project.nama}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2 text-slate-400 text-sm">
                <Link :href="route('penjualan.index')" class="hover:text-slate-200 transition-colors">Penjualan</Link>
                <span>/</span>
                <span class="text-slate-200 font-medium">{{ project.nama }}</span>
            </div>
        </template>

        <div class="p-6 space-y-5">
            <!-- Header Stats -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-white font-bold text-xl">{{ project.nama }}</h1>
                    <p class="text-slate-400 text-sm mt-0.5">{{ project.kota }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <span class="px-3 py-1.5 bg-emerald-500/10 text-emerald-400 ring-1 ring-emerald-500/20 rounded-lg text-xs font-medium">
                        {{ project.kavlings_available }} Tersedia
                    </span>
                    <span class="px-3 py-1.5 bg-blue-500/10 text-blue-400 ring-1 ring-blue-500/20 rounded-lg text-xs font-medium">
                        {{ project.kavlings_booked }} Dipesan
                    </span>
                    <span class="px-3 py-1.5 bg-rose-500/10 text-rose-400 ring-1 ring-rose-500/20 rounded-lg text-xs font-medium">
                        {{ project.kavlings_sold }} Terjual
                    </span>
                </div>
            </div>

            <!-- View Toggle -->
            <div class="flex items-center gap-1 bg-slate-900 border border-slate-800 rounded-lg p-1 w-fit">
                <button @click="viewMode = 'siteplan'"
                    :class="viewMode === 'siteplan' ? 'bg-violet-600 text-white shadow-lg shadow-violet-500/20' : 'text-slate-400 hover:text-slate-200'"
                    class="px-4 py-1.5 rounded-md text-sm font-medium transition-all">
                    🗺️ Siteplan
                </button>
                <button @click="viewMode = 'table'"
                    :class="viewMode === 'table' ? 'bg-violet-600 text-white shadow-lg shadow-violet-500/20' : 'text-slate-400 hover:text-slate-200'"
                    class="px-4 py-1.5 rounded-md text-sm font-medium transition-all">
                    📋 Tabel Unit
                </button>
            </div>

            <!-- Legend -->
            <div class="flex flex-wrap items-center gap-4">
                <div v-for="(cfg, key) in statusConfig" :key="key" class="flex items-center gap-2 text-xs text-slate-400">
                    <span :class="cfg.dot" class="w-3 h-3 rounded-sm inline-block"></span>
                    {{ cfg.label }}
                </div>
                <span class="text-slate-700">|</span>
                <span class="text-slate-500 text-xs">Cincin warna = status pembangunan</span>
                <div v-for="opt in statusBangunOptionsLegend" :key="opt.value" class="flex items-center gap-1.5 text-xs text-slate-500">
                    <span class="w-2.5 h-2.5 rounded-full inline-block ring-2" :style="`background:${statusBangunColorHex[opt.value]}30; box-shadow: 0 0 0 2px ${statusBangunColorHex[opt.value]}`"></span>
                    {{ opt.label }}
                </div>
            </div>

            <!-- ── Multi-Filter (hanya di Table view) ──────────────────────── -->
            <div v-if="viewMode === 'table'" class="bg-slate-900 border border-slate-800 rounded-xl p-3 flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center gap-1.5 text-slate-500 text-xs font-medium mr-1">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3c2.755 0 5.455.232 8.083.678.533.09.917.556.917 1.096v1.044a2.25 2.25 0 01-.659 1.591l-5.432 5.432a2.25 2.25 0 00-.659 1.591v2.927a2.25 2.25 0 01-1.244 2.013L9.75 21v-6.568a2.25 2.25 0 00-.659-1.591L3.659 7.409A2.25 2.25 0 013 5.818V4.774c0-.54.384-1.006.917-1.096A48.32 48.32 0 0112 3z" /></svg>
                    Filter
                </span>
                <div v-for="(sel, idx) in [
                        { model: 'kluster', options: klusterOptions, placeholder: 'Semua Kluster' },
                        { model: 'blok', options: blokOptions, placeholder: 'Semua Blok' },
                        { model: 'tipe_unit', options: tipeUnitOptions, placeholder: 'Semua Tipe' },
                    ]" :key="idx" class="relative">
                    <select v-model="filters[sel.model]"
                        class="appearance-none pl-2.5 pr-7 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500 cursor-pointer">
                        <option value="">{{ sel.placeholder }}</option>
                        <option v-for="v in sel.options" :key="v" :value="v">{{ v }}</option>
                    </select>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3.5 h-3.5 text-slate-500 absolute right-2 top-1/2 -translate-y-1/2 pointer-events-none"><path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 011.06 0L10 11.94l3.72-3.72a.75.75 0 111.06 1.06l-4.25 4.25a.75.75 0 01-1.06 0L5.22 9.28a.75.75 0 010-1.06z" clip-rule="evenodd"/></svg>
                </div>
                <div class="relative">
                    <select v-model="filters.status_jual"
                        class="appearance-none pl-2.5 pr-7 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500 cursor-pointer">
                        <option value="">Semua Status Jual</option>
                        <option v-for="(cfg, key) in statusConfig" :key="key" :value="key">{{ cfg.label }}</option>
                    </select>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3.5 h-3.5 text-slate-500 absolute right-2 top-1/2 -translate-y-1/2 pointer-events-none"><path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 011.06 0L10 11.94l3.72-3.72a.75.75 0 111.06 1.06l-4.25 4.25a.75.75 0 01-1.06 0L5.22 9.28a.75.75 0 010-1.06z" clip-rule="evenodd"/></svg>
                </div>
                <div class="relative">
                    <select v-model="filters.status_bangun"
                        class="appearance-none pl-2.5 pr-7 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500 cursor-pointer">
                        <option value="">Semua Status Bangun</option>
                        <option v-for="v in statusBangunOptions" :key="v" :value="v">{{ statusBangunLabels[v] ?? v }}</option>
                    </select>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3.5 h-3.5 text-slate-500 absolute right-2 top-1/2 -translate-y-1/2 pointer-events-none"><path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 011.06 0L10 11.94l3.72-3.72a.75.75 0 111.06 1.06l-4.25 4.25a.75.75 0 01-1.06 0L5.22 9.28a.75.75 0 010-1.06z" clip-rule="evenodd"/></svg>
                </div>
                <button v-if="activeFilterCount > 0" @click="resetFilters"
                    class="px-2.5 py-1.5 text-slate-400 hover:text-slate-200 text-xs rounded-lg transition-colors">
                    ✕ Reset ({{ activeFilterCount }})
                </button>
                <span class="text-slate-500 text-xs ml-auto">{{ filteredKavlings.length }} / {{ (kavlings ?? []).length }} unit</span>
            </div>

            <!-- ── Siteplan View ─────────────────────────────── -->
            <div v-if="viewMode === 'siteplan'" class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div v-if="!project.siteplan_image" class="flex flex-col items-center justify-center py-20 text-slate-500">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" class="w-12 h-12 mb-3 opacity-50">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498l4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 00-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0z" />
                    </svg>
                    <p class="text-sm">Siteplan belum diupload oleh admin</p>
                </div>
                <!-- Siteplan SVG (ID-matching, warna & klik otomatis) -->
                <div v-else-if="isSvgSiteplan" class="p-3">
                    <InlineSiteplanSvg
                        :siteplan-url="project.siteplan_image"
                        :kavlings="kavlings"
                        :status-colors="statusColorHex"
                        :interactive="true"
                        :selected-id="selectedKavling?.id"
                        @select="selectKavling"
                    />
                </div>
                <!-- Siteplan gambar raster (fallback: marker koordinat persen) -->
                <div v-else class="relative select-none" style="min-height: 400px;">
                    <img :src="project.siteplan_image" class="w-full object-contain" alt="Siteplan" />
                    <!-- Unit markers -->
                    <template v-for="kavling in kavlingsWithKoordinat" :key="kavling.id">
                        <div
                            @click="selectKavling(kavling)"
                            class="group absolute transform -translate-x-1/2 -translate-y-1/2 z-0 hover:z-20"
                            :style="`left: ${kavling.koordinat_x}%; top: ${kavling.koordinat_y}%;`"
                        >
                            <!-- Lingkaran marker -->
                            <div
                                :class="[
                                    'rounded-full flex items-center justify-center transition-all duration-150 cursor-pointer',
                                    statusConfig[kavling.status_jual]?.siteplan ?? 'bg-gray-500/40 border-2 border-gray-500'
                                ]"
                                :style="`width: ${markerSize}px; height: ${markerSize}px; box-shadow: 0 0 0 2px ${statusBangunColorHex[kavling.status_bangun] ?? '#64748b'};`"
                            >
                                <span class="text-white font-bold drop-shadow leading-none select-none" :style="`font-size: ${Math.max(8, Math.round(markerSize / 2.6))}px;`">{{ kavling.nomor_kavling }}</span>
                            </div>

                            <!-- Hover card: identitas + status jual/bangun -->
                            <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-1.5 w-max max-w-[180px] opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-opacity duration-100 pointer-events-none">
                                <div class="bg-slate-950 border border-slate-700 rounded-lg px-2.5 py-1.5 shadow-xl text-left">
                                    <div class="text-white text-xs font-semibold">{{ kavling.nomor_lengkap }}</div>
                                    <div class="flex items-center gap-1 mt-0.5">
                                        <span :class="statusConfig[kavling.status_jual]?.dot" class="w-1.5 h-1.5 rounded-full inline-block"></span>
                                        <span class="text-slate-300 text-[11px]">{{ statusConfig[kavling.status_jual]?.label }}</span>
                                    </div>
                                    <div class="flex items-center gap-1 mt-0.5">
                                        <span class="w-1.5 h-1.5 rounded-full inline-block" :style="`background:${statusBangunColorHex[kavling.status_bangun]}`"></span>
                                        <span class="text-slate-400 text-[11px]">{{ statusBangunLabels[kavling.status_bangun] ?? kavling.status_bangun }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                    <div v-if="kavlingsWithKoordinat.length === 0" class="absolute inset-0 flex items-center justify-center">
                        <span class="bg-slate-900/80 text-slate-400 text-sm px-4 py-2 rounded-lg">
                            Posisi unit belum ditentukan oleh admin
                        </span>
                    </div>
                </div>
            </div>

            <!-- ── Tabel Unit View ─────────────────────────────── -->
            <div v-if="viewMode === 'table'" class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div v-if="!filteredKavlings.length" class="text-center py-12 text-slate-500 text-sm">
                    Tidak ada unit yang cocok dengan filter.
                </div>
                <div v-for="(kavlings, blok) in kavlingsByBlok" :key="blok" class="mb-0">
                    <div class="px-4 py-2 bg-slate-800/50 border-b border-slate-800 text-slate-400 text-xs font-semibold uppercase tracking-wider">
                        Blok {{ blok }}
                    </div>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-xs text-slate-500 border-b border-slate-800">
                                <th class="px-4 py-2 text-left font-medium">Kluster</th>
                                <th class="px-4 py-2 text-left font-medium">Kavling</th>
                                <th class="px-4 py-2 text-left font-medium">Tipe</th>
                                <th class="px-4 py-2 text-left font-medium">Luas</th>
                                <th class="px-4 py-2 text-left font-medium">Harga</th>
                                <th class="px-4 py-2 text-left font-medium">Status Jual</th>
                                <th class="px-4 py-2 text-left font-medium">Pembangunan</th>
                                <th class="px-4 py-2 text-left font-medium">Konsumen</th>
                                <th class="px-4 py-2 text-right font-medium">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="k in kavlings" :key="k.id" class="border-b border-slate-800/50 hover:bg-slate-800/30 transition-colors">
                                <td class="px-4 py-2.5 text-slate-400 text-xs">{{ k.kluster ?? '-' }}</td>
                                <td class="px-4 py-2.5 text-slate-200 font-medium">{{ k.nomor_lengkap }}</td>
                                <td class="px-4 py-2.5 text-slate-400 text-xs">{{ k.tipe_unit ?? '-' }}</td>
                                <td class="px-4 py-2.5 text-slate-400 text-xs">
                                    <div v-if="k.luas_tanah">T: {{ k.luas_tanah }} m²</div>
                                    <div v-if="k.luas_bangunan" class="text-slate-500">B: {{ k.luas_bangunan }} m²</div>
                                </td>
                                <td class="px-4 py-2.5 text-slate-300 text-xs font-mono">{{ formatRupiah(k.harga) }}</td>
                                <td class="px-4 py-2.5">
                                    <span :class="statusConfig[k.status_jual]?.badge" class="px-2 py-0.5 rounded-full text-xs font-medium">
                                        {{ statusConfig[k.status_jual]?.label ?? k.status_jual }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5">
                                    <div class="flex items-center gap-2">
                                        <div class="w-12 h-1 bg-slate-800 rounded-full overflow-hidden flex-shrink-0">
                                            <div class="h-full bg-gradient-to-r from-violet-500 to-indigo-500 rounded-full" :style="{ width: (k.progress_bangun ?? 0) + '%' }"/>
                                        </div>
                                        <span class="text-slate-400 text-xs">{{ k.status_bangun_label }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 text-slate-400 text-xs">{{ k.konsumen_nama ?? '-' }}</td>
                                <td class="px-4 py-2.5 text-right">
                                    <div class="inline-flex items-center gap-1.5">
                                        <button @click="selectKavling(k)"
                                            class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs rounded-lg transition-colors">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3.5 h-3.5"><path d="M10 12.5a2.5 2.5 0 100-5 2.5 2.5 0 000 5z"/><path fill-rule="evenodd" d="M.664 10.59a1.651 1.651 0 010-1.186A10.004 10.004 0 0110 3c4.257 0 7.893 2.66 9.336 6.41.147.381.146.804 0 1.186A10.004 10.004 0 0110 17c-4.257 0-7.893-2.66-9.336-6.41zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"/></svg>
                                            Detail
                                        </button>
                                        <button
                                            v-if="isBookable(k)"
                                            @click="selectedKavling = k; openBook()"
                                            class="px-3 py-1 bg-violet-600 hover:bg-violet-500 text-white text-xs rounded-lg transition-colors font-medium"
                                        >
                                            Booking
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ── Detail Modal (setara dengan modal di halaman Proyek, minus edit) ── -->
        <Teleport to="body">
            <div v-if="showDetailModal && selectedKavling"
                class="fixed inset-0 z-50 flex items-center justify-center p-4"
                @click.self="showDetailModal = false">
                <div class="absolute inset-0 bg-black/80 backdrop-blur-sm" @click="showDetailModal = false" />
                <div class="relative bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-2xl shadow-2xl overflow-hidden max-h-[90vh] overflow-y-auto">

                    <div :class="{ available: 'bg-emerald-500', hold: 'bg-yellow-500', booked: 'bg-blue-500', sold: 'bg-rose-500', cancellation_requested: 'bg-orange-500' }[selectedKavling.status_jual] ?? 'bg-slate-700'" class="h-1.5 w-full"/>

                    <div class="flex items-start justify-between p-5 border-b border-slate-800">
                        <div class="flex items-center gap-4">
                            <div :class="['w-14 h-14 rounded-2xl flex items-center justify-center text-2xl font-black shadow-lg', statusConfig[selectedKavling.status_jual]?.badge ?? 'bg-slate-700 text-slate-300']">
                                {{ selectedKavling.nomor_kavling }}
                            </div>
                            <div>
                                <div class="flex items-center gap-2 flex-wrap">
                                    <h3 class="text-white font-bold text-xl">{{ selectedKavling.nomor_lengkap }}</h3>
                                    <span :class="statusConfig[selectedKavling.status_jual]?.badge" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold">
                                        {{ statusConfig[selectedKavling.status_jual]?.label }}
                                    </span>
                                </div>
                                <div class="text-slate-400 text-sm mt-0.5">{{ project.nama }}</div>
                            </div>
                        </div>
                        <button @click="showDetailModal = false" class="text-slate-500 hover:text-slate-300 transition-colors p-1 flex-shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-6 h-6"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/></svg>
                        </button>
                    </div>

                    <div class="p-5 space-y-5">
                        <!-- Info Grid -->
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                            <div class="bg-slate-800/60 rounded-xl p-3 text-center">
                                <div class="text-slate-500 text-xs mb-1">Kluster</div>
                                <div class="text-white font-bold text-lg">{{ selectedKavling.kluster ?? '-' }}</div>
                            </div>
                            <div class="bg-slate-800/60 rounded-xl p-3 text-center">
                                <div class="text-slate-500 text-xs mb-1">Blok</div>
                                <div class="text-white font-bold text-lg">{{ selectedKavling.blok ?? '-' }}</div>
                            </div>
                            <div class="bg-slate-800/60 rounded-xl p-3 text-center">
                                <div class="text-slate-500 text-xs mb-1">Tipe</div>
                                <div class="text-white font-bold text-lg">{{ selectedKavling.tipe_unit ?? '-' }}</div>
                            </div>
                            <div class="bg-slate-800/60 rounded-xl p-3 text-center">
                                <div class="text-slate-500 text-xs mb-1">Kamar</div>
                                <div class="text-white font-bold text-lg">{{ selectedKavling.kamar_tidur ?? '-' }}KT / {{ selectedKavling.kamar_mandi ?? '-' }}KM</div>
                            </div>
                            <div class="bg-slate-800/60 rounded-xl p-3 text-center">
                                <div class="text-slate-500 text-xs mb-1">Luas Tanah</div>
                                <div class="text-white font-bold text-lg">{{ selectedKavling.luas_tanah ?? '-' }} <span class="text-xs font-normal">m²</span></div>
                            </div>
                            <div class="bg-slate-800/60 rounded-xl p-3 text-center">
                                <div class="text-slate-500 text-xs mb-1">Luas Bangunan</div>
                                <div class="text-white font-bold text-lg">{{ selectedKavling.luas_bangunan ?? '-' }} <span class="text-xs font-normal">m²</span></div>
                            </div>
                        </div>

                        <!-- Harga -->
                        <div class="bg-gradient-to-r from-violet-500/10 to-indigo-500/10 border border-violet-500/20 rounded-xl p-4">
                            <div class="text-slate-400 text-xs font-medium">Harga</div>
                            <div class="text-violet-300 font-black text-2xl mt-0.5">{{ formatRupiah(selectedKavling.harga) }}</div>
                        </div>

                        <!-- Progress Pembangunan (read-only, bukan bagian sales) -->
                        <div class="bg-slate-800/50 rounded-xl p-4 space-y-3">
                            <div class="flex items-center justify-between">
                                <div class="text-slate-300 font-semibold text-sm">Progress Pembangunan</div>
                                <span class="text-slate-300 text-xs font-medium">{{ selectedKavling.status_bangun_label }}</span>
                            </div>
                            <div class="h-2.5 bg-slate-700 rounded-full overflow-hidden">
                                <div class="h-full bg-gradient-to-r from-violet-500 to-indigo-400 rounded-full transition-all duration-700" :style="{ width: (selectedKavling.progress_bangun ?? 0) + '%' }"/>
                            </div>
                        </div>

                        <!-- Keterangan -->
                        <div v-if="selectedKavling.keterangan" class="bg-violet-500/10 border border-violet-500/20 rounded-xl p-3">
                            <div class="text-violet-400 text-xs font-medium mb-1">🏷️ Keterangan</div>
                            <div class="text-slate-300 text-sm">{{ selectedKavling.keterangan }}</div>
                        </div>

                        <!-- Konsumen -->
                        <div v-if="selectedKavling.konsumen_nama" class="bg-blue-500/10 border border-blue-500/20 rounded-xl p-4 flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-blue-500/20 flex items-center justify-center text-blue-400 font-bold text-sm flex-shrink-0">
                                {{ selectedKavling.konsumen_nama?.slice(0, 1) }}
                            </div>
                            <div>
                                <div class="text-slate-400 text-xs">Pembeli / Pemesan</div>
                                <div class="text-blue-300 font-semibold">{{ selectedKavling.konsumen_nama }}</div>
                            </div>
                        </div>

                        <!-- Catatan -->
                        <div v-if="selectedKavling.catatan" class="bg-amber-500/10 border border-amber-500/20 rounded-xl p-3">
                            <div class="text-amber-400 text-xs font-medium mb-1">📝 Catatan</div>
                            <div class="text-slate-300 text-sm">{{ selectedKavling.catatan }}</div>
                        </div>

                        <!-- Foto & Denah Rumah -->
                        <div v-if="selectedKavling.foto_rumah || selectedKavling.denah_rumah" class="grid grid-cols-2 gap-3">
                            <div v-if="selectedKavling.foto_rumah" class="space-y-2">
                                <div class="text-slate-400 text-xs font-medium uppercase tracking-wider">Foto Rumah</div>
                                <img :src="selectedKavling.foto_rumah" class="w-full aspect-video object-cover rounded-xl bg-slate-800" alt="Foto Rumah" />
                            </div>
                            <div v-if="selectedKavling.denah_rumah" class="space-y-2">
                                <div class="text-slate-400 text-xs font-medium uppercase tracking-wider">Denah Rumah</div>
                                <img :src="selectedKavling.denah_rumah" class="w-full aspect-video object-contain rounded-xl bg-white" alt="Denah Rumah" />
                            </div>
                        </div>

                        <!-- Actions: hanya Booking & Tukar Unit -->
                        <div class="flex gap-3 pt-2 border-t border-slate-800">
                            <button @click="showDetailModal = false" class="flex-1 py-2.5 text-slate-400 hover:text-slate-200 text-sm border border-slate-700 rounded-lg transition-colors">
                                Tutup
                            </button>
                            <button
                                v-if="isBookable(selectedKavling)"
                                @click="openBook"
                                class="flex-1 py-2.5 bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-500 hover:to-indigo-500 text-white text-sm font-medium rounded-lg transition-all shadow-lg shadow-violet-500/20"
                            >
                                🏠 Booking Sekarang
                            </button>
                            <Link
                                v-if="selectedKavling.transaksi_id"
                                :href="`${route('konsumens.show', selectedKavling.konsumen_id)}?transaksi=${selectedKavling.transaksi_id}`"
                                class="flex-1 py-2.5 bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 text-sm font-medium rounded-lg transition-colors text-center"
                            >
                                📄 Lihat Detail Pesanan
                            </Link>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Booking Modal ─────────────────────────────────── -->
            <div v-if="showBookModal && selectedKavling"
                class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm overflow-y-auto"
                @click.self="showBookModal = false">
                <div class="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-lg shadow-2xl my-4">
                    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-800">
                        <div>
                            <h3 class="text-white font-semibold">Form Booking</h3>
                            <p class="text-slate-400 text-xs mt-0.5">Unit {{ selectedKavling.nomor_lengkap }} – {{ formatRupiah(selectedKavling.harga) }}</p>
                        </div>
                        <button @click="showBookModal = false" class="text-slate-500 hover:text-slate-300">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    <div class="px-6 py-5 space-y-5 max-h-[70vh] overflow-y-auto">
                        <!-- Ringkasan error — jaring pengaman kalau ada field gagal validasi
                             yang inputnya sedang tersembunyi (mis. mode konsumen berbeda) -->
                        <div v-if="Object.keys(bookForm.errors).length" class="bg-rose-500/10 border border-rose-500/30 rounded-xl p-3 space-y-1">
                            <p class="text-rose-400 text-xs font-semibold">Booking belum bisa diproses, periksa kembali:</p>
                            <ul class="text-rose-300 text-xs list-disc list-inside space-y-0.5">
                                <li v-for="(msg, field) in bookForm.errors" :key="field">{{ msg }}</li>
                            </ul>
                        </div>

                        <!-- ═══ SECTION 1: Konsumen, Sales & Pemilihan Unit ═══ -->

                        <!-- Info Unit Terpilih -->
                        <div class="bg-slate-800/60 rounded-xl p-3 grid grid-cols-4 gap-2 text-center">
                            <div>
                                <div class="text-slate-500 text-[10px]">Unit</div>
                                <div class="text-slate-200 text-sm font-semibold">{{ selectedKavling.nomor_lengkap }}</div>
                            </div>
                            <div>
                                <div class="text-slate-500 text-[10px]">Tipe</div>
                                <div class="text-slate-200 text-sm font-semibold">{{ selectedKavling.tipe_unit ?? '-' }}</div>
                            </div>
                            <div>
                                <div class="text-slate-500 text-[10px]">Luas T/B</div>
                                <div class="text-slate-200 text-sm font-semibold">{{ selectedKavling.luas_tanah ?? '-' }}/{{ selectedKavling.luas_bangunan ?? '-' }}</div>
                            </div>
                            <div>
                                <div class="text-slate-500 text-[10px]">Harga Dasar</div>
                                <div class="text-violet-300 text-sm font-semibold">{{ formatRupiah(selectedKavling.harga) }}</div>
                            </div>
                        </div>

                        <!-- Tanggal Booking -->
                        <div>
                            <label class="block text-slate-400 text-xs mb-1.5">Tanggal Booking <span class="text-rose-400">*</span></label>
                            <input v-model="bookForm.tanggal_booking" type="date"
                                class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"
                                :class="{ 'border-rose-500': bookForm.errors.tanggal_booking }" />
                            <p v-if="bookForm.errors.tanggal_booking" class="text-rose-400 text-xs mt-1">{{ bookForm.errors.tanggal_booking }}</p>
                        </div>

                        <!-- Konsumen -->
                        <div class="space-y-3">
                            <h4 class="text-slate-300 text-sm font-medium border-b border-slate-800 pb-2">Data Konsumen</h4>
                            <div class="flex gap-3">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" v-model="bookForm.konsumen_mode" value="existing" class="accent-violet-500" />
                                    <span class="text-slate-300 text-sm">Konsumen Lama</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" v-model="bookForm.konsumen_mode" value="new" class="accent-violet-500" />
                                    <span class="text-slate-300 text-sm">Konsumen Baru</span>
                                </label>
                            </div>
                            <div v-if="bookForm.konsumen_mode === 'existing'">
                                <label class="block text-slate-400 text-xs mb-1.5">Cari Konsumen <span class="text-rose-400">*</span></label>
                                <SearchSelect
                                    v-model="bookForm.konsumen_id"
                                    :items="konsumens"
                                    :search-keys="['nama', 'no_hp', 'nik']"
                                    label-key="nama"
                                    placeholder="Cari nama, No. HP, atau NIK..."
                                    :option-hint="k => k.no_hp ?? ''"
                                />
                                <p v-if="bookForm.errors.konsumen_id" class="text-rose-400 text-xs mt-1">{{ bookForm.errors.konsumen_id }}</p>
                            </div>
                            <div v-else class="space-y-3">
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-slate-400 text-xs mb-1.5">Nama Konsumen <span class="text-rose-400">*</span></label>
                                        <input v-model="bookForm.konsumen_nama" type="text" placeholder="Nama lengkap"
                                            class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"
                                            :class="{ 'border-rose-500': bookForm.errors.konsumen_nama }" />
                                        <p v-if="bookForm.errors.konsumen_nama" class="text-rose-400 text-xs mt-1">{{ bookForm.errors.konsumen_nama }}</p>
                                    </div>
                                    <div>
                                        <label class="block text-slate-400 text-xs mb-1.5">No. HP/WA <span class="text-rose-400">*</span></label>
                                        <input v-model="bookForm.konsumen_no_hp" type="text" placeholder="08xxxxxxxxxx"
                                            class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                    </div>
                                    <div>
                                        <label class="block text-slate-400 text-xs mb-1.5">NIK</label>
                                        <input v-model="bookForm.konsumen_nik" type="text" placeholder="16 digit NIK"
                                            class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                    </div>
                                    <div>
                                        <label class="block text-slate-400 text-xs mb-1.5">Email</label>
                                        <input v-model="bookForm.konsumen_email" type="email" placeholder="nama@email.com"
                                            class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Sales / Agent -->
                        <div class="space-y-2">
                            <h4 class="text-slate-300 text-sm font-medium border-b border-slate-800 pb-2">Sales / Agent</h4>
                            <SearchSelect
                                v-model="bookForm.sales_agent_id"
                                :items="salesAgents"
                                :search-keys="['nama', 'agency_nama']"
                                label-key="nama"
                                placeholder="Cari sales atau agent..."
                                :option-hint="a => a.tipe_label"
                            />
                            <div v-if="selectedSalesAgent" class="flex items-center gap-2 text-xs bg-violet-500/10 border border-violet-500/20 rounded-lg px-3 py-2">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3.5 h-3.5 text-violet-400 flex-shrink-0"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
                                <span class="text-violet-300">Skema komisi terkunci: <strong>{{ selectedSalesAgent.komisi_label }}</strong></span>
                                <span v-if="selectedSalesAgent.agency_nama" class="text-slate-500">· {{ selectedSalesAgent.agency_nama }}</span>
                            </div>
                        </div>

                        <!-- ═══ SECTION 2: Biaya Tambahan & Diskon (harga netto dulu sebelum skema pembayaran) ═══ -->

                        <!-- Biaya Kelebihan Tanah -->
                        <div class="space-y-3">
                            <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                                <h4 class="text-slate-300 text-sm font-medium">Biaya Kelebihan Tanah</h4>
                                <button type="button" @click="bookForm.biaya_kelebihan_tanah_aktif = !bookForm.biaya_kelebihan_tanah_aktif"
                                    :class="bookForm.biaya_kelebihan_tanah_aktif ? 'bg-violet-600' : 'bg-slate-700'"
                                    class="relative w-11 h-6 rounded-full transition-colors flex-shrink-0">
                                    <span :class="bookForm.biaya_kelebihan_tanah_aktif ? 'translate-x-5' : 'translate-x-0.5'"
                                        class="absolute top-0.5 w-5 h-5 bg-white rounded-full transition-transform" />
                                </button>
                            </div>
                            <div v-if="bookForm.biaya_kelebihan_tanah_aktif" class="space-y-3">
                                <div class="flex gap-3">
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="radio" v-model="bookForm.biaya_kelebihan_tanah_mode" value="per_m2" class="accent-violet-500" />
                                        <span class="text-slate-300 text-sm">Per m²</span>
                                    </label>
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="radio" v-model="bookForm.biaya_kelebihan_tanah_mode" value="nominal" class="accent-violet-500" />
                                        <span class="text-slate-300 text-sm">Nominal Langsung</span>
                                    </label>
                                </div>
                                <div v-if="bookForm.biaya_kelebihan_tanah_mode === 'per_m2'" class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-slate-400 text-xs mb-1.5">Luas Kelebihan (m²)</label>
                                        <input v-model="bookForm.biaya_kelebihan_tanah_luas" type="number" min="0" step="0.01" placeholder="0"
                                            class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                    </div>
                                    <div>
                                        <label class="block text-slate-400 text-xs mb-1.5">Harga per m² (Rp)</label>
                                        <input v-model="bookForm.biaya_kelebihan_tanah_harga_per_m2" type="number" min="0" placeholder="0"
                                            class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                    </div>
                                </div>
                                <div v-else>
                                    <label class="block text-slate-400 text-xs mb-1.5">Nominal (Rp)</label>
                                    <input v-model="bookForm.biaya_kelebihan_tanah_nominal_input" type="number" min="0" placeholder="0"
                                        class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                </div>
                                <div class="text-xs text-slate-500">Subtotal: <span class="text-slate-300 font-medium">{{ formatRupiah(biayaKelebihanTanahNominal) }}</span></div>
                            </div>
                        </div>

                        <!-- Biaya Lainnya (preset admin) — tambah satu per satu -->
                        <div class="space-y-2">
                            <h4 class="text-slate-300 text-sm font-medium border-b border-slate-800 pb-2">Biaya Lainnya</h4>

                            <div v-if="!bookForm.biaya_tambahan_selected.length" class="text-slate-600 text-xs">
                                Belum ada biaya tambahan lain.
                            </div>
                            <div v-for="id in bookForm.biaya_tambahan_selected" :key="id" class="flex items-center gap-2">
                                <span class="flex-1 text-slate-300 text-sm">{{ biayaPresetNama(id) }}</span>
                                <input v-model="bookForm.biaya_tambahan_nominals[id]" type="number" min="0" placeholder="Rp 0"
                                    class="w-32 px-3 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                <button type="button" @click="removeBiayaTambahan(id)" class="text-slate-500 hover:text-rose-400 transition-colors p-1">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/></svg>
                                </button>
                            </div>

                            <div class="relative">
                                <button type="button" @click="showBiayaPicker = !showBiayaPicker"
                                    v-if="availableBiayaPresets.length"
                                    class="inline-flex items-center gap-1.5 text-violet-400 hover:text-violet-300 text-xs font-medium transition-colors">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3.5 h-3.5"><path d="M10.75 4.75a.75.75 0 00-1.5 0v4.5h-4.5a.75.75 0 000 1.5h4.5v4.5a.75.75 0 001.5 0v-4.5h4.5a.75.75 0 000-1.5h-4.5v-4.5z"/></svg>
                                    Tambah Biaya
                                </button>
                                <p v-else-if="!bookForm.biaya_tambahan_selected.length" class="text-slate-600 text-xs">Belum ada preset biaya tambahan di Pengaturan.</p>

                                <div v-if="showBiayaPicker" class="absolute z-10 mt-1 w-56 bg-slate-800 border border-slate-700 rounded-lg shadow-xl overflow-hidden">
                                    <button v-for="preset in availableBiayaPresets" :key="preset.id" type="button"
                                        @click="addBiayaTambahan(preset.id)"
                                        class="w-full text-left px-3 py-2 text-slate-200 text-sm hover:bg-slate-700 transition-colors">
                                        {{ preset.nama }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Diskon / Promo -->
                        <div class="space-y-3">
                            <h4 class="text-slate-300 text-sm font-medium border-b border-slate-800 pb-2">Diskon / Promo</h4>
                            <select v-model="bookForm.promo_preset_id"
                                class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500">
                                <option :value="null">Tanpa Promo</option>
                                <option v-for="promo in promoPresets" :key="promo.id" :value="promo.id">{{ promo.nama }}</option>
                            </select>
                            <div v-if="bookForm.promo_preset_id" class="space-y-2">
                                <div class="flex gap-3">
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="radio" v-model="bookForm.diskon_mode" value="persen" class="accent-violet-500" />
                                        <span class="text-slate-300 text-sm">%</span>
                                    </label>
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="radio" v-model="bookForm.diskon_mode" value="nominal" class="accent-violet-500" />
                                        <span class="text-slate-300 text-sm">Nominal</span>
                                    </label>
                                </div>
                                <input v-model="bookForm.diskon_nilai" type="number" min="0" :placeholder="bookForm.diskon_mode === 'persen' ? '%' : 'Rp'"
                                    class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                            </div>
                        </div>

                        <!-- Summary Harga Netto -->
                        <div class="bg-gradient-to-r from-violet-500/10 to-indigo-500/10 border border-violet-500/20 rounded-xl p-4 space-y-1.5 text-sm">
                            <div class="flex justify-between text-slate-400">
                                <span>Harga Dasar</span>
                                <span class="text-slate-300">{{ formatRupiah(hargaDasar) }}</span>
                            </div>
                            <div class="flex justify-between text-slate-400">
                                <span>+ Total Biaya Tambahan</span>
                                <span class="text-slate-300">{{ formatRupiah(totalBiayaTambahan) }}</span>
                            </div>
                            <div class="flex justify-between text-slate-400">
                                <span>− Diskon</span>
                                <span class="text-slate-300">{{ formatRupiah(diskonNominal) }}</span>
                            </div>
                            <div class="flex justify-between pt-1.5 border-t border-violet-500/20 font-semibold">
                                <span class="text-violet-300">Harga Jual Netto</span>
                                <span class="text-violet-300 text-base">{{ formatRupiah(hargaJualNetto) }}</span>
                            </div>
                        </div>

                        <!-- ═══ SECTION 3: Skema Pembayaran (baru terbuka setelah harga netto selesai) ═══ -->
                        <div v-if="!canChooseSkemaPembayaran" class="text-center py-4 text-slate-600 text-xs border border-dashed border-slate-700 rounded-xl">
                            Lengkapi harga jual netto di atas dulu sebelum memilih skema pembayaran.
                        </div>
                        <div v-else class="space-y-4 pt-1 border-t border-slate-800">
                            <div>
                                <label class="block text-slate-400 text-xs mb-1.5">Cara Pembayaran <span class="text-rose-400">*</span></label>
                                <select v-model="bookForm.cara_bayar"
                                    class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500">
                                    <option value="cash">Cash</option>
                                    <option value="cash_bertahap">Cash Bertahap</option>
                                    <option value="kpr_subsidi">KPR Subsidi</option>
                                    <option value="kpr_komersil">KPR Komersil</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-slate-400 text-xs mb-1.5">Skema Pembayaran <span class="text-rose-400">*</span></label>
                                <select v-model="bookForm.skema_dp_preset_id"
                                    class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"
                                    :class="{ 'border-rose-500': bookForm.errors.skema_dp_preset_id }">
                                    <option :value="null">Pilih skema pembayaran...</option>
                                    <option v-for="preset in availableSkemaPresets" :key="preset.id" :value="preset.id">{{ preset.nama }}</option>
                                </select>
                                <p v-if="!availableSkemaPresets.length" class="text-amber-400 text-xs mt-1">Belum ada preset skema pembayaran untuk cara bayar ini di Pengaturan.</p>
                                <p v-if="bookForm.errors.skema_dp_preset_id" class="text-rose-400 text-xs mt-1">{{ bookForm.errors.skema_dp_preset_id }}</p>
                            </div>

                            <!-- Kolom dinamis sesuai atribut preset yang dipilih — nominal dikunci dari preset, tidak bisa diedit manual -->
                            <template v-if="selectedSkemaPreset">
                                <div v-if="selectedSkemaPreset.booking_fee_aktif">
                                    <label class="block text-slate-400 text-xs mb-1.5">Booking Fee / UTJ</label>
                                    <div class="flex items-center justify-between px-3 py-2.5 bg-slate-800/60 border border-slate-700 rounded-lg">
                                        <span class="text-slate-200 text-sm font-medium">{{ formatRupiah(bookForm.booking_fee) }}</span>
                                        <span class="text-slate-500 text-xs">
                                            {{ selectedSkemaPreset.booking_fee_tipe === 'persen' ? `${selectedSkemaPreset.booking_fee_nilai}% dari ${basisLabels[selectedSkemaPreset.booking_fee_basis]}` : 'Nominal tetap' }} · terkunci
                                        </span>
                                    </div>
                                </div>
                                <div v-if="selectedSkemaPreset.dp_aktif">
                                    <label class="block text-slate-400 text-xs mb-1.5">DP</label>
                                    <div class="flex items-center justify-between px-3 py-2.5 bg-slate-800/60 border border-slate-700 rounded-lg">
                                        <span class="text-slate-200 text-sm font-medium">{{ formatRupiah(bookForm.dp_nominal) }}</span>
                                        <span class="text-slate-500 text-xs">
                                            {{ selectedSkemaPreset.dp_tipe === 'persen' ? `${selectedSkemaPreset.dp_nilai}% dari ${basisLabels[selectedSkemaPreset.dp_basis]}` : 'Nominal tetap' }} · terkunci
                                        </span>
                                    </div>
                                </div>
                                <p v-if="!selectedSkemaPreset.booking_fee_aktif && !selectedSkemaPreset.dp_aktif" class="text-slate-600 text-xs">
                                    Skema ini tidak ada booking fee maupun DP.
                                </p>
                            </template>

                            <!-- Cicilan Kali (jika Cash Bertahap) -->
                            <div v-if="bookForm.cara_bayar === 'cash_bertahap'">
                                <label class="block text-slate-400 text-xs mb-1.5">Jumlah Cicilan (x)</label>
                                <input v-model="bookForm.cicilan_kali" type="number" min="1" placeholder="misal: 12"
                                    class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                            </div>

                            <p v-if="['kpr_subsidi','kpr_komersil'].includes(bookForm.cara_bayar)" class="text-slate-600 text-xs">
                                Plafon KPR, tenor, dan preset Dana Jaminan/SBUM diisi belakangan di halaman database konsumen — baru bisa dihitung final setelah jumlah SBUM diketahui.
                            </p>
                        </div>

                        <!-- Catatan -->
                        <div>
                            <label class="block text-slate-400 text-xs mb-1.5">Catatan</label>
                            <textarea v-model="bookForm.catatan" rows="2" placeholder="Catatan tambahan (opsional)"
                                class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500 resize-none" />
                        </div>
                    </div>

                    <div class="px-6 py-4 border-t border-slate-800 flex gap-3">
                        <button @click="showBookModal = false" class="flex-1 py-2.5 text-slate-400 hover:text-slate-200 text-sm border border-slate-700 rounded-lg transition-colors">
                            Batal
                        </button>
                        <button
                            @click="submitBooking"
                            :disabled="bookForm.processing"
                            class="flex-1 py-2.5 bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-500 hover:to-indigo-500 disabled:opacity-60 text-white text-sm font-medium rounded-lg transition-all shadow-lg shadow-violet-500/20"
                        >
                            {{ bookForm.processing ? 'Memproses...' : '✅ Konfirmasi Booking' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
