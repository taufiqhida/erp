<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InlineSiteplanSvg from '@/Components/InlineSiteplanSvg.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

const props = defineProps({
    project:    Object,
    kavlings:   Array,
    konsumens:  Array,
    pembiayaan: Object,
});

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
    available:              { label: 'Tersedia',   siteplan: 'bg-emerald-500/25 hover:bg-emerald-500/40 border-2 border-emerald-500 hover:border-emerald-400 cursor-pointer shadow-lg shadow-emerald-500/20',  badge: 'bg-emerald-500/15 text-emerald-400 ring-1 ring-emerald-500/30' },
    hold:                   { label: 'Hold',       siteplan: 'bg-yellow-500/25 hover:bg-yellow-500/40 border-2 border-yellow-500 hover:border-yellow-400 cursor-pointer',                                        badge: 'bg-yellow-500/15 text-yellow-400 ring-1 ring-yellow-500/30' },
    booked:                 { label: 'Booking',    siteplan: 'bg-blue-500/25 border-2 border-blue-500 cursor-default opacity-80',                                                                                 badge: 'bg-blue-500/15 text-blue-400 ring-1 ring-blue-500/30' },
    sold:                   { label: 'Terjual',    siteplan: 'bg-rose-500/25 border-2 border-rose-500 cursor-default opacity-70',                                                                                 badge: 'bg-rose-500/15 text-rose-400 ring-1 ring-rose-500/30' },
    cancellation_requested: { label: 'Pembatalan', siteplan: 'bg-orange-500/25 border-2 border-orange-500 cursor-default opacity-70',                                                                             badge: 'bg-orange-500/15 text-orange-400 ring-1 ring-orange-500/30' },
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

// ── Kavling grouped by blok ──────────────────────────────────────────
const kavlingsByBlok = computed(() => {
    const groups = {};
    (props.kavlings ?? []).forEach(k => {
        const blok = k.blok ?? '-';
        if (!groups[blok]) groups[blok] = [];
        groups[blok].push(k);
    });
    return groups;
});

const kavlingsWithKoordinat = computed(() =>
    (props.kavlings ?? []).filter(k => k.koordinat_x != null && k.koordinat_y != null)
);

// ── Selected kavling ──────────────────────────────────────────────────
const selectedKavling = ref(null);
const showDetailModal  = ref(false);
const showBookModal    = ref(false);
const showSwapModal    = ref(false);

const canSwap = computed(() => user.value?.permissions?.includes('swap kavling'));
const isSwappable = (k) => k?.transaksi_id && ['booked', 'sold'].includes(k.status_jual);
const availableForSwap = computed(() =>
    (props.kavlings ?? []).filter(k => k.status_jual === 'available' && k.id !== selectedKavling.value?.id)
);

const swapForm = useForm({ kavling_baru_id: '', alasan: '' });

const openSwap = () => {
    if (!selectedKavling.value) return;
    swapForm.reset();
    showDetailModal.value = false;
    showSwapModal.value = true;
};

const submitSwap = () => {
    swapForm.patch(route('bookings.swap-unit', selectedKavling.value.transaksi_id), {
        onSuccess: () => { showSwapModal.value = false; swapForm.reset(); selectedKavling.value = null; }
    });
};

const selectKavling = (kavling) => {
    if (!['available', 'hold'].includes(kavling.status_jual)) {
        selectedKavling.value = kavling;
        showDetailModal.value = true;
        return;
    }
    selectedKavling.value = kavling;
    showDetailModal.value = true;
};

const openBook = () => {
    if (!selectedKavling.value) return;
    bookForm.harga_deal = selectedKavling.value.harga ?? '';
    showDetailModal.value = false;
    showBookModal.value = true;
};

// ── Booking Form ──────────────────────────────────────────────────────
const bookForm = useForm({
    konsumen_id:     '',
    konsumen_nama:   '',
    konsumen_no_hp:  '',
    konsumen_mode:   'existing', // 'existing' | 'new'
    booking_fee:     '',
    cara_bayar:      'kpr_subsidi',
    cicilan_kali:    '',
    skema_dp:        'tanpa_dp', // 'tanpa_dp' | 'nominal' | 'persen'
    skema_dp_nominal: '',
    skema_dp_persen:  '',
    skema_dp_kali:    '',
    plafon_kpr:      '',
    harga_deal:      '',
    catatan:         '',
});

const skemaOptions = [
    { value: 'tanpa_dp', label: 'Tanpa DP' },
    { value: 'nominal',  label: 'Nominal' },
    { value: 'persen',   label: 'Persentase' },
];

// Build skema_dp string sebelum submit
const submitBooking = () => {
    let skema = bookForm.skema_dp;
    if (skema === 'nominal') skema = `nominal:${bookForm.skema_dp_nominal}`;
    if (skema === 'persen')  skema = `persen:${bookForm.skema_dp_persen}:${bookForm.skema_dp_kali}x`;

    const payload = { ...bookForm.data(), skema_dp: skema };

    bookForm.transform(() => payload).post(
        route('bookings.store', selectedKavling.value.id),
        { onSuccess: () => { showBookModal.value = false; bookForm.reset(); } }
    );
};

const formatRupiah = (v) => v
    ? 'Rp ' + Number(v).toLocaleString('id-ID')
    : '-';

const isBookable = (k) => ['available', 'hold'].includes(k.status_jual);

// Komponen pembiayaan KPR Subsidi dari pengaturan proyek
const subsidyComponents = computed(() =>
    props.pembiayaan?.kpr_subsidi_config ?? []
);
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
                        {{ project.kavlings_booked }} Booking
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
            <div class="flex flex-wrap items-center gap-3">
                <div v-for="(cfg, key) in statusConfig" :key="key" class="flex items-center gap-1.5">
                    <div :class="[
                        'w-3 h-3 rounded-sm border',
                        key === 'available' ? 'bg-emerald-500/40 border-emerald-500' :
                        key === 'hold'      ? 'bg-yellow-500/40 border-yellow-500' :
                        key === 'booked'    ? 'bg-blue-500/40 border-blue-500' :
                        key === 'sold'      ? 'bg-rose-500/40 border-rose-500' :
                                             'bg-orange-500/40 border-orange-500'
                    ]"/>
                    <span class="text-slate-400 text-xs">{{ cfg.label }}</span>
                </div>
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
                            :class="[
                                'absolute transform -translate-x-1/2 -translate-y-1/2 rounded px-1.5 py-0.5 text-xs font-bold transition-all duration-150',
                                statusConfig[kavling.status_jual]?.siteplan ?? 'bg-gray-500/20 border-2 border-gray-500'
                            ]"
                            :style="`left: ${kavling.koordinat_x}%; top: ${kavling.koordinat_y}%;`"
                            :title="kavling.nomor_lengkap"
                        >
                            <span class="text-white drop-shadow text-[10px]">{{ kavling.nomor_kavling }}</span>
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
                <div v-for="(kavlings, blok) in kavlingsByBlok" :key="blok" class="mb-0">
                    <div class="px-4 py-2 bg-slate-800/50 border-b border-slate-800 text-slate-400 text-xs font-semibold uppercase tracking-wider">
                        Blok {{ blok }}
                    </div>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-xs text-slate-500 border-b border-slate-800">
                                <th class="px-4 py-2 text-left font-medium">Unit</th>
                                <th class="px-4 py-2 text-right font-medium">LT / LB</th>
                                <th class="px-4 py-2 text-right font-medium">Harga</th>
                                <th class="px-4 py-2 text-left font-medium">Status</th>
                                <th class="px-4 py-2 text-left font-medium">Konsumen</th>
                                <th class="px-4 py-2 text-left font-medium">Keterangan</th>
                                <th class="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="k in kavlings" :key="k.id" class="border-b border-slate-800/50 hover:bg-slate-800/30 transition-colors">
                                <td class="px-4 py-2.5 text-slate-200 font-medium">{{ k.nomor_lengkap }}</td>
                                <td class="px-4 py-2.5 text-slate-400 text-right text-xs">
                                    {{ k.luas_tanah ?? '-' }} / {{ k.luas_bangunan ?? '-' }} m²
                                </td>
                                <td class="px-4 py-2.5 text-slate-300 text-right text-xs font-medium">{{ formatRupiah(k.harga) }}</td>
                                <td class="px-4 py-2.5">
                                    <span :class="statusConfig[k.status_jual]?.badge" class="px-2 py-0.5 rounded-full text-xs font-medium">
                                        {{ statusConfig[k.status_jual]?.label ?? k.status_jual }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-slate-400 text-xs">{{ k.konsumen_nama ?? '-' }}</td>
                                <td class="px-4 py-2.5 text-slate-500 text-xs">{{ k.keterangan ?? '-' }}</td>
                                <td class="px-4 py-2.5 text-right">
                                    <button
                                        v-if="isBookable(k)"
                                        @click="selectedKavling = k; openBook()"
                                        class="px-3 py-1 bg-violet-600 hover:bg-violet-500 text-white text-xs rounded-lg transition-colors font-medium"
                                    >
                                        Booking
                                    </button>
                                    <span v-else class="text-slate-600 text-xs">–</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ── Detail Modal ─────────────────────────────────────────── -->
        <Teleport to="body">
            <div v-if="showDetailModal && selectedKavling"
                class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm"
                @click.self="showDetailModal = false">
                <div class="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-md shadow-2xl">
                    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-800">
                        <h3 class="text-white font-semibold">{{ selectedKavling.nomor_lengkap }}</h3>
                        <button @click="showDetailModal = false" class="text-slate-500 hover:text-slate-300">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                    <div class="px-6 py-4 space-y-3 text-sm">
                        <div class="flex justify-between">
                            <span class="text-slate-400">Status</span>
                            <span :class="statusConfig[selectedKavling.status_jual]?.badge" class="px-2 py-0.5 rounded-full text-xs font-medium">
                                {{ statusConfig[selectedKavling.status_jual]?.label }}
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-400">Harga</span>
                            <span class="text-slate-200 font-medium">{{ formatRupiah(selectedKavling.harga) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-400">Luas Tanah / Bangunan</span>
                            <span class="text-slate-200">{{ selectedKavling.luas_tanah ?? '-' }} / {{ selectedKavling.luas_bangunan ?? '-' }} m²</span>
                        </div>
                        <div v-if="selectedKavling.keterangan" class="flex justify-between">
                            <span class="text-slate-400">Keterangan</span>
                            <span class="text-amber-400 font-medium">{{ selectedKavling.keterangan }}</span>
                        </div>
                        <div v-if="selectedKavling.konsumen_nama" class="flex justify-between">
                            <span class="text-slate-400">Konsumen</span>
                            <span class="text-slate-200">{{ selectedKavling.konsumen_nama }}</span>
                        </div>
                    </div>
                    <div class="px-6 py-4 border-t border-slate-800 flex gap-3">
                        <button @click="showDetailModal = false" class="flex-1 py-2 text-slate-400 hover:text-slate-200 text-sm border border-slate-700 rounded-lg transition-colors">
                            Tutup
                        </button>
                        <button
                            v-if="isBookable(selectedKavling)"
                            @click="openBook"
                            class="flex-1 py-2 bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-500 hover:to-indigo-500 text-white text-sm font-medium rounded-lg transition-all shadow-lg shadow-violet-500/20"
                        >
                            🏠 Booking Sekarang
                        </button>
                        <button
                            v-if="canSwap && isSwappable(selectedKavling)"
                            @click="openSwap"
                            class="flex-1 py-2 bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 text-sm font-medium rounded-lg transition-colors"
                        >
                            🔄 Tukar Unit
                        </button>
                    </div>
                </div>
            </div>

            <!-- ── Tukar Unit Modal ──────────────────────────────── -->
            <div v-if="showSwapModal && selectedKavling"
                class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm"
                @click.self="showSwapModal = false">
                <div class="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-md shadow-2xl">
                    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-800">
                        <div>
                            <h3 class="text-white font-semibold">Tukar Unit</h3>
                            <p class="text-slate-400 text-xs mt-0.5">Dari {{ selectedKavling.nomor_lengkap }} ke unit lain</p>
                        </div>
                        <button @click="showSwapModal = false" class="text-slate-500 hover:text-slate-300">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                    <div class="px-6 py-5 space-y-4">
                        <div>
                            <label class="block text-slate-400 text-xs mb-1.5">Unit Tujuan <span class="text-rose-400">*</span></label>
                            <select v-model="swapForm.kavling_baru_id"
                                class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"
                                :class="{ 'border-rose-500': swapForm.errors.kavling_baru_id }">
                                <option value="">Pilih unit tujuan...</option>
                                <option v-for="k in availableForSwap" :key="k.id" :value="k.id">{{ k.nomor_lengkap }} – {{ formatRupiah(k.harga) }}</option>
                            </select>
                            <p v-if="swapForm.errors.kavling_baru_id" class="text-rose-400 text-xs mt-1">{{ swapForm.errors.kavling_baru_id }}</p>
                            <p v-if="availableForSwap.length === 0" class="text-amber-400 text-xs mt-1">Tidak ada unit lain yang tersedia di proyek ini.</p>
                        </div>
                        <div>
                            <label class="block text-slate-400 text-xs mb-1.5">Alasan</label>
                            <textarea v-model="swapForm.alasan" rows="2" placeholder="Alasan tukar unit (opsional)"
                                class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500 resize-none" />
                        </div>
                    </div>
                    <div class="px-6 py-4 border-t border-slate-800 flex gap-3">
                        <button @click="showSwapModal = false" class="flex-1 py-2.5 text-slate-400 hover:text-slate-200 text-sm border border-slate-700 rounded-lg transition-colors">
                            Batal
                        </button>
                        <button
                            @click="submitSwap"
                            :disabled="swapForm.processing || !swapForm.kavling_baru_id"
                            class="flex-1 py-2.5 bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-500 hover:to-indigo-500 disabled:opacity-60 text-white text-sm font-medium rounded-lg transition-all shadow-lg shadow-violet-500/20"
                        >
                            {{ swapForm.processing ? 'Memproses...' : '✅ Konfirmasi Tukar Unit' }}
                        </button>
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
                        <!-- Konsumen -->
                        <div class="space-y-3">
                            <h4 class="text-slate-300 text-sm font-medium border-b border-slate-800 pb-2">Data Konsumen</h4>
                            <div class="flex gap-3">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" v-model="bookForm.konsumen_mode" value="existing" class="accent-violet-500" />
                                    <span class="text-slate-300 text-sm">Konsumen Existing</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" v-model="bookForm.konsumen_mode" value="new" class="accent-violet-500" />
                                    <span class="text-slate-300 text-sm">Konsumen Baru</span>
                                </label>
                            </div>
                            <div v-if="bookForm.konsumen_mode === 'existing'">
                                <label class="block text-slate-400 text-xs mb-1.5">Pilih Konsumen <span class="text-rose-400">*</span></label>
                                <select v-model="bookForm.konsumen_id"
                                    class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"
                                    :class="{ 'border-rose-500': bookForm.errors.konsumen_id }">
                                    <option value="">Pilih konsumen...</option>
                                    <option v-for="k in konsumens" :key="k.id" :value="k.id">{{ k.nama }} – {{ k.no_hp }}</option>
                                </select>
                                <p v-if="bookForm.errors.konsumen_id" class="text-rose-400 text-xs mt-1">{{ bookForm.errors.konsumen_id }}</p>
                            </div>
                            <div v-else class="space-y-3">
                                <div>
                                    <label class="block text-slate-400 text-xs mb-1.5">Nama Konsumen <span class="text-rose-400">*</span></label>
                                    <input v-model="bookForm.konsumen_nama" type="text" placeholder="Nama lengkap"
                                        class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"
                                        :class="{ 'border-rose-500': bookForm.errors.konsumen_nama }" />
                                    <p v-if="bookForm.errors.konsumen_nama" class="text-rose-400 text-xs mt-1">{{ bookForm.errors.konsumen_nama }}</p>
                                </div>
                                <div>
                                    <label class="block text-slate-400 text-xs mb-1.5">No. HP</label>
                                    <input v-model="bookForm.konsumen_no_hp" type="text" placeholder="08xxxxxxxxxx"
                                        class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                </div>
                            </div>
                        </div>

                        <!-- Booking Detail -->
                        <div class="space-y-3">
                            <h4 class="text-slate-300 text-sm font-medium border-b border-slate-800 pb-2">Detail Booking</h4>
                            <div>
                                <label class="block text-slate-400 text-xs mb-1.5">Booking Fee / UTJ <span class="text-rose-400">*</span></label>
                                <input v-model="bookForm.booking_fee" type="number" min="0" placeholder="0"
                                    class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"
                                    :class="{ 'border-rose-500': bookForm.errors.booking_fee }" />
                                <p v-if="bookForm.errors.booking_fee" class="text-rose-400 text-xs mt-1">{{ bookForm.errors.booking_fee }}</p>
                            </div>
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
                            <!-- Cicilan Kali (jika Cash Bertahap) -->
                            <div v-if="bookForm.cara_bayar === 'cash_bertahap'">
                                <label class="block text-slate-400 text-xs mb-1.5">Jumlah Cicilan (x)</label>
                                <input v-model="bookForm.cicilan_kali" type="number" min="1" placeholder="misal: 12"
                                    class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                            </div>
                        </div>

                        <!-- Skema DP -->
                        <div class="space-y-3">
                            <h4 class="text-slate-300 text-sm font-medium border-b border-slate-800 pb-2">Skema DP</h4>
                            <div class="flex gap-3 flex-wrap">
                                <label v-for="opt in skemaOptions" :key="opt.value" class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" v-model="bookForm.skema_dp" :value="opt.value" class="accent-violet-500" />
                                    <span class="text-slate-300 text-sm">{{ opt.label }}</span>
                                </label>
                            </div>
                            <div v-if="bookForm.skema_dp === 'nominal'">
                                <label class="block text-slate-400 text-xs mb-1.5">Jumlah DP (Rp)</label>
                                <input v-model="bookForm.skema_dp_nominal" type="number" min="0" placeholder="0"
                                    class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                            </div>
                            <div v-if="bookForm.skema_dp === 'persen'" class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-slate-400 text-xs mb-1.5">Persentase DP (%)</label>
                                    <input v-model="bookForm.skema_dp_persen" type="number" min="0" max="100" placeholder="10"
                                        class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                </div>
                                <div>
                                    <label class="block text-slate-400 text-xs mb-1.5">Frekuensi (x)</label>
                                    <input v-model="bookForm.skema_dp_kali" type="number" min="1" placeholder="3"
                                        class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                </div>
                            </div>
                        </div>

                        <!-- Plafon KPR -->
                        <div v-if="['kpr_subsidi','kpr_komersil'].includes(bookForm.cara_bayar)">
                            <label class="block text-slate-400 text-xs mb-1.5">Plafon KPR (Rp)</label>
                            <input v-model="bookForm.plafon_kpr" type="number" min="0" placeholder="0"
                                class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                        </div>

                        <!-- Komponen Subsidi (read-only, dari pengaturan proyek) -->
                        <div v-if="bookForm.cara_bayar === 'kpr_subsidi' && subsidyComponents.length > 0">
                            <h4 class="text-slate-300 text-sm font-medium border-b border-slate-800 pb-2 mb-3">Komponen KPR Subsidi</h4>
                            <div class="space-y-2">
                                <div v-for="comp in subsidyComponents" :key="comp.key" class="flex justify-between text-sm">
                                    <span class="text-slate-400">{{ comp.label }}</span>
                                    <span class="text-slate-200 font-medium">{{ formatRupiah(comp.jumlah) }}</span>
                                </div>
                            </div>
                            <p class="text-slate-600 text-xs mt-2">* Komponen diatur oleh admin di Pengaturan → Pembiayaan Proyek</p>
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
