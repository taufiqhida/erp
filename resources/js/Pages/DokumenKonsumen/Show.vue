<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    transaksi:  Object,   // kavling_konsumen record
    konsumen:   Object,
    kavling:    Object,
    dokumens:   Array,
    bast:       Object,
    bastChecklistItems: Object,
});

// Status config
const statusConfig = {
    belum_ada:  { label: 'Belum Ada',  icon: '❌', cls: 'bg-slate-700 text-slate-400', badgeCls: 'bg-slate-700/50 text-slate-400 ring-1 ring-slate-600' },
    sudah_ada:  { label: 'Sudah Ada',  icon: '✅', cls: 'bg-emerald-500/20 text-emerald-400', badgeCls: 'bg-emerald-500/15 text-emerald-400 ring-1 ring-emerald-500/30' },
    perlu_revisi: { label: 'Revisi',   icon: '⚠️', cls: 'bg-amber-500/20 text-amber-400', badgeCls: 'bg-amber-500/15 text-amber-400 ring-1 ring-amber-500/30' },
    ditolak:    { label: 'Ditolak',    icon: '🚫', cls: 'bg-rose-500/20 text-rose-400', badgeCls: 'bg-rose-500/15 text-rose-400 ring-1 ring-rose-500/30' },
};

// Progress
const progress = computed(() => {
    if (!props.dokumens?.length) return 0;
    const done = props.dokumens.filter(d => d.status === 'sudah_ada').length;
    return Math.round((done / props.dokumens.length) * 100);
});
const wajibTotal = computed(() => props.dokumens?.filter(d => d.sifat === 'wajib').length ?? 0);
const wajibDone  = computed(() => props.dokumens?.filter(d => d.sifat === 'wajib' && d.status === 'sudah_ada').length ?? 0);

const sifatBadgeCls = {
    wajib:       'bg-rose-500/10 text-rose-400 ring-1 ring-rose-500/20',
    kondisional: 'bg-amber-500/10 text-amber-400 ring-1 ring-amber-500/20',
    opsional:    'bg-slate-700/50 text-slate-400 ring-1 ring-slate-600',
};

// Update status form
const updateForm = useForm({ status: '', catatan: '' });
const updating   = ref(null);

const updateStatus = (dok, status) => {
    let catatan = '';
    if (['perlu_revisi', 'ditolak'].includes(status)) {
        catatan = window.prompt(`Catatan untuk status "${status === 'perlu_revisi' ? 'Perlu Revisi' : 'Ditolak'}" (opsional):`, dok.catatan_revisi ?? '') ?? '';
    }
    updating.value = dok.id;
    updateForm.status = status;
    updateForm.catatan = catatan;
    updateForm.patch(route('dokumen.update-status', dok.id), {
        onFinish: () => { updating.value = null; }
    });
};

const formatRp = (v) => v ? 'Rp ' + Number(v).toLocaleString('id-ID') : '-';

// Upload file dokumen (memicu status otomatis jadi "Sudah Ada" di server)
const uploadForm = useForm({ file: null });
const uploadingFile = ref(null);
const onDokumenFileChange = (e, dok) => {
    const file = e.target.files[0];
    if (!file) return;
    uploadForm.file = file;
    uploadingFile.value = dok.id;
    uploadForm.post(route('dokumen.upload', dok.id), {
        forceFormData: true,
        onFinish: () => { uploadingFile.value = null; uploadForm.reset(); },
    });
};

// ── Pipeline KPR ──────────────────────────────────────────────────────
const PIPELINE_STAGES = [
    { key: 'booking',      label: 'Booking' },
    { key: 'pemberkasan',  label: 'Pemberkasan' },
    { key: 'proses_bank',  label: 'Proses Bank/SLIK' },
    { key: 'sp3k',         label: 'SP3K' },
    { key: 'rencana_akad', label: 'Rencana Akad' },
    { key: 'akad',         label: 'Akad' },
    { key: 'bast',         label: 'BAST' },
];

const currentStageIndex = computed(() => PIPELINE_STAGES.findIndex(s => s.key === props.transaksi.status_penjualan));
const isBatal = computed(() => props.transaksi.status_penjualan === 'batal');

// "Lanjutkan" generik hanya dipakai untuk booking→pemberkasan dan
// rencana_akad→akad. Transisi proses_bank→sp3k dan sp3k→rencana_akad
// punya kartu keputusan khusus masing-masing (lihat bankForm/sp3kForm).
const nextStage = computed(() => {
    if (isBatal.value) return null;
    const idx = currentStageIndex.value;
    if (idx === 0) return PIPELINE_STAGES[1]; // booking -> pemberkasan
    if (idx === 1) return PIPELINE_STAGES[2]; // pemberkasan -> proses_bank
    if (idx === 4) return PIPELINE_STAGES[5]; // rencana_akad -> akad
    return null;
});

// Menuju proses_bank digerbangi kelengkapan dokumen wajib
const advanceBlocked = computed(() => nextStage.value?.key === 'proses_bank' && !props.transaksi.dokumen_wajib_lengkap);

const sp3kBadge = {
    safe:     { label: 'Berlaku',  cls: 'bg-emerald-500/15 text-emerald-400' },
    warning:  { label: '≤30 hari', cls: 'bg-amber-500/15 text-amber-400' },
    critical: { label: '≤14 hari', cls: 'bg-orange-500/15 text-orange-400' },
    expired:  { label: 'Expired',  cls: 'bg-rose-500/15 text-rose-400' },
};

const showAdvanceModal = ref(false);
const advanceForm = useForm({
    status_penjualan: '',
    catatan: '',
});

const openAdvance = () => {
    if (!nextStage.value || advanceBlocked.value) return;
    advanceForm.reset();
    advanceForm.status_penjualan = nextStage.value.key;
    showAdvanceModal.value = true;
};

const submitAdvance = () => {
    advanceForm.patch(route('bookings.update-status', props.transaksi.id), {
        onSuccess: () => { showAdvanceModal.value = false; }
    });
};

// ── Keputusan Proses Bank / SLIK ────────────────────────────────────────
const bankForm = useForm({
    status_bank: props.transaksi.status_bank ?? 'diajukan',
    tanggal_keputusan_bank: props.transaksi.tanggal_keputusan_bank ?? '',
    catatan_bank: props.transaksi.catatan_bank ?? '',
});

const submitBankDecision = () => {
    bankForm.patch(route('bookings.bank-decision', props.transaksi.id), { preserveScroll: true });
};

// ── Keputusan SP3K ───────────────────────────────────────────────────────
const sp3kForm = useForm({
    tanggal_sp3k: props.transaksi.tanggal_sp3k ?? '',
    tanggal_expired_sp3k: props.transaksi.tanggal_expired_sp3k ?? '',
    status_sp3k: props.transaksi.status_sp3k ?? 'approved',
    tanggal_disetujui_sp3k: props.transaksi.tanggal_disetujui_sp3k ?? '',
    catatan_sp3k: props.transaksi.catatan_sp3k ?? '',
    plafon_baru: props.transaksi.plafon_kpr ?? '',
    rekening_kpr_dibuka: props.transaksi.rekening_kpr_dibuka ?? false,
    biaya_akad_lunas: props.transaksi.biaya_akad_lunas ?? false,
});

const submitSp3kDecision = () => {
    sp3kForm.patch(route('bookings.sp3k-decision', props.transaksi.id), { preserveScroll: true });
};

// ── Revert / batalkan (dipakai dari kartu Proses Bank & SP3K saat ditolak) ─
const revertToPemberkasan = () => {
    if (!window.confirm('Kembalikan transaksi ke tahap Pemberkasan untuk revisi berkas?')) return;
    router.patch(route('bookings.revert-pemberkasan', props.transaksi.id), {}, { preserveScroll: true });
};

const batalkanTransaksi = () => {
    if (!window.confirm('Batalkan transaksi ini? Status kavling akan kembali tersedia.')) return;
    router.patch(route('bookings.update-status', props.transaksi.id), { status_penjualan: 'batal' }, { preserveScroll: true });
};

// ── BAST ──────────────────────────────────────────────────────────────
// Checklist BAST cuma relevan begitu pipeline sampai tahap Akad/BAST.
const showBast = computed(() => currentStageIndex.value >= 5); // 5 = index 'akad'

const bastForm = useForm({
    tanggal_bast: props.bast?.tanggal_bast ?? '',
    penerima: props.bast?.penerima ?? '',
    catatan: props.bast?.catatan ?? '',
    status_ttd: props.bast?.status_ttd ?? 'belum_ttd',
    checklist: props.bast?.checklist ?? Object.fromEntries(Object.keys(props.bastChecklistItems ?? {}).map(k => [k, false])),
});

const submitBast = () => {
    bastForm.patch(route('bast.update', props.transaksi.id), { preserveScroll: true });
};

const bastUploadForm = useForm({ file: null });
const onBastFileChange = (e) => {
    bastUploadForm.file = e.target.files[0];
    if (bastUploadForm.file) {
        bastUploadForm.post(route('bast.upload', props.transaksi.id), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => bastUploadForm.reset(),
        });
    }
};
</script>

<template>
    <Head :title="`Dokumen – ${konsumen.nama}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2 text-slate-400 text-sm">
                <Link :href="route('konsumens.index')" class="hover:text-slate-200 transition-colors">Konsumen</Link>
                <span>/</span>
                <Link :href="route('konsumens.show', konsumen.id)" class="hover:text-slate-200 transition-colors">{{ konsumen.nama }}</Link>
                <span>/</span>
                <span class="text-slate-200 font-medium">Dokumen</span>
            </div>
        </template>

        <div class="p-6 space-y-5">
            <!-- Header info -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h1 class="text-white font-bold text-xl">{{ konsumen.nama }}</h1>
                        <div class="flex flex-wrap items-center gap-3 mt-1.5 text-sm text-slate-400">
                            <span class="font-mono bg-slate-800 px-2 py-0.5 rounded text-xs">{{ kavling.nomor_lengkap }}</span>
                            <span>{{ kavling.project_nama }}</span>
                            <span>{{ transaksi.cara_bayar_label }}</span>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-slate-400 text-xs">Harga Deal</div>
                        <div class="text-white font-bold text-lg">{{ formatRp(transaksi.harga_deal) }}</div>
                    </div>
                </div>

                <!-- Progress bar -->
                <div class="mt-5 space-y-2">
                    <div class="flex justify-between items-center text-xs text-slate-400">
                        <span>Kelengkapan Berkas</span>
                        <span>
                            <span class="text-violet-400 font-semibold">{{ wajibDone }}/{{ wajibTotal }}</span>
                            <span class="text-slate-600 ml-1">dokumen wajib</span>
                        </span>
                    </div>
                    <div class="h-2.5 bg-slate-800 rounded-full overflow-hidden">
                        <div class="h-full rounded-full transition-all duration-700"
                            :class="progress === 100 ? 'bg-gradient-to-r from-emerald-500 to-teal-500' : 'bg-gradient-to-r from-violet-500 to-indigo-500'"
                            :style="{ width: progress + '%' }" />
                    </div>
                    <div class="text-right text-xs font-semibold"
                        :class="progress === 100 ? 'text-emerald-400' : 'text-violet-400'">
                        {{ progress }}% lengkap
                    </div>
                </div>
            </div>

            <!-- Pipeline KPR -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-slate-300 font-medium text-sm">Pipeline KPR</h2>
                    <span v-if="transaksi.sp3k_expiry_status" :class="sp3kBadge[transaksi.sp3k_expiry_status]?.cls"
                        class="text-xs px-2 py-0.5 rounded-full font-medium">
                        SP3K {{ sp3kBadge[transaksi.sp3k_expiry_status]?.label }}
                    </span>
                </div>

                <div v-if="isBatal" class="text-center py-4 text-rose-400 text-sm bg-rose-500/10 rounded-xl">
                    Transaksi ini berstatus Batal
                </div>

                <div v-else class="flex items-center overflow-x-auto pb-1">
                    <template v-for="(stage, idx) in PIPELINE_STAGES" :key="stage.key">
                        <div class="flex flex-col items-center flex-shrink-0" style="min-width: 90px;">
                            <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold"
                                :class="idx < currentStageIndex ? 'bg-emerald-500 text-white'
                                    : idx === currentStageIndex ? 'bg-violet-600 text-white ring-4 ring-violet-500/20'
                                    : 'bg-slate-800 text-slate-600'">
                                <span v-if="idx < currentStageIndex">✓</span>
                                <span v-else>{{ idx + 1 }}</span>
                            </div>
                            <span class="text-xs mt-1.5 text-center"
                                :class="idx <= currentStageIndex ? 'text-slate-300' : 'text-slate-600'">{{ stage.label }}</span>
                        </div>
                        <div v-if="idx < PIPELINE_STAGES.length - 1" class="flex-1 h-0.5 min-w-[16px]"
                            :class="idx < currentStageIndex ? 'bg-emerald-500' : 'bg-slate-800'" />
                    </template>
                </div>

                <div v-if="!isBatal" class="mt-4 pt-4 border-t border-slate-800 flex flex-wrap items-center gap-x-6 gap-y-1 text-xs text-slate-500">
                    <span v-if="transaksi.tanggal_sp3k">SP3K: {{ transaksi.tanggal_sp3k }}</span>
                    <span v-if="transaksi.tanggal_expired_sp3k">Expired SP3K: {{ transaksi.tanggal_expired_sp3k }}</span>
                    <span v-if="transaksi.tanggal_rencana_akad">Rencana Akad: {{ transaksi.tanggal_rencana_akad }}</span>
                    <span v-if="transaksi.tanggal_akad">Akad: {{ transaksi.tanggal_akad }}</span>
                    <div v-if="transaksi.can_update_status && nextStage" class="ml-auto flex items-center gap-2">
                        <span v-if="advanceBlocked" class="text-amber-400 text-xs">Lengkapi dokumen wajib dulu</span>
                        <button @click="openAdvance" :disabled="advanceBlocked"
                            class="px-3 py-1.5 bg-violet-600 hover:bg-violet-500 disabled:opacity-40 disabled:cursor-not-allowed text-white text-xs font-medium rounded-lg transition-colors">
                            Lanjutkan ke {{ nextStage.label }} →
                        </button>
                    </div>
                </div>
            </div>

            <!-- Keputusan Proses Bank / SLIK -->
            <div v-if="transaksi.status_penjualan === 'proses_bank'" class="bg-slate-900 border border-slate-800 rounded-2xl p-5">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-slate-300 font-medium text-sm">Keputusan Proses Bank / SLIK</h2>
                    <span v-if="transaksi.tanggal_pengajuan_bank" class="text-slate-500 text-xs">Diajukan {{ transaksi.tanggal_pengajuan_bank }}</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
                    <div>
                        <label class="block text-slate-400 text-xs font-medium mb-1.5">Status Keputusan</label>
                        <select v-model="bankForm.status_bank" :disabled="!transaksi.can_update_status"
                            class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500 disabled:opacity-60">
                            <option value="diajukan">Diajukan</option>
                            <option value="disetujui">Disetujui</option>
                            <option value="ditolak">Ditolak</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-slate-400 text-xs font-medium mb-1.5">Tanggal Keputusan</label>
                        <input v-model="bankForm.tanggal_keputusan_bank" type="date" :disabled="!transaksi.can_update_status"
                            class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500 disabled:opacity-60" />
                    </div>
                </div>

                <div class="mb-4">
                    <label class="block text-slate-400 text-xs font-medium mb-1.5">Catatan</label>
                    <textarea v-model="bankForm.catatan_bank" rows="2" :disabled="!transaksi.can_update_status"
                        class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm resize-none focus:outline-none focus:ring-1 focus:ring-violet-500 disabled:opacity-60" />
                </div>

                <p v-if="bankForm.status_bank === 'disetujui'" class="text-emerald-400 text-xs mb-4">
                    ✓ Menyimpan sebagai Disetujui akan otomatis melanjutkan transaksi ke tahap SP3K.
                </p>

                <div class="flex flex-wrap items-center gap-3">
                    <button v-if="transaksi.can_update_status" @click="submitBankDecision" :disabled="bankForm.processing"
                        class="px-4 py-2 bg-violet-600 hover:bg-violet-500 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-colors">
                        {{ bankForm.processing ? 'Menyimpan...' : 'Simpan Keputusan' }}
                    </button>
                    <template v-if="transaksi.can_update_status && transaksi.status_bank === 'ditolak'">
                        <button @click="revertToPemberkasan"
                            class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-200 text-sm font-medium rounded-lg transition-colors">
                            ↩ Kembali ke Pemberkasan
                        </button>
                        <button @click="batalkanTransaksi"
                            class="px-4 py-2 bg-rose-600/20 hover:bg-rose-600/30 text-rose-400 text-sm font-medium rounded-lg transition-colors">
                            ✕ Batalkan Transaksi
                        </button>
                    </template>
                </div>
            </div>

            <!-- Keputusan SP3K -->
            <div v-if="transaksi.status_penjualan === 'sp3k'" class="bg-slate-900 border border-slate-800 rounded-2xl p-5">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-slate-300 font-medium text-sm">Keputusan SP3K</h2>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
                    <div>
                        <label class="block text-slate-400 text-xs font-medium mb-1.5">Tanggal Terbit SP3K</label>
                        <input v-model="sp3kForm.tanggal_sp3k" type="date" :disabled="!transaksi.can_update_status"
                            class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500 disabled:opacity-60" />
                    </div>
                    <div>
                        <label class="block text-slate-400 text-xs font-medium mb-1.5">Tanggal Expired SP3K</label>
                        <input v-model="sp3kForm.tanggal_expired_sp3k" type="date" :disabled="!transaksi.can_update_status"
                            class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500 disabled:opacity-60" />
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
                    <div>
                        <label class="block text-slate-400 text-xs font-medium mb-1.5">Keputusan</label>
                        <select v-model="sp3kForm.status_sp3k" :disabled="!transaksi.can_update_status"
                            class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500 disabled:opacity-60">
                            <option value="approved">Approved</option>
                            <option value="turun_plafon">Turun Plafon</option>
                            <option value="ditolak">Ditolak</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-slate-400 text-xs font-medium mb-1.5">Tanggal Disetujui</label>
                        <input v-model="sp3kForm.tanggal_disetujui_sp3k" type="date" :disabled="!transaksi.can_update_status"
                            class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500 disabled:opacity-60" />
                    </div>
                </div>

                <div v-if="sp3kForm.status_sp3k === 'turun_plafon'" class="mb-4">
                    <label class="block text-slate-400 text-xs font-medium mb-1.5">Plafon Baru</label>
                    <input v-model="sp3kForm.plafon_baru" type="number" min="0" :disabled="!transaksi.can_update_status"
                        placeholder="cth. 250000000"
                        class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500 disabled:opacity-60" />
                    <p v-if="transaksi.plafon_kpr" class="text-slate-600 text-xs mt-1">Plafon sebelumnya: {{ formatRp(transaksi.plafon_kpr) }}</p>
                </div>

                <div class="mb-4">
                    <label class="block text-slate-400 text-xs font-medium mb-1.5">Catatan</label>
                    <textarea v-model="sp3kForm.catatan_sp3k" rows="2" :disabled="!transaksi.can_update_status"
                        class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm resize-none focus:outline-none focus:ring-1 focus:ring-violet-500 disabled:opacity-60" />
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-2">
                    <label class="flex items-center gap-2.5 px-3 py-2.5 bg-slate-800/60 rounded-lg cursor-pointer hover:bg-slate-800 transition-colors">
                        <input type="checkbox" v-model="sp3kForm.rekening_kpr_dibuka" :disabled="!transaksi.can_update_status"
                            class="w-4 h-4 accent-emerald-500 rounded" />
                        <span class="text-slate-300 text-sm">Konsumen sudah buka rekening KPR</span>
                    </label>
                    <label class="flex items-center gap-2.5 px-3 py-2.5 bg-slate-800/60 rounded-lg cursor-pointer hover:bg-slate-800 transition-colors">
                        <input type="checkbox" v-model="sp3kForm.biaya_akad_lunas" :disabled="!transaksi.can_update_status"
                            class="w-4 h-4 accent-emerald-500 rounded" />
                        <span class="text-slate-300 text-sm">Biaya akad lunas</span>
                    </label>
                </div>
                <p v-if="sp3kForm.status_sp3k !== 'ditolak' && !(sp3kForm.rekening_kpr_dibuka && sp3kForm.biaya_akad_lunas)"
                    class="text-amber-400 text-xs mb-4">
                    ⚠ Kedua checklist harus dicentang dulu sebelum bisa lanjut ke Rencana Akad.
                </p>
                <p v-else-if="sp3kForm.status_sp3k !== 'ditolak'" class="text-emerald-400 text-xs mb-4">
                    ✓ Menyimpan akan otomatis melanjutkan transaksi ke tahap Rencana Akad.
                </p>

                <div class="flex flex-wrap items-center gap-3">
                    <button v-if="transaksi.can_update_status" @click="submitSp3kDecision" :disabled="sp3kForm.processing"
                        class="px-4 py-2 bg-violet-600 hover:bg-violet-500 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-colors">
                        {{ sp3kForm.processing ? 'Menyimpan...' : 'Simpan Keputusan' }}
                    </button>
                    <template v-if="transaksi.can_update_status && transaksi.status_sp3k === 'ditolak'">
                        <button @click="revertToPemberkasan"
                            class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-200 text-sm font-medium rounded-lg transition-colors">
                            ↩ Kembali ke Pemberkasan
                        </button>
                        <button @click="batalkanTransaksi"
                            class="px-4 py-2 bg-rose-600/20 hover:bg-rose-600/30 text-rose-400 text-sm font-medium rounded-lg transition-colors">
                            ✕ Batalkan Transaksi
                        </button>
                    </template>
                </div>
            </div>

            <!-- BAST -->
            <div v-if="showBast" class="bg-slate-900 border border-slate-800 rounded-2xl p-5">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-slate-300 font-medium text-sm">Berita Acara Serah Terima (BAST)</h2>
                    <span v-if="bast?.complete && bast?.status_ttd === 'sudah_ttd'"
                        class="text-xs px-2 py-0.5 rounded-full font-medium bg-emerald-500/15 text-emerald-400">
                        ✅ Selesai
                    </span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
                    <label v-for="(label, key) in bastChecklistItems" :key="key"
                        class="flex items-center gap-2.5 px-3 py-2.5 bg-slate-800/60 rounded-lg cursor-pointer hover:bg-slate-800 transition-colors">
                        <input type="checkbox" v-model="bastForm.checklist[key]"
                            :disabled="!transaksi.can_update_status"
                            class="w-4 h-4 accent-emerald-500 rounded" />
                        <span class="text-slate-300 text-sm">{{ label }}</span>
                    </label>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
                    <div>
                        <label class="block text-slate-400 text-xs font-medium mb-1.5">Tanggal BAST</label>
                        <input v-model="bastForm.tanggal_bast" type="date" :disabled="!transaksi.can_update_status"
                            class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500 disabled:opacity-60" />
                    </div>
                    <div>
                        <label class="block text-slate-400 text-xs font-medium mb-1.5">Penerima</label>
                        <input v-model="bastForm.penerima" type="text" :disabled="!transaksi.can_update_status" placeholder="Nama penerima unit"
                            class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500 disabled:opacity-60" />
                    </div>
                </div>

                <div class="mb-4">
                    <label class="block text-slate-400 text-xs font-medium mb-1.5">Status Tanda Tangan</label>
                    <select v-model="bastForm.status_ttd" :disabled="!transaksi.can_update_status"
                        class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500 disabled:opacity-60">
                        <option value="belum_ttd">Belum Tanda Tangan</option>
                        <option value="sudah_ttd">Sudah Tanda Tangan</option>
                    </select>
                </div>

                <div class="mb-4">
                    <label class="block text-slate-400 text-xs font-medium mb-1.5">Catatan</label>
                    <textarea v-model="bastForm.catatan" rows="2" :disabled="!transaksi.can_update_status"
                        class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm resize-none focus:outline-none focus:ring-1 focus:ring-violet-500 disabled:opacity-60" />
                </div>

                <div class="mb-4">
                    <label class="block text-slate-400 text-xs font-medium mb-1.5">Dokumen BAST (scan/foto bertanda tangan)</label>
                    <a v-if="bast?.dokumen_path" :href="bast.dokumen_path" target="_blank"
                        class="text-violet-400 hover:text-violet-300 text-xs underline block mb-2">📎 Lihat dokumen terupload</a>
                    <input v-if="transaksi.can_update_status" type="file" accept=".pdf,.jpg,.jpeg,.png" @change="onBastFileChange"
                        class="block w-full text-xs text-slate-400 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:bg-slate-700 file:text-slate-300 hover:file:bg-slate-600 cursor-pointer" />
                </div>

                <button v-if="transaksi.can_update_status" @click="submitBast" :disabled="bastForm.processing"
                    class="px-4 py-2 bg-violet-600 hover:bg-violet-500 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-colors">
                    {{ bastForm.processing ? 'Menyimpan...' : 'Simpan BAST' }}
                </button>
            </div>

            <!-- Dokumen List -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="px-5 py-3.5 border-b border-slate-800 flex items-center justify-between">
                    <h2 class="text-slate-300 font-medium text-sm">Daftar Dokumen</h2>
                    <span class="text-slate-500 text-xs">{{ dokumens.length }} dokumen</span>
                </div>

                <div v-if="!dokumens.length" class="py-12 text-center text-slate-600 text-sm">
                    Belum ada template dokumen. Atur di Pengaturan → Template Dokumen.
                </div>

                <div class="divide-y divide-slate-800/60">
                    <div v-for="dok in dokumens" :key="dok.id"
                        class="flex items-center gap-4 px-5 py-4 hover:bg-slate-800/20 transition-colors">
                        <!-- Status icon -->
                        <div class="w-9 h-9 rounded-full flex items-center justify-center text-lg flex-shrink-0"
                            :class="statusConfig[dok.status]?.cls ?? 'bg-slate-700'">
                            {{ statusConfig[dok.status]?.icon ?? '❓' }}
                        </div>

                        <!-- Info -->
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="text-slate-200 text-sm font-medium">{{ dok.nama_dokumen }}</span>
                                <span :class="sifatBadgeCls[dok.sifat]"
                                    class="px-1.5 py-0.5 text-xs rounded">{{ dok.sifat_label }}</span>
                            </div>
                            <div class="mt-1 flex flex-wrap items-center gap-2">
                                <span :class="statusConfig[dok.status]?.badgeCls ?? 'bg-slate-700 text-slate-400'"
                                    class="text-xs px-2 py-0.5 rounded-full font-medium">
                                    {{ statusConfig[dok.status]?.label ?? dok.status }}
                                </span>
                                <span v-if="dok.tanggal_upload" class="text-slate-600 text-xs">Diupload {{ dok.tanggal_upload }}</span>
                                <span v-if="dok.verified_by" class="text-slate-600 text-xs">· Diverifikasi {{ dok.verified_by }}, {{ dok.tanggal_verifikasi }}</span>
                                <a v-if="dok.file_path" :href="dok.file_path" target="_blank" class="text-violet-400 hover:text-violet-300 text-xs underline">📎 Lihat file</a>
                            </div>
                            <p v-if="['perlu_revisi','ditolak'].includes(dok.status) && dok.catatan_revisi"
                                class="mt-1.5 text-amber-400 text-xs bg-amber-500/10 border border-amber-500/20 rounded-lg px-2.5 py-1.5">
                                📝 {{ dok.catatan_revisi }}
                            </p>
                        </div>

                        <!-- Upload File -->
                        <label class="w-7 h-7 rounded-full flex items-center justify-center text-sm flex-shrink-0 bg-slate-800 hover:bg-slate-700 cursor-pointer transition-colors" title="Upload file">
                            <span v-if="uploadingFile === dok.id" class="animate-spin text-xs">↻</span>
                            <span v-else>📎</span>
                            <input type="file" accept=".pdf,.jpg,.jpeg,.png" class="hidden" @change="onDokumenFileChange($event, dok)" />
                        </label>

                        <!-- Status Update Buttons -->
                        <div class="flex items-center gap-1.5 flex-shrink-0">
                            <button
                                v-for="(cfg, st) in statusConfig" :key="st"
                                @click="updateStatus(dok, st)"
                                :disabled="dok.status === st || updating === dok.id"
                                :title="cfg.label"
                                :class="[
                                    dok.status === st
                                        ? 'opacity-100 ring-2 ring-offset-1 ring-offset-slate-900 ring-violet-500'
                                        : 'opacity-40 hover:opacity-80',
                                    'w-7 h-7 rounded-full flex items-center justify-center text-sm transition-all disabled:cursor-not-allowed',
                                    cfg.cls
                                ]">
                                <span v-if="updating === dok.id && dok.status !== st" class="animate-spin text-xs">↻</span>
                                <span v-else>{{ cfg.icon }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Legend -->
            <div class="flex flex-wrap gap-3 text-xs text-slate-500">
                <span v-for="(cfg, st) in statusConfig" :key="st" class="flex items-center gap-1.5">
                    <span class="text-sm">{{ cfg.icon }}</span> {{ cfg.label }}
                </span>
                <span class="ml-auto text-slate-600">Klik icon untuk update status dokumen</span>
            </div>
        </div>

        <!-- Modal: Lanjutkan Tahap Pipeline -->
        <Teleport to="body">
            <div v-if="showAdvanceModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="showAdvanceModal = false" />
                <div class="relative bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-md shadow-2xl">
                    <div class="flex items-center justify-between p-5 border-b border-slate-800">
                        <h3 class="text-white font-semibold">Lanjutkan ke {{ nextStage?.label }}</h3>
                        <button @click="showAdvanceModal = false" class="text-slate-500 hover:text-slate-300">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/></svg>
                        </button>
                    </div>
                    <div class="p-5 space-y-4">
                        <div>
                            <label class="block text-slate-400 text-xs font-medium mb-1.5">Catatan (opsional)</label>
                            <textarea v-model="advanceForm.catatan" rows="2" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm resize-none focus:outline-none focus:ring-1 focus:ring-violet-500" />
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 p-5 border-t border-slate-800">
                        <button @click="showAdvanceModal = false" class="px-4 py-2.5 text-slate-400 hover:text-slate-200 text-sm">Batal</button>
                        <button @click="submitAdvance" :disabled="advanceForm.processing"
                            class="px-5 py-2.5 bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-500 hover:to-indigo-500 disabled:opacity-60 text-white text-sm font-semibold rounded-xl transition-all shadow-lg shadow-violet-500/20">
                            {{ advanceForm.processing ? 'Menyimpan...' : 'Konfirmasi' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
