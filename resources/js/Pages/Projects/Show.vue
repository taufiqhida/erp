<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InlineSiteplanSvg from '@/Components/InlineSiteplanSvg.vue';
import CsvImportModal from '@/Components/CsvImportModal.vue';
import KavlingSearchSelect from '@/Components/KavlingSearchSelect.vue';
import { Head, Link, useForm, usePage, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

const props = defineProps({
    project:   Object,
    kavlings:  Array,
    konsumens: Array,
});

// ── Can admin edit? ──────────────────────────────────────────────────────
const page    = usePage();
const isAdmin = computed(() =>
    page.props.auth?.user?.roles?.some(r => ['superadmin','manajer'].includes(r))
);

// ── View mode ────────────────────────────────────────────────────────────────
const viewMode = ref('siteplan'); // 'siteplan' | 'table'

// ── Multi-filter (kluster/blok/tipe/status jual/status bangun) ───────────────
const filters = ref({ kluster: '', blok: '', tipe_unit: '', status_jual: '', status_bangun: '' });

const uniqueOptions = (key) => {
    const values = (props.kavlings ?? []).map(k => k[key]).filter(v => v !== null && v !== undefined && v !== '');
    return [...new Set(values)].sort();
};
const klusterOptions   = computed(() => uniqueOptions('kluster'));
const blokOptions      = computed(() => uniqueOptions('blok'));
const tipeUnitOptions  = computed(() => uniqueOptions('tipe_unit'));

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

const canUpdateStatusBangun = computed(() =>
    page.props.auth?.user?.permissions?.includes('update status bangun')
);

// Siteplan selalu tampilkan semua unit (tidak ikut filter Table view, karena
// filter bar memang tidak ditampilkan di mode Siteplan).
const kavlingsWithKoordinat = computed(() =>
    (props.kavlings ?? []).filter(k => k.koordinat_x != null && k.koordinat_y != null)
);

// ── Status config ────────────────────────────────────────────────────────────
const statusConfig = {
    available:              { label: 'Tersedia',       bg: 'bg-emerald-500', text: 'text-emerald-400', badge: 'bg-emerald-500/15 text-emerald-400 ring-1 ring-emerald-500/30', dot: 'bg-emerald-400', siteplan: 'bg-emerald-500/20 border-2 border-emerald-500/60' },
    hold:                   { label: 'Tidak Tersedia', bg: 'bg-yellow-500',  text: 'text-yellow-400',  badge: 'bg-yellow-500/15 text-yellow-400 ring-1 ring-yellow-500/30',   dot: 'bg-yellow-400',  siteplan: 'bg-yellow-500/20 border-2 border-yellow-500/60' },
    booked:                 { label: 'Dipesan',        bg: 'bg-blue-500',    text: 'text-blue-400',    badge: 'bg-blue-500/15 text-blue-400 ring-1 ring-blue-500/30',         dot: 'bg-blue-400',    siteplan: 'bg-blue-500/20 border-2 border-blue-500/50 opacity-80' },
    sold:                   { label: 'Terjual',        bg: 'bg-rose-500',    text: 'text-rose-400',    badge: 'bg-rose-500/15 text-rose-400 ring-1 ring-rose-500/30',         dot: 'bg-rose-400',    siteplan: 'bg-rose-500/20 border-2 border-rose-500/50 opacity-80' },
    cancellation_requested: { label: 'Pembatalan',     bg: 'bg-orange-500',  text: 'text-orange-400',  badge: 'bg-orange-500/15 text-orange-400 ring-1 ring-orange-500/30',   dot: 'bg-orange-400',  siteplan: 'bg-orange-500/20 border-2 border-orange-500/50 opacity-80' },
};

// Warna ring/border marker siteplan berdasar status_bangun (dikombinasikan
// dengan warna isi/fill dari status_jual di atas)
const statusBangunColorHex = {
    not_started:    '#64748b',
    foundation:     '#f97316',
    structure:      '#3b82f6',
    roofing:        '#6366f1',
    finishing:      '#a855f7',
    handover_ready: '#10b981',
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

// ── Selected kavling (dipakai untuk modal Detail, dibuka dari Tabel) ─────────
const selectedKavling  = ref(null);
const showDetailModal  = ref(false);
const showAddModal     = ref(false);
const showEditModal    = ref(false);

const selectKavling = (kavling) => {
    selectedKavling.value = kavling;
    showDetailModal.value = true;
};

const closeDetail = () => {
    showDetailModal.value = false;
};

const isClickable = (k) => k.status_jual === 'available';

// ── Forms ────────────────────────────────────────────────────────────────
const kavlingForm = useForm({
    kluster: '', blok: '', nomor_kavling: '', tipe_unit: '', luas_tanah: '', luas_bangunan: '',
    harga: '', keterangan: '', status_jual: 'available', status_bangun: 'not_started', catatan: '',
});

// ── Edit Kavling (info umum saja — status_jual & konsumen bukan bagian ini) ──
const kavlingEditForm = useForm({
    kluster: '', blok: '', nomor_kavling: '', tipe_unit: '', luas_tanah: '', luas_bangunan: '',
    harga: '', keterangan: '', catatan: '',
});

const openEditKavling = (k) => {
    kavlingEditForm.clearErrors();
    kavlingEditForm.kluster = k.kluster ?? '';
    kavlingEditForm.blok = k.blok ?? '';
    kavlingEditForm.nomor_kavling = k.nomor_kavling ?? '';
    kavlingEditForm.tipe_unit = k.tipe_unit ?? '';
    kavlingEditForm.luas_tanah = k.luas_tanah ?? '';
    kavlingEditForm.luas_bangunan = k.luas_bangunan ?? '';
    kavlingEditForm.harga = k.harga ?? '';
    kavlingEditForm.keterangan = k.keterangan ?? '';
    kavlingEditForm.catatan = k.catatan ?? '';
    selectedKavling.value = k;
    showEditModal.value = true;
};

const submitEditKavling = () => {
    kavlingEditForm.put(route('kavlings.update', selectedKavling.value.id), {
        onSuccess: () => { showEditModal.value = false; },
    });
};

// ── Toggle ketersediaan (Tersedia / Tidak Tersedia) ──────────────────────
const statusJualForms = ref({});
const toggleStatusJual = (k) => {
    const next = k.status_jual === 'available' ? 'hold' : 'available';
    if (!statusJualForms.value[k.id]) {
        statusJualForms.value[k.id] = useForm({ status_jual: next });
    }
    const form = statusJualForms.value[k.id];
    form.status_jual = next;
    form.patch(route('kavlings.status-jual', k.id), { preserveScroll: true });
};

// ── Update status bangun langsung dari tabel (tanpa buka modal) ─────────
const statusBangunInlineForms = ref({});
const updateStatusBangunInline = (k, value) => {
    if (!statusBangunInlineForms.value[k.id]) {
        statusBangunInlineForms.value[k.id] = useForm({ status_bangun: value, catatan: k.catatan ?? '' });
    }
    const form = statusBangunInlineForms.value[k.id];
    form.status_bangun = value;
    form.patch(route('kavlings.status-bangun', k.id), { preserveScroll: true });
};

const statusBangunForm = useForm({
    status_bangun: 'not_started',
    catatan: '',
});

// Gunakan ref lokal agar binding select selalu sinkron
const localStatusBangun = ref('not_started');

// Watch selectedKavling: setiap ganti kavling, update dropdown
watch(selectedKavling, (kavling) => {
    if (kavling) {
        localStatusBangun.value = kavling.status_bangun ?? 'not_started';
        statusBangunForm.status_bangun = kavling.status_bangun ?? 'not_started';
        statusBangunForm.catatan = kavling.catatan ?? '';
    }
}, { immediate: true });

const openStatusBangun = (kavling) => {
    localStatusBangun.value = kavling.status_bangun ?? 'not_started';
    statusBangunForm.status_bangun = kavling.status_bangun ?? 'not_started';
    statusBangunForm.catatan = kavling.catatan ?? '';
    selectedKavling.value = kavling;
};

const submitStatusBangun = () => {
    // Sync nilai dari localStatusBangun sebelum submit
    statusBangunForm.status_bangun = localStatusBangun.value;
    statusBangunForm.patch(route('kavlings.status-bangun', selectedKavling.value.id), {
        onSuccess: () => {
            // Jangan reset agar dropdown tidak kembali kosong
        }
    });
};

// ── Upload Gambar ───────────────────────────────────────────────────────────────
const uploadForm = useForm({ tipe: '', gambar: null });
const fotoPreview = ref(null);
const denahPreview = ref(null);

const handleFileChange = (e, tipe) => {
    const file = e.target.files[0];
    if (!file) return;
    uploadForm.tipe   = tipe;
    uploadForm.gambar = file;
    const reader = new FileReader();
    reader.onload = (ev) => {
        if (tipe === 'foto_rumah') fotoPreview.value = ev.target.result;
        else denahPreview.value = ev.target.result;
    };
    reader.readAsDataURL(file);
};

const submitUploadGambar = (tipe) => {
    uploadForm.tipe = tipe;
    uploadForm.post(route('kavlings.upload-gambar', selectedKavling.value.id), {
        forceFormData: true,
        onSuccess: () => {
            uploadForm.reset();
            if (tipe === 'foto_rumah') fotoPreview.value = null;
            else denahPreview.value = null;
        },
    });
};

const submitAddKavling = () => {
    kavlingForm.post(route('projects.kavlings.store', props.project.id), {
        onSuccess: () => { showAddModal.value = false; kavlingForm.reset(); }
    });
};

// ── Helpers ──────────────────────────────────────────────────────────────────
const formatRupiah = (n) => n ? 'Rp ' + new Intl.NumberFormat('id-ID').format(n) : '-';

// Status bangun options (untuk dropdown)
const statusBangunOptions = [
    { value: 'not_started',    label: 'Belum Mulai' },
    { value: 'foundation',     label: 'Pondasi' },
    { value: 'structure',      label: 'Struktur' },
    { value: 'roofing',        label: 'Atap' },
    { value: 'finishing',      label: 'Finishing' },
    { value: 'handover_ready', label: 'Siap Serah Terima' },
];

// ── Siteplan koordinat edit mode ────────────────────────────────────────
// Assign posisi: admin pilih kavling dari dropdown, lalu klik posisinya di
// gambar siteplan (tidak lagi lewat klik card per-unit).
const editingKoordinat   = ref(false);
const pendingKoordinats  = ref({});
const positioningKavlingId = ref(null);

const startEditingKoordinat = () => {
    editingKoordinat.value = true;
    positioningKavlingId.value = null;
};

const cancelEditingKoordinat = () => {
    editingKoordinat.value = false;
    positioningKavlingId.value = null;
    pendingKoordinats.value = {};
};

const onSiteplanClick = (e) => {
    if (!editingKoordinat.value || !positioningKavlingId.value) return;
    const rect = e.currentTarget.getBoundingClientRect();
    const x = ((e.clientX - rect.left) / rect.width * 100).toFixed(2);
    const y = ((e.clientY - rect.top)  / rect.height * 100).toFixed(2);
    pendingKoordinats.value[positioningKavlingId.value] = { x, y };
    // Update visual instantly
    const k = props.kavlings.find(k => k.id === positioningKavlingId.value);
    if (k) { k.koordinat_x = x; k.koordinat_y = y; }
};

const onMarkerClick = (k) => {
    if (editingKoordinat.value) {
        positioningKavlingId.value = k.id;
    } else {
        selectKavling(k);
    }
};

// Ukuran marker siteplan (global per-proyek, tidak per-unit) ───────────────
const markerSize = ref(props.project.siteplan_marker_size ?? 28); // px
let markerSizeSaveTimer = null;
const saveMarkerSize = (value) => {
    clearTimeout(markerSizeSaveTimer);
    markerSizeSaveTimer = setTimeout(() => {
        useForm({ siteplan_marker_size: value }).patch(route('projects.siteplan-marker-size', props.project.id), {
            preserveScroll: true,
            preserveState: true,
        });
    }, 400);
};
watch(markerSize, saveMarkerSize);

const saveAllKoordinat = () => {
    const kavlingsPayload = Object.entries(pendingKoordinats.value).map(([id, pos]) => ({
        id: Number(id),
        koordinat_x: pos.x,
        koordinat_y: pos.y,
    }));
    const koordinatForm = useForm({ kavlings: kavlingsPayload });
    koordinatForm.patch(route('projects.kavling-koordinat', props.project.id), {
        onSuccess: () => {
            editingKoordinat.value = false;
            positioningKavlingId.value = null;
            pendingKoordinats.value = {};
        }
    });
};

// ── Import Excel ────────────────────────────────────────────────────────
const importForm  = useForm({ file: null });
const showImport  = ref(false);
const onImportFile = (e) => { importForm.file = e.target.files[0]; };
const submitImport = () => {
    importForm.post(route('projects.import-kavling', props.project.id), {
        forceFormData: true,
        onSuccess: () => { showImport.value = false; importForm.reset(); }
    });
};

// ── Import CSV dengan preview & mapping kolom ────────────────────────
const showCsvImport = ref(false);
const onCsvImported = () => {
    router.reload({ only: ['kavlings', 'project'] });
};

// ── Upload / ganti gambar Siteplan ───────────────────────────────────────
const showUploadSiteplan = ref(false);
const siteplanUploadForm = useForm({ siteplan_image: null });
const siteplanPreview = ref(null);

const onSiteplanFileChange = (e) => {
    const file = e.target.files[0];
    if (!file) return;
    siteplanUploadForm.siteplan_image = file;
    siteplanPreview.value = URL.createObjectURL(file);
};

const submitUploadSiteplan = () => {
    siteplanUploadForm.post(route('projects.siteplan.upload', props.project.id), {
        forceFormData: true,
        onSuccess: () => {
            showUploadSiteplan.value = false;
            siteplanUploadForm.reset();
            siteplanPreview.value = null;
        },
    });
};

</script>

<template>
    <Head :title="project.nama" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2 text-slate-400 text-sm">
                <Link :href="route('beranda')" class="hover:text-slate-200 transition-colors">Proyek</Link>
                <span>/</span>
                <span class="text-slate-200 font-medium">{{ project.nama }}</span>
            </div>
        </template>

        <div class="p-6 space-y-5">

            <!-- ── Project Header ───────────────────────────────────────── -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5">
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-violet-500 to-indigo-600 flex items-center justify-center shadow-lg shadow-violet-500/20 flex-shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-6 h-6 text-white">
                                <path d="M11.47 3.84a.75.75 0 011.06 0l8.69 8.69a.75.75 0 101.06-1.06l-8.689-8.69a2.25 2.25 0 00-3.182 0l-8.69 8.69a.75.75 0 001.061 1.06l8.69-8.69z"/>
                                <path d="M12 5.432l8.159 8.159c.03.03.06.058.091.086v6.198c0 1.035-.84 1.875-1.875 1.875H15a.75.75 0 01-.75-.75v-4.5a.75.75 0 00-.75-.75h-3a.75.75 0 00-.75.75V21a.75.75 0 01-.75.75H5.625a1.875 1.875 0 01-1.875-1.875v-6.198a2.29 2.29 0 00.091-.086L12 5.43z"/>
                            </svg>
                        </div>
                        <div>
                            <div class="flex items-center gap-2 flex-wrap">
                                <h1 class="text-white font-bold text-xl">{{ project.nama }}</h1>
                                <span :class="project.is_active ? 'bg-emerald-500/15 text-emerald-400' : 'bg-slate-700 text-slate-400'" class="px-2 py-0.5 rounded-full text-xs font-medium">
                                    {{ project.is_active ? 'Aktif' : 'Non-aktif' }}
                                </span>
                            </div>
                            <div class="text-slate-400 text-sm mt-0.5">{{ project.kode }} · {{ project.kota }}</div>
                            <div class="text-slate-500 text-xs mt-0.5">{{ project.lokasi }}</div>
                        </div>
                    </div>
                    <div class="flex gap-2 flex-wrap">
                        <!-- Import Excel (cepat, tanpa preview) -->
                        <button v-if="isAdmin" @click="showImport = true"
                            class="inline-flex items-center gap-1.5 px-3 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs rounded-lg transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5 text-emerald-400">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                            </svg>
                            Import Excel
                        </button>
                        <!-- Import CSV dengan preview & mapping kolom -->
                        <button v-if="isAdmin" @click="showCsvImport = true"
                            class="inline-flex items-center gap-1.5 px-3 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs rounded-lg transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5 text-violet-400">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            Import CSV (Preview)
                        </button>
                        <Link :href="route('projects.edit', project.id)"
                              class="inline-flex items-center gap-1.5 px-3 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs rounded-lg transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                            Edit Proyek
                        </Link>
                    </div>
                </div>

                <!-- Stats -->
                <div class="grid grid-cols-2 sm:grid-cols-6 gap-3 mt-5">
                    <div class="bg-slate-800/50 rounded-xl p-3 text-center">
                        <div class="text-white font-bold text-2xl">{{ project.kavlings_count }}</div>
                        <div class="text-slate-400 text-xs mt-0.5">Total Unit</div>
                    </div>
                    <div class="bg-slate-800/50 rounded-xl p-3 text-center">
                        <div class="text-slate-300 font-bold text-lg leading-tight">{{ project.luas_tanah_total ? (Number(project.luas_tanah_total).toLocaleString('id-ID') + ' m²') : '-' }}</div>
                        <div class="text-slate-400 text-xs mt-0.5">Luas Tanah</div>
                    </div>
                    <div class="bg-emerald-500/10 rounded-xl p-3 text-center">
                        <div class="text-emerald-400 font-bold text-2xl">{{ project.kavlings_available }}</div>
                        <div class="text-slate-400 text-xs mt-0.5">Tersedia</div>
                    </div>
                    <div class="bg-yellow-500/10 rounded-xl p-3 text-center">
                        <div class="text-yellow-400 font-bold text-2xl">{{ project.kavlings_hold }}</div>
                        <div class="text-slate-400 text-xs mt-0.5">Hold</div>
                    </div>
                    <div class="bg-blue-500/10 rounded-xl p-3 text-center">
                        <div class="text-blue-400 font-bold text-2xl">{{ project.kavlings_booked }}</div>
                        <div class="text-slate-400 text-xs mt-0.5">Dipesan</div>
                    </div>
                    <div class="bg-rose-500/10 rounded-xl p-3 text-center">
                        <div class="text-rose-400 font-bold text-2xl">{{ project.kavlings_sold }}</div>
                        <div class="text-slate-400 text-xs mt-0.5">Terjual</div>
                    </div>
                </div>

                <!-- Progress -->
                <div class="mt-4">
                    <div class="flex justify-between text-xs text-slate-400 mb-1.5">
                        <span>Progress Penjualan</span>
                        <span class="text-violet-400 font-semibold">{{ project.progress }}%</span>
                    </div>
                    <div class="h-2 bg-slate-800 rounded-full overflow-hidden">
                        <div class="h-full bg-gradient-to-r from-violet-500 to-indigo-500 rounded-full transition-all duration-1000" :style="{ width: project.progress + '%' }"/>
                    </div>
                </div>
            </div>

            <!-- ── View Toggle + Buttons ─────────────────────────── -->
            <div class="flex items-center justify-between flex-wrap gap-3">
                <div class="flex items-center gap-1 bg-slate-900 border border-slate-800 rounded-lg p-1">
                    <button @click="viewMode = 'siteplan'"
                            :class="viewMode === 'siteplan' ? 'bg-violet-600 text-white shadow' : 'text-slate-400 hover:text-slate-200'"
                            class="flex items-center gap-2 px-3 py-1.5 rounded-md text-xs font-medium transition-all">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z"/></svg>
                        Siteplan
                    </button>
                    <button @click="viewMode = 'table'"
                            :class="viewMode === 'table' ? 'bg-violet-600 text-white shadow' : 'text-slate-400 hover:text-slate-200'"
                            class="flex items-center gap-2 px-3 py-1.5 rounded-md text-xs font-medium transition-all">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 01-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-7.5A1.125 1.125 0 0112 19.5m9.75-9.75c0 .621-.504 1.125-1.125 1.125H12m8.625-9H12"/></svg>
                        Tabel
                    </button>
                </div>
                <!-- Admin action buttons -->
                <div class="flex items-center gap-2 flex-wrap">
                    <!-- Upload/ganti siteplan (admin only) -->
                    <button v-if="isAdmin && viewMode === 'siteplan'" @click="showUploadSiteplan = true"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs rounded-lg transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498l4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 00-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0z" />
                        </svg>
                        {{ project.siteplan_image ? 'Ganti Siteplan' : 'Upload Siteplan' }}
                    </button>
                    <!-- Edit koordinat mode (admin only) -->
                    <template v-if="isAdmin && viewMode === 'siteplan' && project.siteplan_image && !isSvgSiteplan">
                        <div v-if="editingKoordinat" class="flex items-center gap-2">
                            <button @click="saveAllKoordinat"
                                class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 text-white text-xs rounded-lg font-medium transition-colors">
                                Simpan Posisi
                            </button>
                            <button @click="cancelEditingKoordinat"
                                class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 text-slate-300 text-xs rounded-lg transition-colors">
                                Batal
                            </button>
                        </div>
                        <button v-else @click="startEditingKoordinat"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-amber-500/10 hover:bg-amber-500/20 border border-amber-500/20 text-amber-400 text-xs rounded-lg transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                            </svg>
                            Atur Posisi Unit
                        </button>
                    </template>
                    <Link :href="route('projects.kavlings.index', project.id)"
                            class="inline-flex items-center gap-1.5 px-3 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs rounded-lg transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
                        Semua Unit
                    </Link>
                    <button v-if="isAdmin" @click="showAddModal = true"
                            class="inline-flex items-center gap-2 px-3 py-2 bg-violet-600/20 hover:bg-violet-600/30 text-violet-300 text-xs font-medium rounded-lg transition-colors border border-violet-500/20">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3.5 h-3.5"><path d="M10.75 4.75a.75.75 0 00-1.5 0v4.5h-4.5a.75.75 0 000 1.5h4.5v4.5a.75.75 0 001.5 0v-4.5h4.5a.75.75 0 000-1.5h-4.5v-4.5z"/></svg>
                        Tambah Kavling
                    </button>
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
                        <option v-for="opt in statusBangunOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                    </select>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3.5 h-3.5 text-slate-500 absolute right-2 top-1/2 -translate-y-1/2 pointer-events-none"><path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 011.06 0L10 11.94l3.72-3.72a.75.75 0 111.06 1.06l-4.25 4.25a.75.75 0 01-1.06 0L5.22 9.28a.75.75 0 010-1.06z" clip-rule="evenodd"/></svg>
                </div>
                <button v-if="activeFilterCount > 0" @click="resetFilters"
                    class="px-2.5 py-1.5 text-slate-400 hover:text-slate-200 text-xs rounded-lg transition-colors">
                    ✕ Reset ({{ activeFilterCount }})
                </button>
                <span class="text-slate-500 text-xs ml-auto">{{ filteredKavlings.length }} / {{ (kavlings ?? []).length }} unit</span>
            </div>

            <!-- ═══════════════════════════════════════════════════════════════
                 SITEPLAN VIEW — visual status saja, detail & edit unit ada di Tabel
            ════════════════════════════════════════════════════════════════ -->
            <div v-if="viewMode === 'siteplan'" class="space-y-5">

                <!-- Legend -->
                <div class="flex flex-wrap items-center gap-4">
                    <div v-for="(cfg, key) in statusConfig" :key="key" class="flex items-center gap-2 text-xs text-slate-400">
                        <span :class="cfg.dot" class="w-3 h-3 rounded-sm inline-block"></span>
                        {{ cfg.label }}
                    </div>
                    <span class="text-slate-700">|</span>
                    <span class="text-slate-500 text-xs">Cincin warna = status pembangunan</span>
                    <div v-for="opt in statusBangunOptions" :key="opt.value" class="flex items-center gap-1.5 text-xs text-slate-500">
                        <span class="w-2.5 h-2.5 rounded-full inline-block ring-2" :style="`background:${statusBangunColorHex[opt.value]}30; box-shadow: 0 0 0 2px ${statusBangunColorHex[opt.value]}`"></span>
                        {{ opt.label }}
                    </div>
                    <div v-if="!isSvgSiteplan && project.siteplan_image" class="flex items-center gap-2 ml-auto">
                        <span class="text-slate-500 text-xs whitespace-nowrap">Ukuran titik</span>
                        <input type="range" v-model.number="markerSize" min="12" max="56" step="2" class="w-24 accent-violet-500" />
                        <span class="text-slate-500 text-xs w-8">{{ markerSize }}px</span>
                    </div>
                </div>

                <!-- Picker kavling saat mode "Atur Posisi Unit" aktif -->
                <div v-if="editingKoordinat" class="bg-slate-900 border border-amber-500/30 rounded-xl p-3 flex flex-wrap items-center gap-3">
                    <span class="text-amber-400 text-xs font-medium flex-shrink-0">📍 Cari & pilih unit, lalu klik posisinya di gambar:</span>
                    <div class="flex-1 min-w-[220px]">
                        <KavlingSearchSelect
                            v-model="positioningKavlingId"
                            :kavlings="kavlings"
                            placeholder="-- pilih kavling --"
                            :option-hint="k => [k.blok ? `Blok ${k.blok}` : null, k.tipe_unit, k.koordinat_x != null ? 'sudah diposisikan' : null].filter(Boolean).join(' · ')"
                        />
                    </div>
                </div>

                <!-- Peta Siteplan SVG (ID-matching, warna otomatis) -->
                <div v-if="project.siteplan_image && isSvgSiteplan" class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden p-3">
                    <InlineSiteplanSvg
                        :siteplan-url="project.siteplan_image"
                        :kavlings="kavlings"
                        :status-colors="statusColorHex"
                        :status-bangun-colors="statusBangunColorHex"
                        :interactive="true"
                        @select="selectKavling"
                    />
                </div>

                <!-- Peta Siteplan gambar raster -->
                <div v-else-if="project.siteplan_image" class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden">
                    <div
                        class="relative select-none"
                        :class="editingKoordinat ? 'cursor-crosshair' : ''"
                        style="min-height: 300px;"
                        @click="onSiteplanClick"
                    >
                        <img :src="project.siteplan_image" class="w-full object-contain pointer-events-none" alt="Siteplan" />
                        <template v-for="k in kavlingsWithKoordinat" :key="k.id">
                            <div
                                @click.stop="onMarkerClick(k)"
                                class="group absolute transform -translate-x-1/2 -translate-y-1/2 z-0 hover:z-20"
                                :style="`left: ${k.koordinat_x}%; top: ${k.koordinat_y}%;`"
                            >
                                <!-- Lingkaran marker -->
                                <div
                                    :class="[
                                        'rounded-full flex items-center justify-center transition-all duration-150 cursor-pointer',
                                        statusConfig[k.status_jual]?.siteplan ?? 'bg-gray-500/40 border-2 border-gray-500',
                                        positioningKavlingId === k.id ? 'ring-2 ring-white' : ''
                                    ]"
                                    :style="`width: ${markerSize}px; height: ${markerSize}px; box-shadow: 0 0 0 2px ${statusBangunColorHex[k.status_bangun] ?? '#64748b'};`"
                                >
                                    <span class="text-white font-bold drop-shadow leading-none select-none" :style="`font-size: ${Math.max(8, Math.round(markerSize / 2.6))}px;`">{{ k.nomor_kavling }}</span>
                                </div>

                                <!-- Hover card: identitas + status jual/bangun -->
                                <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-1.5 w-max max-w-[180px] opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-opacity duration-100 pointer-events-none">
                                    <div class="bg-slate-950 border border-slate-700 rounded-lg px-2.5 py-1.5 shadow-xl text-left">
                                        <div class="text-white text-xs font-semibold">{{ k.nomor_lengkap }}</div>
                                        <div class="flex items-center gap-1 mt-0.5">
                                            <span :class="statusConfig[k.status_jual]?.dot" class="w-1.5 h-1.5 rounded-full inline-block"></span>
                                            <span class="text-slate-300 text-[11px]">{{ statusConfig[k.status_jual]?.label }}</span>
                                        </div>
                                        <div class="flex items-center gap-1 mt-0.5">
                                            <span class="w-1.5 h-1.5 rounded-full inline-block" :style="`background:${statusBangunColorHex[k.status_bangun]}`"></span>
                                            <span class="text-slate-400 text-[11px]">{{ k.status_bangun_label }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <div v-if="!kavlings?.length" class="bg-slate-900 border border-dashed border-slate-700 rounded-2xl p-16 text-center text-slate-500">
                    Belum ada kavling. Klik "Tambah Kavling" untuk mulai.
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════════════
                 TABLE VIEW
            ════════════════════════════════════════════════════════════════ -->
            <div v-else class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-800">
                                <th class="text-left px-5 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Kluster</th>
                                <th class="text-left px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Kavling</th>
                                <th class="text-left px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Tipe</th>
                                <th class="text-left px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Luas</th>
                                <th class="text-left px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Harga</th>
                                <th class="text-left px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Status Jual</th>
                                <th class="text-left px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Pembangunan</th>
                                <th class="text-left px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Konsumen</th>
                                <th class="text-right px-5 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60">
                            <tr v-if="!filteredKavlings.length">
                                <td colspan="9" class="text-center py-12 text-slate-500">Tidak ada kavling yang cocok dengan filter.</td>
                            </tr>
                            <tr v-for="k in filteredKavlings" :key="k.id" class="hover:bg-slate-800/20 transition-colors">
                                <td class="px-5 py-3.5 text-slate-400 text-xs">{{ k.kluster ?? '-' }}</td>
                                <td class="px-4 py-3.5 text-slate-200 font-medium">{{ k.nomor_lengkap }}</td>
                                <td class="px-4 py-3.5 text-slate-400 text-xs">{{ k.tipe_unit ?? '-' }}</td>
                                <td class="px-4 py-3.5 text-slate-400 text-xs">
                                    <div v-if="k.luas_tanah">T: {{ k.luas_tanah }} m²</div>
                                    <div v-if="k.luas_bangunan" class="text-slate-500">B: {{ k.luas_bangunan }} m²</div>
                                </td>
                                <td class="px-4 py-3.5 text-slate-300 text-xs font-mono">{{ formatRupiah(k.harga) }}</td>
                                <td class="px-4 py-3.5">
                                    <button v-if="isAdmin && ['available', 'hold'].includes(k.status_jual)"
                                        @click="toggleStatusJual(k)"
                                        :title="`Klik untuk ubah jadi ${k.status_jual === 'available' ? 'Tidak Tersedia' : 'Tersedia'}`"
                                        :class="statusConfig[k.status_jual]?.badge" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium hover:opacity-75 transition-opacity cursor-pointer">
                                        {{ statusConfig[k.status_jual]?.label ?? k.status_jual }}
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3 h-3"><path fill-rule="evenodd" d="M4 10a.75.75 0 01.75-.75h10.638L11.29 5.29a.75.75 0 111.06-1.06l5.5 5.5a.75.75 0 010 1.06l-5.5 5.5a.75.75 0 11-1.06-1.06l4.098-4.098H4.75A.75.75 0 014 10z" clip-rule="evenodd"/></svg>
                                    </button>
                                    <span v-else :class="statusConfig[k.status_jual]?.badge" class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium">
                                        {{ statusConfig[k.status_jual]?.label ?? k.status_jual }}
                                    </span>
                                </td>
                                <td class="px-4 py-3.5">
                                    <div class="flex items-center gap-2">
                                        <div class="w-12 h-1 bg-slate-800 rounded-full overflow-hidden flex-shrink-0">
                                            <div class="h-full bg-gradient-to-r from-violet-500 to-indigo-500 rounded-full" :style="{ width: k.progress_bangun + '%' }"/>
                                        </div>
                                        <select v-if="canUpdateStatusBangun"
                                            :value="k.status_bangun"
                                            @change="updateStatusBangunInline(k, $event.target.value)"
                                            class="px-2 py-1 bg-slate-800 border border-slate-700 rounded-md text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500 cursor-pointer">
                                            <option v-for="opt in statusBangunOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                        </select>
                                        <span v-else class="text-slate-400 text-xs">{{ k.status_bangun_label }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3.5 text-slate-400 text-xs">{{ k.konsumen_nama ?? '-' }}</td>
                                <td class="px-5 py-3.5 text-right">
                                    <div class="inline-flex items-center gap-1.5">
                                        <button @click="selectKavling(k)"
                                                class="inline-flex items-center gap-1.5 px-2.5 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs rounded-lg transition-colors">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3.5 h-3.5"><path d="M10 12.5a2.5 2.5 0 100-5 2.5 2.5 0 000 5z"/><path fill-rule="evenodd" d="M.664 10.59a1.651 1.651 0 010-1.186A10.004 10.004 0 0110 3c4.257 0 7.893 2.66 9.336 6.41.147.381.146.804 0 1.186A10.004 10.004 0 0110 17c-4.257 0-7.893-2.66-9.336-6.41zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"/></svg>
                                            Detail
                                        </button>
                                        <button v-if="isAdmin" @click="openEditKavling(k)"
                                                class="inline-flex items-center gap-1.5 px-2.5 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs rounded-lg transition-colors">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                                            Edit
                                        </button>
                                    </div>
                                </td>

                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ═══ MODAL: DETAIL KAVLING ════════════════════════════════════════ -->
        <Teleport to="body">
            <div v-if="showDetailModal && selectedKavling" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/80 backdrop-blur-sm" @click="closeDetail" />
                <div class="relative bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-2xl shadow-2xl overflow-hidden max-h-[90vh] overflow-y-auto">

                    <!-- Header bar warna status -->
                    <div :class="statusConfig[selectedKavling.status_jual]?.bg ?? 'bg-slate-700'" class="h-1.5 w-full"/>

                    <!-- Header -->
                    <div class="flex items-start justify-between p-5 border-b border-slate-800">
                        <div class="flex items-center gap-4">
                            <!-- Ikon kavling besar -->
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
                                <div class="text-slate-400 text-sm mt-0.5">{{ project.nama }} · {{ project.kode }}</div>
                            </div>
                        </div>
                        <button @click="closeDetail" class="text-slate-500 hover:text-slate-300 transition-colors p-1 flex-shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-6 h-6"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/></svg>
                        </button>
                    </div>

                    <!-- Body -->
                    <div class="p-5 space-y-5">

                        <!-- Info Grid 2 kolom -->
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                            <div class="bg-slate-800/60 rounded-xl p-3 text-center">
                                <div class="text-slate-500 text-xs mb-1">Kluster</div>
                                <div class="text-white font-bold text-lg">{{ selectedKavling.kluster ?? '-' }}</div>
                                <div class="text-slate-500 text-xs">Kluster</div>
                            </div>
                            <div class="bg-slate-800/60 rounded-xl p-3 text-center">
                                <div class="text-slate-500 text-xs mb-1">Blok</div>
                                <div class="text-white font-bold text-lg">{{ selectedKavling.blok ?? '-' }}</div>
                                <div class="text-slate-500 text-xs">Blok</div>
                            </div>
                            <div class="bg-slate-800/60 rounded-xl p-3 text-center">
                                <div class="text-slate-500 text-xs mb-1">Nomor</div>
                                <div class="text-white font-bold text-lg">{{ selectedKavling.nomor_kavling }}</div>
                                <div class="text-slate-500 text-xs">Unit</div>
                            </div>
                            <div class="bg-slate-800/60 rounded-xl p-3 text-center">
                                <div class="text-slate-500 text-xs mb-1">Tipe</div>
                                <div class="text-white font-bold text-lg">{{ selectedKavling.tipe_unit ?? '-' }}</div>
                                <div class="text-slate-500 text-xs">Tipe Unit</div>
                            </div>
                            <div class="bg-slate-800/60 rounded-xl p-3 text-center">
                                <div class="text-slate-500 text-xs mb-1">Luas Tanah</div>
                                <div class="text-white font-bold text-lg">{{ selectedKavling.luas_tanah ?? '-' }}</div>
                                <div class="text-slate-500 text-xs">m²</div>
                            </div>
                            <div class="bg-slate-800/60 rounded-xl p-3 text-center">
                                <div class="text-slate-500 text-xs mb-1">Luas Bangunan</div>
                                <div class="text-white font-bold text-lg">{{ selectedKavling.luas_bangunan ?? '-' }}</div>
                                <div class="text-slate-500 text-xs">m²</div>
                            </div>
                        </div>

                        <!-- Keterangan (jika ada) -->
                        <div v-if="selectedKavling.keterangan" class="bg-violet-500/10 border border-violet-500/20 rounded-xl p-3">
                            <div class="text-violet-400 text-xs font-medium mb-1">🏷️ Keterangan</div>
                            <div class="text-slate-300 text-sm">{{ selectedKavling.keterangan }}</div>
                        </div>

                        <!-- Harga -->
                        <div class="bg-gradient-to-r from-violet-500/10 to-indigo-500/10 border border-violet-500/20 rounded-xl p-4 flex items-center justify-between">
                            <div>
                                <div class="text-slate-400 text-xs font-medium">Harga</div>
                                <div class="text-violet-300 font-black text-2xl mt-0.5">{{ formatRupiah(selectedKavling.harga) }}</div>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-violet-500/20 flex items-center justify-center">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-violet-400"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z"/></svg>
                            </div>
                        </div>

                        <!-- Progress Pembangunan -->
                        <div class="bg-slate-800/50 rounded-xl p-4 space-y-3">
                            <div class="flex items-center justify-between">
                                <div class="text-slate-300 font-semibold text-sm">Progress Pembangunan</div>
                                <span :class="statusConfig[selectedKavling.status_bangun]?.badge ?? 'bg-slate-700/50 text-slate-400 ring-1 ring-slate-600'" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium">
                                    {{ selectedKavling.status_bangun_label }}
                                </span>
                            </div>
                            <div class="h-2.5 bg-slate-700 rounded-full overflow-hidden">
                                <div
                                    class="h-full bg-gradient-to-r from-violet-500 to-indigo-400 rounded-full transition-all duration-700"
                                    :style="{ width: selectedKavling.progress_bangun + '%' }"
                                />
                            </div>
                            <div class="flex justify-between text-xs text-slate-500">
                                <span>Belum Mulai</span>
                                <span class="text-violet-400 font-semibold">{{ selectedKavling.progress_bangun }}%</span>
                                <span>Serah Terima</span>
                            </div>
                        </div>

                        <!-- Konsumen (jika ada) -->
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
                        <div class="grid grid-cols-2 gap-3">
                            <!-- Foto Rumah -->
                            <div class="space-y-2">
                                <div class="text-slate-400 text-xs font-medium uppercase tracking-wider flex items-center gap-1.5">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3.5 h-3.5 text-violet-400"><path fill-rule="evenodd" d="M1 8a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 018.07 3h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0016.07 6H17a2 2 0 012 2v7a2 2 0 01-2 2H3a2 2 0 01-2-2V8zm13.5 3a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0zM10 14a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"/></svg>
                                    Foto Rumah
                                </div>
                                <div class="relative group rounded-xl overflow-hidden bg-slate-800 aspect-video">
                                    <img v-if="fotoPreview || selectedKavling.foto_rumah"
                                        :src="fotoPreview || selectedKavling.foto_rumah"
                                        class="w-full h-full object-cover" alt="Foto Rumah" />
                                    <div v-else class="w-full h-full flex flex-col items-center justify-center text-slate-600 gap-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-7 h-7"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" /></svg>
                                        <span class="text-xs">Belum ada foto</span>
                                    </div>
                                    <label class="absolute inset-0 bg-black/60 opacity-0 group-hover:opacity-100 flex flex-col items-center justify-center cursor-pointer transition-opacity gap-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5 text-white"><path d="M9.25 13.25a.75.75 0 001.5 0V4.636l2.955 3.129a.75.75 0 001.09-1.03l-4.25-4.5a.75.75 0 00-1.09 0l-4.25 4.5a.75.75 0 101.09 1.03L9.25 4.636v8.614z"/><path d="M3.5 12.75a.75.75 0 00-1.5 0v2.5A2.75 2.75 0 004.75 18h10.5A2.75 2.75 0 0018 15.25v-2.5a.75.75 0 00-1.5 0v2.5c0 .69-.56 1.25-1.25 1.25H4.75c-.69 0-1.25-.56-1.25-1.25v-2.5z"/></svg>
                                        <span class="text-white text-xs font-medium">Upload Foto</span>
                                        <input type="file" class="hidden" accept="image/jpeg,image/jpg,image/png,image/webp" @change="handleFileChange($event, 'foto_rumah')" />
                                    </label>
                                </div>
                                <button v-if="fotoPreview" @click="submitUploadGambar('foto_rumah')" :disabled="uploadForm.processing"
                                    class="w-full py-1.5 bg-violet-600/20 hover:bg-violet-600/30 text-violet-300 text-xs font-medium rounded-lg transition-colors border border-violet-500/20 disabled:opacity-50">
                                    {{ uploadForm.processing ? 'Mengupload...' : '✓ Simpan Foto' }}
                                </button>
                            </div>

                            <!-- Denah Rumah -->
                            <div class="space-y-2">
                                <div class="text-slate-400 text-xs font-medium uppercase tracking-wider flex items-center gap-1.5">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3.5 h-3.5 text-indigo-400"><path fill-rule="evenodd" d="M1 2.75A.75.75 0 011.75 2h16.5a.75.75 0 010 1.5H17v8.75A2.25 2.25 0 0114.75 14.5H14v2.25a.75.75 0 01-.75.75h-4.5a.75.75 0 01-.75-.75V14.5h-.25A2.25 2.25 0 016 12.25V3.5H1.75A.75.75 0 011 2.75zM7.5 3.5v8.75c0 .414.336.75.75.75h3.5a.75.75 0 00.75-.75V3.5h-5z" clip-rule="evenodd"/></svg>
                                    Denah Rumah
                                </div>
                                <div class="relative group rounded-xl overflow-hidden bg-slate-800 aspect-video">
                                    <img v-if="denahPreview || selectedKavling.denah_rumah"
                                        :src="denahPreview || selectedKavling.denah_rumah"
                                        class="w-full h-full object-contain bg-white" alt="Denah Rumah" />
                                    <div v-else class="w-full h-full flex flex-col items-center justify-center text-slate-600 gap-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-7 h-7"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498l4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 00-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0z" /></svg>
                                        <span class="text-xs">Belum ada denah</span>
                                    </div>
                                    <label class="absolute inset-0 bg-black/60 opacity-0 group-hover:opacity-100 flex flex-col items-center justify-center cursor-pointer transition-opacity gap-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5 text-white"><path d="M9.25 13.25a.75.75 0 001.5 0V4.636l2.955 3.129a.75.75 0 001.09-1.03l-4.25-4.5a.75.75 0 00-1.09 0l-4.25 4.5a.75.75 0 101.09 1.03L9.25 4.636v8.614z"/><path d="M3.5 12.75a.75.75 0 00-1.5 0v2.5A2.75 2.75 0 004.75 18h10.5A2.75 2.75 0 0018 15.25v-2.5a.75.75 0 00-1.5 0v2.5c0 .69-.56 1.25-1.25 1.25H4.75c-.69 0-1.25-.56-1.25-1.25v-2.5z"/></svg>
                                        <span class="text-white text-xs font-medium">Upload Denah</span>
                                        <input type="file" class="hidden" accept="image/jpeg,image/jpg,image/png,image/webp" @change="handleFileChange($event, 'denah_rumah')" />
                                    </label>
                                </div>
                                <button v-if="denahPreview" @click="submitUploadGambar('denah_rumah')" :disabled="uploadForm.processing"
                                    class="w-full py-1.5 bg-indigo-600/20 hover:bg-indigo-600/30 text-indigo-300 text-xs font-medium rounded-lg transition-colors border border-indigo-500/20 disabled:opacity-50">
                                    {{ uploadForm.processing ? 'Mengupload...' : '✓ Simpan Denah' }}
                                </button>
                            </div>
                        </div>

                        <div class="border-t border-slate-800"/>

                        <!-- Aksi: Update Status Bangun -->
                        <div class="space-y-2">
                            <div class="text-slate-400 text-xs font-medium uppercase tracking-wider">Update Progress Bangun</div>
                            <div class="flex gap-2">
                                <select
                                    v-model="localStatusBangun"
                                    class="flex-1 px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"
                                >
                                    <option v-for="opt in statusBangunOptions" :key="opt.value" :value="opt.value">
                                        {{ opt.label }}
                                    </option>
                                </select>
                                <button
                                    @click="submitStatusBangun"
                                    :disabled="statusBangunForm.processing"
                                    class="px-4 py-2.5 bg-slate-700 hover:bg-slate-600 disabled:opacity-50 text-slate-200 text-sm font-medium rounded-lg transition-colors whitespace-nowrap"
                                >
                                    {{ statusBangunForm.processing ? 'Menyimpan...' : 'Update' }}
                                </button>
                            </div>
                        </div>

                        <!-- Aksi (jika tersedia) -->
                        <div v-if="isClickable(selectedKavling)" class="bg-violet-500/10 border border-violet-500/20 rounded-xl p-4 text-center">
                            <p class="text-violet-300 text-sm mb-2">Booking unit ini dilakukan lewat menu Penjualan</p>
                            <Link :href="route('penjualan.project', project.id)"
                                  class="inline-flex items-center gap-1.5 px-4 py-2 bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-500 hover:to-indigo-500 text-white text-sm font-semibold rounded-xl transition-all shadow-lg shadow-violet-500/25">
                                Buka Penjualan
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3.5 h-3.5"><path fill-rule="evenodd" d="M3 10a.75.75 0 01.75-.75h10.638L10.23 5.29a.75.75 0 111.04-1.08l5.5 5.25a.75.75 0 010 1.08l-5.5 5.25a.75.75 0 11-1.04-1.08l4.158-3.96H3.75A.75.75 0 013 10z" clip-rule="evenodd"/></svg>
                            </Link>
                        </div>
                        <div v-else class="text-center py-3 text-slate-600 text-xs bg-slate-800/30 rounded-xl">
                            Kavling ini tidak tersedia untuk booking
                        </div>

                    </div>
                </div>
            </div>
        </Teleport>

        <!-- ═══ MODAL: TAMBAH KAVLING ════════════════════════════════════════ -->
        <Teleport to="body">
            <div v-if="showAddModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="showAddModal = false"/>
                <div class="relative bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-md shadow-2xl">
                    <div class="flex items-center justify-between p-5 border-b border-slate-800">
                        <h3 class="text-white font-semibold">Tambah Kavling Baru</h3>
                        <button @click="showAddModal = false" class="text-slate-500 hover:text-slate-300">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/></svg>
                        </button>
                    </div>
                    <form @submit.prevent="submitAddKavling" class="p-5 space-y-4">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-slate-400 text-xs mb-1.5">Kluster</label>
                                <input v-model="kavlingForm.kluster" type="text" placeholder="Cluster A" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                            <div>
                                <label class="block text-slate-400 text-xs mb-1.5">Blok</label>
                                <input v-model="kavlingForm.blok" type="text" placeholder="A" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-slate-400 text-xs mb-1.5">Nomor Kavling <span class="text-rose-400">*</span></label>
                                <input v-model="kavlingForm.nomor_kavling" type="text" placeholder="A01" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" :class="{ 'border-rose-500': kavlingForm.errors.nomor_kavling }"/>
                                <p v-if="kavlingForm.errors.nomor_kavling" class="text-rose-400 text-xs mt-1">{{ kavlingForm.errors.nomor_kavling }}</p>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-slate-400 text-xs mb-1.5">Tipe Unit</label>
                                <input v-model="kavlingForm.tipe_unit" type="text" placeholder="36/72" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                            <div>
                                <label class="block text-slate-400 text-xs mb-1.5">Luas Tanah (m²)</label>
                                <input v-model="kavlingForm.luas_tanah" type="number" step="0.01" placeholder="100" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                            <div>
                                <label class="block text-slate-400 text-xs mb-1.5">Luas Bangunan (m²)</label>
                                <input v-model="kavlingForm.luas_bangunan" type="number" step="0.01" placeholder="60" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-slate-400 text-xs mb-1.5">Harga (Rp)</label>
                                <input v-model="kavlingForm.harga" type="number" step="1000000" placeholder="500000000" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-slate-400 text-xs mb-1.5">Keterangan</label>
                                <input v-model="kavlingForm.keterangan" type="text" placeholder="Hook, strategis, dll" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                        </div>
                        <div class="flex justify-end gap-3">
                            <button type="button" @click="showAddModal = false" class="px-4 py-2.5 text-slate-400 text-sm">Batal</button>
                            <button type="submit" :disabled="kavlingForm.processing" class="px-4 py-2.5 bg-violet-600 hover:bg-violet-500 text-white text-sm font-medium rounded-lg transition-colors">
                                {{ kavlingForm.processing ? 'Menyimpan...' : 'Tambah Kavling' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <!-- ═══ MODAL: EDIT KAVLING (info umum saja) ═══════════════════════════ -->
        <Teleport to="body">
            <div v-if="showEditModal && selectedKavling" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="showEditModal = false"/>
                <div class="relative bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-md shadow-2xl">
                    <div class="flex items-center justify-between p-5 border-b border-slate-800">
                        <div>
                            <h3 class="text-white font-semibold">Edit Kavling {{ selectedKavling.nomor_lengkap }}</h3>
                            <p class="text-slate-500 text-xs mt-0.5">Status jual & konsumen tidak diubah dari sini</p>
                        </div>
                        <button @click="showEditModal = false" class="text-slate-500 hover:text-slate-300">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/></svg>
                        </button>
                    </div>
                    <form @submit.prevent="submitEditKavling" class="p-5 space-y-4">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-slate-400 text-xs mb-1.5">Kluster</label>
                                <input v-model="kavlingEditForm.kluster" type="text" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                            <div>
                                <label class="block text-slate-400 text-xs mb-1.5">Blok</label>
                                <input v-model="kavlingEditForm.blok" type="text" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-slate-400 text-xs mb-1.5">Nomor Kavling <span class="text-rose-400">*</span></label>
                                <input v-model="kavlingEditForm.nomor_kavling" type="text" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" :class="{ 'border-rose-500': kavlingEditForm.errors.nomor_kavling }"/>
                                <p v-if="kavlingEditForm.errors.nomor_kavling" class="text-rose-400 text-xs mt-1">{{ kavlingEditForm.errors.nomor_kavling }}</p>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-slate-400 text-xs mb-1.5">Tipe Unit</label>
                                <input v-model="kavlingEditForm.tipe_unit" type="text" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                            <div>
                                <label class="block text-slate-400 text-xs mb-1.5">Luas Tanah (m²)</label>
                                <input v-model="kavlingEditForm.luas_tanah" type="number" step="0.01" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                            <div>
                                <label class="block text-slate-400 text-xs mb-1.5">Luas Bangunan (m²)</label>
                                <input v-model="kavlingEditForm.luas_bangunan" type="number" step="0.01" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-slate-400 text-xs mb-1.5">Harga (Rp)</label>
                                <input v-model="kavlingEditForm.harga" type="number" step="1000000" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-slate-400 text-xs mb-1.5">Keterangan</label>
                                <input v-model="kavlingEditForm.keterangan" type="text" placeholder="Hook, strategis, dll" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-slate-400 text-xs mb-1.5">Catatan</label>
                                <textarea v-model="kavlingEditForm.catatan" rows="2" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500 resize-none"/>
                            </div>
                        </div>
                        <div class="flex justify-end gap-3">
                            <button type="button" @click="showEditModal = false" class="px-4 py-2.5 text-slate-400 text-sm">Batal</button>
                            <button type="submit" :disabled="kavlingEditForm.processing" class="px-4 py-2.5 bg-violet-600 hover:bg-violet-500 text-white text-sm font-medium rounded-lg transition-colors">
                                {{ kavlingEditForm.processing ? 'Menyimpan...' : 'Simpan Perubahan' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <!-- ── MODAL: Upload / Ganti Siteplan ────────────────────────────── -->
        <Teleport to="body">
            <div v-if="showUploadSiteplan" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="showUploadSiteplan = false" />
                <div class="relative bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-md shadow-2xl">
                    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-800">
                        <div>
                            <h3 class="text-white font-semibold">{{ project.siteplan_image ? 'Ganti Siteplan' : 'Upload Siteplan' }}</h3>
                            <p class="text-slate-400 text-xs mt-0.5">Format PNG/JPG (klik posisi manual) atau SVG (ID per-unit sudah disiapkan)</p>
                        </div>
                        <button @click="showUploadSiteplan = false" class="text-slate-500 hover:text-slate-300">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/></svg>
                        </button>
                    </div>
                    <div class="px-6 py-5 space-y-4">
                        <div v-if="siteplanPreview || project.siteplan_image" class="rounded-xl overflow-hidden border border-slate-700 bg-slate-800">
                            <img :src="siteplanPreview || project.siteplan_image" class="w-full max-h-48 object-contain" alt="Preview Siteplan" />
                        </div>
                        <div>
                            <label class="block text-slate-400 text-xs font-medium mb-1.5">Pilih File</label>
                            <input type="file" accept=".png,.jpg,.jpeg,.svg" @change="onSiteplanFileChange"
                                class="block w-full text-xs text-slate-400 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:bg-slate-700 file:text-slate-300 hover:file:bg-slate-600 cursor-pointer" />
                            <p v-if="siteplanUploadForm.errors.siteplan_image" class="text-rose-400 text-xs mt-1">{{ siteplanUploadForm.errors.siteplan_image }}</p>
                        </div>
                    </div>
                    <div class="px-6 pb-5 flex gap-3">
                        <button @click="showUploadSiteplan = false" class="flex-1 py-2.5 text-slate-400 border border-slate-700 rounded-lg text-sm transition-colors hover:text-slate-200">Batal</button>
                        <button @click="submitUploadSiteplan" :disabled="siteplanUploadForm.processing || !siteplanUploadForm.siteplan_image"
                            class="flex-1 py-2.5 bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-500 hover:to-indigo-500 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-all">
                            {{ siteplanUploadForm.processing ? 'Mengupload...' : '📤 Upload' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- ── MODAL: Import Excel Kavling ───────────────────────────────── -->
        <Teleport to="body">
            <div v-if="showImport" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="showImport = false" />
                <div class="relative bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-md shadow-2xl">
                    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-800">
                        <div>
                            <h3 class="text-white font-semibold">Import Data Kavling</h3>
                            <p class="text-slate-400 text-xs mt-0.5">Upload file Excel (.xlsx) sesuai format template</p>
                        </div>
                        <button @click="showImport = false" class="text-slate-500 hover:text-slate-300">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/></svg>
                        </button>
                    </div>
                    <div class="px-6 py-5 space-y-4">
                        <a :href="route('projects.kavling-template', project.id)"
                            class="flex items-center justify-center gap-2 w-full py-2.5 bg-violet-600/15 hover:bg-violet-600/25 text-violet-300 text-sm font-medium rounded-lg transition-colors border border-violet-500/30">
                            📄 Download Template Excel
                        </a>
                        <!-- Format info -->
                        <div class="bg-slate-800 rounded-xl p-4 text-xs space-y-1.5 text-slate-400">
                            <div class="text-slate-300 font-medium mb-2">Format kolom Excel yang diperlukan:</div>
                            <div class="grid grid-cols-2 gap-1">
                                <span class="font-mono bg-slate-700 px-1.5 py-0.5 rounded">nomor_kavling (wajib)</span>
                                <span class="font-mono bg-slate-700 px-1.5 py-0.5 rounded">kluster (opsional)</span>
                                <span class="font-mono bg-slate-700 px-1.5 py-0.5 rounded">blok (opsional)</span>
                                <span class="font-mono bg-slate-700 px-1.5 py-0.5 rounded">tipe_unit (opsional)</span>
                                <span class="font-mono bg-slate-700 px-1.5 py-0.5 rounded">luas_tanah (opsional)</span>
                                <span class="font-mono bg-slate-700 px-1.5 py-0.5 rounded">luas_bangunan (opsional)</span>
                                <span class="font-mono bg-slate-700 px-1.5 py-0.5 rounded">harga (opsional)</span>
                                <span class="font-mono bg-slate-700 px-1.5 py-0.5 rounded">status (opsional)</span>
                                <span class="font-mono bg-slate-700 px-1.5 py-0.5 rounded">status_bangun (opsional)</span>
                                <span class="font-mono bg-slate-700 px-1.5 py-0.5 rounded">keterangan (opsional)</span>
                            </div>
                            <p class="pt-1 text-slate-500">status: <span class="font-mono">available</span> / <span class="font-mono">not_for_sale</span> (default available) · status_bangun: <span class="font-mono">not_started</span> / <span class="font-mono">foundation</span> / <span class="font-mono">structure</span> / <span class="font-mono">roofing</span> / <span class="font-mono">finishing</span> / <span class="font-mono">handover_ready</span> (default not_started, isi kalau proyek sudah berjalan)</p>
                        </div>
                        <div>
                            <label class="block text-slate-400 text-xs font-medium mb-1.5">Pilih File Excel</label>
                            <input type="file" accept=".xlsx,.xls" @change="onImportFile"
                                class="block w-full text-xs text-slate-400 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:bg-slate-700 file:text-slate-300 hover:file:bg-slate-600 cursor-pointer" />
                            <p v-if="importForm.errors.file" class="text-rose-400 text-xs mt-1">{{ importForm.errors.file }}</p>
                        </div>
                    </div>
                    <div class="px-6 pb-5 flex gap-3">
                        <button @click="showImport = false" class="flex-1 py-2.5 text-slate-400 border border-slate-700 rounded-lg text-sm transition-colors hover:text-slate-200">Batal</button>
                        <button @click="submitImport" :disabled="importForm.processing || !importForm.file"
                            class="flex-1 py-2.5 bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-all">
                            {{ importForm.processing ? 'Mengimpor...' : '📥 Import Sekarang' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <CsvImportModal
            :show="showCsvImport"
            :project-id="project.id"
            @close="showCsvImport = false"
            @imported="onCsvImported"
        />
    </AuthenticatedLayout>
</template>
